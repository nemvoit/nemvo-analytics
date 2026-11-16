#!/usr/bin/env bash
# Upgrade test: installs an older release zip on a throwaway WordPress with statistics in the
# WordPress database and in an external database (the default prefix of that release), plus saved
# passwords, then installs the new zip over it like a plugin update and checks that the first request
# upgrades everything without losing data: tables renamed in both databases, same row counts and
# report numbers, secrets encrypted, cron schedule renamed, settings kept, old and new REST
# namespaces answering, and moving the statistics back still works.
# Usage: tests/upgrade-test.sh dist/nemvo-analytics-1.0.2.zip dist/nemvo-analytics-1.0.3.zip
set -euo pipefail
cd "$(dirname "$0")/.."

old_zip=${1:?usage: $0 old.zip new.zip}
new_zip=${2:?usage: $0 old.zip new.zip}
project=nemvo-analytics-upgrade
port=${UPGRADE_PORT:-8098}
url="http://localhost:$port"
work=$(mktemp -d)
# KEEP=1 leaves the containers running for a look around (remove them with: docker compose -p nemvo-analytics-upgrade down -v).
[ -n "${KEEP:-}" ] || trap 'docker compose -p $project -f "$work/compose.yml" down -v >/dev/null 2>&1; rm -rf "$work"' EXIT

fail=0
expect() { # name, condition result ("1" = pass)
	if [ "$2" = 1 ]; then echo "ok   $1"; else echo "FAIL $1"; fail=1; fi
}

cp "$old_zip" "$work/old.zip"
cp "$new_zip" "$work/new.zip"
cat >"$work/compose.yml" <<YAML
services:
  db:
    image: mariadb:11.4
    environment: { MARIADB_DATABASE: wp, MARIADB_USER: wp, MARIADB_PASSWORD: wp, MARIADB_ROOT_PASSWORD: root }
    healthcheck: { test: ["CMD", "healthcheck.sh", "--connect", "--innodb_initialized"], interval: 3s, retries: 30 }
  statsdb:
    image: mariadb:11.4
    environment: { MARIADB_DATABASE: analytics, MARIADB_USER: analytics, MARIADB_PASSWORD: analytics, MARIADB_ROOT_PASSWORD: root }
    healthcheck: { test: ["CMD", "healthcheck.sh", "--connect", "--innodb_initialized"], interval: 3s, retries: 30 }
  wordpress:
    image: wordpress:php8.3-apache
    depends_on: { db: { condition: service_healthy }, statsdb: { condition: service_healthy } }
    ports: ["$port:80"]
    environment:
      WORDPRESS_DB_HOST: db
      WORDPRESS_DB_USER: wp
      WORDPRESS_DB_PASSWORD: wp
      WORDPRESS_DB_NAME: wp
      WORDPRESS_DEBUG: "1"
      NEMVO_ANALYTICS_STORAGE: local
      NEMVO_ANALYTICS_DB_HOST: statsdb
      NEMVO_ANALYTICS_DB_NAME: analytics
      NEMVO_ANALYTICS_DB_USER: analytics
      NEMVO_ANALYTICS_DB_PASSWORD: analytics
    volumes: ["$work:/zips:ro"]
YAML

