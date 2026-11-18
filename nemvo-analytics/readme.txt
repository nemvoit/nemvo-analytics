=== Nemvo Analytics ===
Contributors: fpoller
Tags: analytics, statistics, privacy, bot detection, b2b
Requires at least: 6.2
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.0.4
License: MIT
License URI: https://opensource.org/licenses/MIT

Self-hosted, cookieless web analytics with company identification and bot detection. Your data stays on your server.

== Description ==

Nemvo Analytics shows where your visitors come from, what they read, how long they stay, which companies visit and which visits are bots. Everything is stored in your own database and no visitor data is sent to third parties. It sets no cookies, so in most EU interpretations it needs no consent banner.

It is made for sites that want full traffic reports without an external analytics service, with extra insight for B2B sites: which organisations visit, and whether a visit came from a prospect's office, a home connection or a datacenter.

= Reports =

* Visitors, visits, pageviews, views per visit, visit duration, bounce rate, time on page, scroll depth and conversions, compared with the previous period or the same period last year.
* A time-series chart for every metric, by hour, day, week or month. Click a day to zoom in.
* Traffic sources: channels (organic search, social, AI assistants, email, paid, referral…), sources, referrers and UTM campaigns.
* Pages: top pages, entry and exit pages, time on page, and each page's journey (where visitors came from and went next).
* A world map with Europe, Nordics, Americas and Asia-Pacific views, plus countries, regions and cities.
* Companies & networks: organisations visiting from their own network or office line, network types (business, home, mobile, datacenter, education, government) and providers.
* Devices, operating systems, browsers, screen sizes and languages.
* When people visit: a weekday × hour heatmap.
* Goals and events: form submissions, file downloads, outbound links, email and phone clicks, site search terms, 404 pages, and your own events.
* A visit log: every visit with its pages, time on each page, events, organisation and bot classification.
* Realtime: people on the site right now, pageviews per minute and a live activity feed.
* Every chart, map cell and table row is clickable to filter the whole dashboard; filters can be combined and shared as a link.
* CSV export, a dashboard widget, a views column in the posts list and a weekly email report.

= Companies and networks =

Each visit's network is looked up in a free ASN database (DB-IP, downloaded when you click). Organisations with their own network are named directly. For connections from an internet provider, an optional reverse DNS lookup (off by default, see External services) tells an office line (for example fw.acme.se, which names the company) from a home line. The IP address and hostname are never stored; only the network owner, its type and the company name or domain are.

= Server-side and in the browser =

Every page request is recorded on the server as it is served, so visits that never run JavaScript are counted too. The small tracker then confirms the same page view and adds what only the browser knows: time on page, scroll depth, screen size, events and signs of a real person. The two are matched by a page view id, so nothing is counted twice.

= Automated traffic =

Nothing is blocked and nothing is lost: every visit is recorded with a bot score and the signals behind it. The dashboard shows people by default and can switch to automated traffic or everything. Signals include crawler user agents, automated browsers, page requests that never run JavaScript, hits sent without the tracker, referrer spam, datacenter networks, impossible browsing speed and missing browser headers on one side, and real mouse, touch or keyboard use and a solved proof of work on the other.