echo "== Starting WordPress with $old_zip"
docker compose -p $project -f "$work/compose.yml" up -d >/dev/null 2>&1
dc() { docker compose -p $project -f "$work/compose.yml" exec -T wordpress "$@"; }
sql() { # database service, query
	if [ "$1" = db ]; then set -- "$1" "$2" -uwp -pwp wp; else set -- "$1" "$2" -uanalytics -panalytics analytics; fi
	docker compose -p $project -f "$work/compose.yml" exec -T "$1" mariadb "${@:3}" -N -e "$2" | sort | tr '\n' ' '
}
dc bash -c 'curl -fsSL -o /usr/local/bin/wp https://raw.githubusercontent.com/wp-cli/builds/gh-pages/phar/wp-cli.phar && chmod +x /usr/local/bin/wp'
wp() { dc runuser -u www-data -- wp --path=/var/www/html "$@"; }
for _ in $(seq 1 60); do wp core is-installed >/dev/null 2>&1 && break; wp db check >/dev/null 2>&1 && break; sleep 1; done
wp core install --url="$url" --title=Upgrade --admin_user=admin --admin_password=admin --admin_email=a@example.com --skip-email >/dev/null
wp rewrite structure '/%postname%/' >/dev/null
wp plugin install /zips/old.zip --activate >/dev/null
wp nemvo-analytics seed --days=21 --sessions=60 >/dev/null
# Statistics in both databases, the external one active (as after "Move statistics").
wp nemvo-analytics migrate --to=external --yes >/dev/null
# Passwords saved on the settings page, and the queue on (its cron event uses the old schedule).
wp eval 'update_option( "nemvo_analytics_db", array( "host" => "statsdb", "name" => "analytics", "user" => "analytics", "password" => "db-secret", "prefix" => "nemvo_" ) );
update_option( "nemvo_analytics_services", array_merge( get_option( "nemvo_analytics_services", array() ), array( "queue_enabled" => 1, "queue_host" => "valkey.invalid", "queue_password" => "queue-secret", "cap_url" => "http://cap.invalid", "cap_site_key" => "abc", "cap_secret" => "cap-secret" ) ) );
NemvoAnalytics\Cron::schedule();' >/dev/null

snapshot='use NemvoAnalytics\DB; use NemvoAnalytics\Stats;
if ( method_exists( DB::class, "reset" ) ) { DB::reset(); }
$from = current_datetime()->modify( "-30 days" )->format( "Y-m-d" );
$to   = current_datetime()->format( "Y-m-d" );
$o    = ( new Stats( DB::conn(), $from, $to ) )->overview();
$keep = array_intersect_key( $o, array_flip( array( "visitors", "visits", "pageviews", "bounce_rate", "avg_duration", "conversions" ) ) );
echo wp_json_encode( array( "local" => DB::counts( "local", true ), "external" => DB::counts( "external", true ), "active" => DB::active(), "overview" => $keep ) );'
before=$(wp eval "$snapshot")
echo "     before: $before"
old_tables=$(sql db "SHOW TABLES LIKE 'wp_nemvo_%'")
expect "the old release uses the old table names ($old_tables)" "$(grep -q 'wp_nemvo_sessions' <<<"$old_tables" && echo 1)"

echo "== Updating to $new_zip"
wp plugin install /zips/new.zip --force >/dev/null 2>&1
# The first request after the update: a hit from a page cached before it, to the old namespace.
ua='Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Safari/537.36'
hit() { # namespace, utm marker
	curl -s -o /dev/null -w '%{http_code}' -A "$ua" -H 'Sec-Fetch-Site: same-origin' -H 'Content-Type: text/plain' \
		--data "{\"t\":\"pv\",\"id\":\"$(openssl rand -hex 8)\",\"u\":\"$url/?utm_source=$2\",\"r\":\"\",\"ti\":\"Upgrade\",\"w\":1440,\"tz\":\"Europe/Stockholm\",\"l\":\"sv-SE\",\"b\":0,\"c\":{\"type\":\"home\",\"id\":0}}" "$url/wp-json/$1/v1/e"
}
# The queue host does not exist: hits are written directly, as when Valkey is down.
code_old=$(hit nemvo upgrade-old)
sleep 1
expect "the first request after the update, to the old namespace, is accepted ($code_old)" "$([ "$code_old" = 200 ] && echo 1)"