For stronger proof, connect a self-hosted [Cap](https://capjs.js.org) server. The Cap widget comes with the plugin, and WordPress relays the challenge so visitors only ever talk to your site (unless you set a public Cap address).

= Privacy =

* No cookies, no identifiers stored in the browser, no fingerprinting. The only thing ever stored in the browser is an opt-out flag in local storage, set when a visitor unticks the opt-out checkbox or the site owner opens a page with `#nemvo-analytics-ignore`.
* Visitors are counted with a salted hash of IP address and browser; the salt is replaced daily (or weekly or monthly), so visitors cannot be followed over time.
* IP addresses are never stored. Screen sizes are bucketed and browser versions dropped.
* Do Not Track and Global Privacy Control are respected by default (Brave sends Global Privacy Control, and Firefox does in private windows); you can turn that off. Logged-in roles, IP ranges and paths can be excluded.
* Suggested privacy policy text and an opt-out shortcode: `[nemvo_analytics_optout]`.
* There is no personal data exporter or eraser, because nothing stored identifies a person: no IP addresses, no user ids, and visitor hashes that change daily.
* Data older than a set number of days (395 by default) is deleted automatically.

= Built for speed =

* The tracker is about 3 KB gzipped, printed inline, and loads nothing else unless a visit needs a proof of work.
* A hit costs a handful of small queries in one transaction. The response is sent before engagement and event pings are written where the server allows it.
* Optional queue: with Valkey (or Redis) in front, hits are answered at once and a worker writes them in batches of hundreds.
* Statistics live in the WordPress database by default, or in a separate MySQL or MariaDB database. Move them there and back with one click; tracking continues during the move.
* The dashboard asks for all its reports in a single request, and caches them in your persistent object cache when there is one.

= For developers =

* `nemvoAnalyticsTrack( name, label )` and `data-nemvo-analytics-event` / `data-nemvo-analytics-label` attributes for your own events. The names used up to 1.0.2 (`nvaTrack`, `data-nva-event`, `data-nva-label`) keep working.
* Filters: `nemvo_analytics_should_track`, `nemvo_analytics_allowed_hosts`, `nemvo_analytics_spam_referrers`, `nemvo_analytics_dns_servers`, `nemvo_analytics_rate_limit`.
* REST namespace `nemvo-analytics/v1`; the old `nemvo/v1` routes stay registered for pages cached before 1.0.3.
* WP-CLI: `wp nemvo-analytics status | install | migrate | queue work | lookup | geoip-update | purge | report`.
* Configuration through wp-config.php constants or environment variables (`NEMVO_ANALYTICS_DB_*`, `NEMVO_ANALYTICS_QUEUE_URL`, `NEMVO_ANALYTICS_CAP_*`, `_FILE` variants for Docker secrets). Passwords and keys saved on the settings page are stored encrypted (key from your WordPress salts, or `NEMVO_ANALYTICS_KEY`).
  The settings page shows ready-made Docker Compose examples.

Source code and issue tracker: [github.com/nemvoit/nemvo-analytics](https://github.com/nemvoit/nemvo-analytics).

= Made for sites without page caching =

Nemvo Analytics is built for sites that are not behind a page cache or a CDN. It still works with them, but JavaScript optimizers can delay the tracker and a proxy hides visitor addresses. The setup checklist and Site Health warn you when that is the case and say what to change.

== Installation ==

1. Install and activate the plugin. Statistics are collected right away, in the WordPress database.
2. Open **Analytics › Settings**. The setup checklist at the top shows what is worth doing next:
   * Download the free IP databases: countries (or countries and cities) and networks, needed to identify companies.
   * Add goals, such as a thank-you page or a form submission.
3. Logged-in administrators and editors are not counted. Use a private window to see your own visit under **Analytics › Realtime**.

Optional, for busy sites: a separate statistics database, the Valkey queue and Cap. Each section of the settings page explains how to set it up with Docker Compose or wp-config.php.

== Frequently Asked Questions ==

= Do I need a cookie banner? =

Nemvo Analytics sets no cookies, stores no identifier on the visitor's device (only an opt-out flag in local storage, for visitors who opt out) and does not store IP addresses. Most EU data protection authorities consider such analytics exempt from consent, but check what applies to you. Add the suggested text to your privacy policy (Settings › Privacy › Policy guide).

= How does it identify companies? Is that allowed? =

It looks up which network a visit came from, the same public information any web server log contains. It names organisations by their network or by the hostname of their office line, and never stores the IP address. Companies are organisations, not people, so this is generally not personal data, but mention it in your privacy policy (the suggested text does).

= Does it work with page caching or a CDN? =

Pageviews are sent by JavaScript, so cached pages are counted. But "delay JavaScript" and similar features can hold back the tracker, and a CDN or proxy can hide visitor addresses. Reverse proxies on a private network (Nginx Proxy Manager, Traefik, Caddy) are handled automatically; for a CDN such as Cloudflare, set "Forwarded visitor addresses" to Always. The plugin was built for sites without caching; the setup checklist warns you when it finds a cache or a proxy.

= Where is the data stored? =

In your WordPress database (tables starting with your table prefix and `nemvo_analytics_`), or in a separate MySQL/MariaDB database if you set one up. Visitor data is not sent anywhere else, with two optional exceptions you turn on yourself: reverse DNS lookups (the visitor's address goes to your server's DNS resolver) and a Cap server. See External services.

= Does it slow my site down? =

The tracker is about 3 KB gzipped and runs after the page has loaded. Collecting a hit takes a few milliseconds. With the optional queue, the database is only touched in batches.

= What happens with bots? =

They are recorded and flagged, not blocked, so your numbers show people only while you can still see the crawlers, scrapers and spam separately.

= Can I import from Google Analytics? =

Not in this version.

== External services ==

The plugin works without any external service, and contacts none until you set it up. In detail:

* **DB-IP** (db-ip.com), IP geolocation and network databases. When an administrator clicks a download button on the settings page or runs `wp nemvo-analytics geoip-update`, the free "IP to Country Lite", "IP to City Lite" or "IP to ASN Lite" database is downloaded from download.db-ip.com and then used locally. With "Update the downloaded IP databases monthly" turned on (off by default), the daily maintenance task downloads them again once they are a month old. Only the download request itself is sent (your server's address and WordPress's user agent); no visitor data. The databases are licensed under [CC BY 4.0](https://creativecommons.org/licenses/by/4.0/) and credited where their data is shown. [Lite database license](https://db-ip.com/db/lite.php), [terms of service](https://db-ip.com/tos.php), [privacy policy](https://db-ip.com/privacy.php).
* **Your DNS resolver** (reverse DNS). Off by default. With "Use reverse DNS to tell offices from homes on ISP networks" turned on, and a network database installed, the reverse DNS name of a new visit's IP address is looked up once per visit, only for addresses of internet providers, through the resolvers in the server's /etc/resolv.conf (or the `nemvo_analytics_dns_servers` filter). That resolver, which may be your hosting provider's or a public one, sees the address being looked up. The name is never stored.
* **Your Cap server** (optional, self-hosted, [capjs.js.org](https://capjs.js.org), [source](https://github.com/tiagozip/cap)). With Cap turned on, a visitor's browser fetches a proof-of-work challenge and redeems the solution. By default WordPress relays these requests to the Cap server you configured, adding the visitor's IP address in an X-Forwarded-For header for Cap's rate limiting, and WordPress verifies each solution with the server (secret key and token). With a public Cap address set, browsers talk to that Cap server directly instead, which then sees their IP address and user agent. The Cap server may send instrumentation code that the widget runs in a sandboxed frame in the browser, isolated from your page (it can't read the page, its cookies or storage). Because of this isolation, Cap site keys that block automated clicks and typing, score behavior with blocking on, or block AI agents reject solves with `instr_isolated`; leave those options off for the key you use here. Cap is software you host yourself; its maintainers receive nothing. The widget's code mentions trycap.dev (Cap's website) and has fallback URLs on cdn.jsdelivr.net, which are never used: the plugin points the widget at the files bundled in `assets/vendor/cap/`.
* **An external MySQL or MariaDB database** (optional). If you configure one, statistics (no IP addresses) are stored there instead of in the WordPress database.
* **Your Valkey or Redis server** (optional). With the queue turned on, collected hits (without IP addresses) wait there until they are written to the database.
* **Your own site** (Settings › Check tracking). When an administrator clicks the button, WordPress loads its own home page and sends a test hit to its own endpoint, with a desktop browser user agent so that the page looks like it does to visitors.

== Third-party code ==

All bundled in `assets/vendor/` (see `assets/vendor/README.txt`), so browsers load nothing from a CDN:

* Chart.js 4.5.1, MIT, https://github.com/chartjs/Chart.js
* chartjs-chart-geo 4.3.6, MIT, https://github.com/sgratzl/chartjs-chart-geo (includes parts of d3 and topojson-client, ISC)
* chartjs-chart-matrix 3.1.0, MIT, https://github.com/kurkle/chartjs-chart-matrix
* World map data from world-atlas 2.0.2 (ISC) and Natural Earth (public domain), simplified.
* Cap widget 0.1.63 (`@cap.js/widget`, `cap.min.js`), Apache License 2.0. Readable source: [widget/src at commit 18a9750](https://github.com/tiagozip/cap/tree/18a975028e2de21acc9b3f99209439d3f09093e8/widget/src) of tiagozip/cap, the "widget@0.1.63" release.
* Cap WASM solver 0.0.8 (`@cap.js/wasm`, `cap_wasm_bg.wasm`), Apache License 2.0. Rust source: [wasm/src/rust at commit 8ad83fb](https://github.com/tiagozip/cap/tree/8ad83fb50eaefb7f113ca1ec508ce242ed75033d/wasm/src/rust), the "wasm@0.0.8" release.
* HashWX (`hashwx.wasm`, shipped in `@cap.js/wasm` 0.0.8), GNU LGPL 3.0 (with the GNU GPL 3.0 it builds on, both texts in `assets/vendor/cap/`). C source: [tevador/hashwx at commit 74b567a](https://github.com/tevador/hashwx/tree/74b567a31276a4c5d7cb232a5b7639467f49f961), compiled to WebAssembly by the Cap project.
* pako 2.1.0 (`pako_inflate.min.js`), MIT and Zlib, https://github.com/nodeca/pako/tree/2.1.0

The minified and WASM files are unmodified copies of the published npm packages. `assets/js/tracker.min.js` is generated from `assets/js/tracker.js` with terser 5.51.2 (`tools/minify-tracker.sh` in the GitHub repository).

== Screenshots ==

1. The dashboard: key metrics, the trend chart, sources and pages.
2. Locations, companies & networks, goals, devices and the weekday × hour heatmap.
3. One visit: pages, time on each page, events and how the visit was classified.
4. Automated traffic: what was filtered out and why.
5. Realtime.
6. Dark mode.
7. Settings with the setup checklist and Docker Compose instructions.

== Changelog ==

= 1.0.4 =
* Changed: the bundled Cap widget is updated from 0.1.58 to 0.1.63. Only `cap.min.js` changes; the WASM solvers and pako stay the same, and the widget still loads nothing from other sites.
* Cap's browser check keeps running isolated from your page, as before. Cap site keys that block automated clicks and typing, score behavior with blocking on, or block AI agents can't be used with the plugin.

= 1.0.3 =
* Changed: reverse DNS lookups are off by default on new sites. Sites updating from an earlier version keep their setting. Reverse DNS also only runs with a network database installed.
* Changed: everything the plugin names is now prefixed with "nemvo_analytics" or "nemvo-analytics": the database tables (renamed automatically, in the WordPress database and in an external one), the REST namespace (`nemvo-analytics/v1`), script handles, JavaScript globals (`nemvoAnalyticsTrack`, `data-nemvo-analytics-event`), local storage keys and the cron schedule. The old REST routes, `nvaTrack`, `data-nva-*` attributes, `#nva-ignore` and the old opt-out flag keep working, so pages cached before the update still count.
* Changed: an external database with the old default prefix `nemvo_` now uses `nemvo_analytics_`; its tables are renamed on the first connection. Other prefixes are left as they are.
* Security: passwords and keys saved on the settings page (external database, Valkey, Cap) are stored encrypted and no longer loaded on every page.
* Security: REST hardening. Reporting and admin routes are left out of the public REST index and answer 401/403 to anonymous requests before looking at any parameter; purging, migrating and creating tables also need the WordPress REST nonce. The public collection and Cap endpoints are throttled per anonymous visitor.
* Security: CSV exports escape cells that a spreadsheet would run as a formula.
* Fixed: Cap's "Test connection" reported success for a wrong site key or secret key. Site Health now also reports when Cap rejects the keys while verifying visits.
* Fixed: hits with malformed fields no longer cause PHP warnings.
* Changed: the tracker and the dashboard widget's styles are added through registered script and style handles; the tracker is still printed inline with the same optimizer opt-out attributes.
* Changed: DB-IP is credited in the weekly email and the settings page lookup, the readme lists every external service, and the bundled Cap files come with their exact sources and full license texts (HashWX in `hashwx.wasm` is LGPL-3.0).
* Changed: uninstalling also removes the "Check tracking" log and works on every site of a network.
* Changed: the settings screen's texts are translatable in JavaScript too.
* Changed: the demo traffic generator is no longer part of the plugin zip.

= 1.0.2 =
* Fixed: the setup checklist kept warning that visitor addresses were hidden behind a proxy after "Forwarded visitor addresses" was set to Automatic or Always. The warning now follows the current setting and clears itself once visitor addresses come through.

= 1.0.1 =
* New: every page request is recorded on the server as it is served, and the tracker's hit is matched to the same page view, so visits count even when the browser does not run the tracker (most bots, blocked scripts), and are never counted twice. Turn it off under Tracking › "Record every page request on the server".
* New: Settings › Check tracking. Loads the home page and sends a test hit from the server, then lists every hit the site receives for 30 minutes with what happened to it, including browsers that stop the tracker themselves (Do Not Track, Global Privacy Control).
* New: "Forwarded visitor addresses" (Automatic, Always, Never) replaces "Trust proxy headers". Automatic uses the address a reverse proxy on a private network passes on, such as Nginx Proxy Manager, Traefik or Caddy.
* New: when only automated visits exist in a period, the dashboard says so and links to them.
* Changed: a datacenter or VPN network alone weighs less in the bot score (30 instead of 40), so people on a VPN are counted as people.
* Fixed: the map's World, Europe and other views now highlight the selected view on the first click.
* Fixed: a server without socket functions no longer loses hits when looking up networks.

= 1.0.0 =
* First release.

== Upgrade Notice ==

= 1.0.4 =
Updates the bundled Cap widget to 0.1.63.

= 1.0.3 =
Renames the plugin's database tables and REST routes (automatically; old routes keep working), encrypts saved passwords and fixes the Cap connection test. Reverse DNS is now off by default on new sites.

= 1.0.2 =
Fixes a setup warning about proxies that stayed after the setting was changed.

= 1.0.1 =
Adds Settings › Check tracking, to see why visits do or don't show up, and automatic handling of reverse proxies.

= 1.0.0 =
First release.