after=$(wp eval "$snapshot")
echo "     after:  $after"
# The external database is the active one, so it also holds the new hit: one visit with one pageview.
same=$(python3 -c 'import json,sys; b=json.loads(sys.argv[1]); a=json.loads(sys.argv[2]); e=dict(b["external"]); e["sessions"]+=1; e["pageviews"]+=1; print(1 if a["local"]==b["local"] and a["external"]==e else 0)' "$before" "$after")
expect "same rows in both databases, plus the new hit in the active one" "$same"
ov() { python3 -c 'import json,sys; print(json.dumps(json.loads(sys.argv[1])["overview"], sort_keys=True))' "$1"; }
# The hit above is one more visit, but only for people in the period; compare before it was added.
expect "the active database is still the external one" "$(grep -q '"active":"external"' <<<"$after" && echo 1)"
tables=$(sql db "SHOW TABLES LIKE 'wp_nemvo%'")
expect "WordPress database tables renamed ($tables)" "$([ "$tables" = 'wp_nemvo_analytics_events wp_nemvo_analytics_meta wp_nemvo_analytics_pageviews wp_nemvo_analytics_sessions ' ] && echo 1)"
ext=$(sql statsdb 'SHOW TABLES')
expect "external database tables renamed ($ext)" "$([ "$ext" = 'nemvo_analytics_events nemvo_analytics_meta nemvo_analytics_pageviews nemvo_analytics_sessions ' ] && echo 1)"
state=$(wp eval 'global $wpdb;
$raw = $wpdb->get_results( "SELECT option_name, option_value, autoload FROM {$wpdb->options} WHERE option_name IN (\"nemvo_analytics_db\", \"nemvo_analytics_services\")", OBJECT_K );
$db  = NemvoAnalytics\Settings::db_settings(); $svc = NemvoAnalytics\Settings::services();
echo implode( " ", array(
	get_option( "nemvo_analytics_db_version" ),
	false === strpos( $raw["nemvo_analytics_db"]->option_value . $raw["nemvo_analytics_services"]->option_value, "-secret" ) ? "encrypted" : "plain",
	$db["password"] . "," . $svc["queue_password"] . "," . $svc["cap_secret"],
	in_array( $raw["nemvo_analytics_db"]->autoload, array( "no", "off" ), true ) && in_array( $raw["nemvo_analytics_services"]->autoload, array( "no", "off" ), true ) ? "not-autoloaded" : "autoloaded",
	$db["prefix"],
	wp_get_schedule( "nemvo_analytics_queue" ),
	get_option( "nemvo_analytics_settings" )["rdns_lookup"],
) );')
echo "     $state"
expect "upgrade recorded, secrets encrypted and readable, not autoloaded, prefix and cron schedule renamed, reverse DNS setting kept" "$([ "$state" = '2 encrypted db-secret,queue-secret,cap-secret not-autoloaded nemvo_analytics_ nemvo_analytics_minute 1' ] && echo 1)"
ddl=$(wp eval 'add_filter( "query", function ( $q ) { if ( preg_match( "/^\s*(ALTER|CREATE|RENAME)/i", $q ) ) { echo "DDL "; } return $q; } ); NemvoAnalytics\DB::install( "local" ); NemvoAnalytics\DB::install( "external" );')
expect "installing again changes nothing (${ddl:-no DDL})" "$([ -z "$ddl" ] && echo 1)"
code_new=$(hit nemvo-analytics upgrade-new)
expect "the new namespace accepts hits ($code_new)" "$([ "$code_new" = 200 ] && echo 1)"
stored=$(wp eval '$db = NemvoAnalytics\DB::conn(); echo $db->get_var( "SELECT COUNT(*) FROM " . NemvoAnalytics\DB::table( "sessions" ) . " WHERE utm_source = \"upgrade-old\"" );')
expect "the hit to the old namespace was stored ($stored)" "$([ "$stored" = 1 ] && echo 1)"

echo "== Moving the statistics back to the WordPress database"
wp eval '$db = NemvoAnalytics\DB::conn(); $db->query( "DELETE FROM " . NemvoAnalytics\DB::table( "sessions" ) . " WHERE utm_source LIKE \"upgrade-%\"" ); $db->query( "DELETE FROM " . NemvoAnalytics\DB::table( "pageviews" ) . " WHERE title = \"Upgrade\"" );' >/dev/null
wp nemvo-analytics migrate --to=local --replace --yes >/dev/null
back=$(wp eval "$snapshot")
expect "same report numbers as before the update, now from the WordPress database" "$([ "$(ov "$before")" = "$(ov "$back")" ] && grep -q '"active":"local"' <<<"$back" && echo 1)"
echo "     $(ov "$back")"

[ $fail = 0 ] && echo "UPGRADE TEST PASSED" || { echo "UPGRADE TEST FAILED"; exit 1; }
