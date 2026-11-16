# Publishing to WordPress.org

How Nemvo Analytics gets into the WordPress.org plugin directory, and how new versions reach it afterwards.

## 1. Before submitting

- **WordPress.org account.** The account that submits becomes the plugin's owner. `Contributors:` in `nemvo-analytics/readme.txt` must list WordPress.org usernames (currently `fpoller`).
- **Two-factor authentication.** WordPress.org requires 2FA for accounts with commit access. Turn it on under *Edit profile → Account & Security*.
- **Checks.** Build the zip and run the smoke test. The smoke test includes Plugin Check, the same tool the review team runs first:

  ```sh
  ./build.sh
  ./smoke-test.sh dist/nemvo-analytics-1.0.3.zip
  ```

  Optionally, paste `readme.txt` into the [readme validator](https://wordpress.org/plugins/developers/readme-validator/).

Plugin Check reports no errors and no warnings.

## 2. Submit

1. Sign in at [wordpress.org/plugins/developers/add](https://wordpress.org/plugins/developers/add/).
2. Upload `dist/nemvo-analytics-<version>.zip` and accept the guidelines.
3. The slug is taken from the plugin name: `nemvo-analytics`. It shows on the confirmation page. You can still ask the reviewers to change it, but once the plugin is approved it is permanent.

An automated scan runs right away. Then the plugin waits in the review queue; the page shows how many plugins are ahead of it. Reviews take anywhere from a few days to several weeks.

## 3. During review

The review team emails you from `plugins@wordpress.org`. Reply to that email, and don't open a new thread. If they ask for changes:

1. Make the changes and raise the patch version, as for every change (see Later releases).
2. Run the tests, `./build.sh` and `./smoke-test.sh` again.
3. Upload the new zip on the same [Add your plugin](https://wordpress.org/plugins/developers/add/) page, then reply to the email saying what you changed.

Reviewers often ask about the following. The answers are already in `readme.txt`:

| Topic | Where it's answered |
|---|---|
| Calls to external services (DB-IP downloads, reverse DNS, your own Cap, Valkey and database servers, the "Check tracking" loopback) | *External services* section |
| Minified files (Chart.js and its plugins, the Cap widget and WASM, pako, the minified tracker) | *Third-party code* section and `assets/vendor/README.txt` and `assets/vendor/cap/README.txt` (exact source commit, the Rust source of the WASM); `assets/js/tracker.js` is the source of `tracker.min.js` (terser 5.51.2, `tools/minify-tracker.sh`) |
| jsDelivr URLs and trycap.dev in `cap.min.js` | Fallbacks of the unmodified Cap widget, never used: the tracker sets `CAP_CUSTOM_WASM_URL`, `CAP_CUSTOM_HASHWX_URL` and `CAP_PAKO_URL` to the bundled files first. Cap's server may send instrumentation code; Cap is self-hosted by the site owner. |
| Licenses | MIT for the plugin. MIT, ISC, Apache-2.0 (full text in `assets/vendor/cap/LICENSE-cap.txt`; upstream has no NOTICE), LGPL-3.0 for HashWX in `hashwx.wasm` (LGPL and GPL 3.0 texts next to it) and Zlib for the bundled files, all GPL-compatible. DB-IP data is CC BY 4.0 and credited in the dashboard footer, the settings lookup and the weekly email. |
| Identifying companies and visitor privacy | *Description › Companies and networks* and *Privacy*, and the FAQ. Reverse DNS is off by default. No exporter/eraser: nothing stored identifies a person. |
| Inline script on every page | The tracker is a registered handle (`nemvo-analytics-tracker`) without a source file, its code added with `wp_add_inline_script()`; it can be loaded as a file instead (Settings › Tracking) |
| Public REST routes (`__return_true`) | `POST /e` and `POST /cap/challenge|redeem` only, each with a comment why: visitors' browsers call them. Size limits, validation, per-visitor throttle (`nemvo_analytics_rate_limit`). Everything else needs a capability and is out of the REST index. |
| Raw sockets and files | Valkey (RESP over `stream_socket_client`, no PHP extension needed), DNS over UDP (own resolver client with a 0.4 s timeout), an `fsockopen` reachability probe before connecting to an external database, and streamed gunzip of the DB-IP download into `uploads/nemvo-analytics/` (too large for memory). |
| "Check tracking" sends a Chrome user agent | A loopback request to the site's own home page, only when an administrator clicks the button, so that the page looks like it does to visitors (caches and security plugins vary by user agent). |

## 4. After approval

You get Subversion (SVN) access to `https://plugins.svn.wordpress.org/nemvo-analytics/`. SVN doesn't use your normal password: set an SVN password under [Edit profile → Account & Security](https://profiles.wordpress.org/me/profile/edit/group/3/?screen=svn-password).

The plugin goes live when the approved version (below `<version>`, e.g. 1.0.3) is committed to SVN. Use one of the two ways below, not both.

### Option A: GitHub Actions (recommended)

The release workflow (`.github/workflows/release.yml`) already contains a WordPress.org job, which only runs once you switch it on. In the repository on GitHub:

1. **Settings → Secrets and variables → Actions → Secrets:** add `SVN_USERNAME` (your WordPress.org username) and `SVN_PASSWORD` (the SVN password).
2. **Settings → Secrets and variables → Actions → Variables:** add `WPORG_DEPLOY` with the value `true`.
3. **Actions → Release:** open the run for `v<version>` (push that tag first if it isn't on GitHub yet) and choose **Re-run all jobs**.

The job builds the zip, copies its contents (the plugin without the dev-only seeder) to `trunk/`, creates `tags/<version>` and uploads `.wordpress-org/` (icons, banners, screenshots) to `assets/`.

### Option B: by hand with SVN

```sh
./build.sh && rm -rf build && mkdir build && unzip -q dist/nemvo-analytics-<version>.zip -d build
svn checkout https://plugins.svn.wordpress.org/nemvo-analytics wporg && cd wporg
rsync -a --delete ../build/nemvo-analytics/ trunk/
mkdir -p assets && cp ../.wordpress-org/* assets/
svn add --force trunk assets
svn cp trunk tags/<version>
svn propset svn:mime-type image/png assets/*.png
svn propset svn:mime-type image/svg+xml assets/*.svg
svn commit -m "Nemvo Analytics <version>" --username <wordpress.org username>
```

The listing at `https://wordpress.org/plugins/nemvo-analytics/` updates within a few minutes. Search can take a few hours to pick it up.

## 5. Later releases

Every change gets its own version, also while WordPress.org reviews a submission: one patch version per delivered piece of work.

1. Set the new version in `nemvo-analytics/nemvo-analytics.php` (header and `NEMVO_ANALYTICS_VERSION`) and in `readme.txt` (`Stable tag:`). Add a changelog entry (and an Upgrade Notice) to `readme.txt` and a changelog entry to `README.md`. `build.sh` refuses to build when these differ.
2. If the database schema or stored data changed, raise `Install::DB_VERSION` and add the step to `Install::upgrade()` (`dbDelta` in `DB::install()` adds new columns and keys in each statistics database on its next use).
3. If strings changed, regenerate `languages/nemvo-analytics.pot`:
   `docker compose -f .devcontainer/docker-compose.yml exec -T wordpress wp i18n make-pot /var/www/html/wp-content/plugins/nemvo-analytics /var/www/html/wp-content/plugins/nemvo-analytics/languages/nemvo-analytics.pot --domain=nemvo-analytics --exclude=assets/vendor,includes/class-seeder.php`
4. If `tracker.js` changed, run `tools/minify-tracker.sh` (`build.sh` does too; CI checks it).
5. Run the tests (README → Tests), PHPCS, `./build.sh` and `./smoke-test.sh dist/nemvo-analytics-<version>.zip`. For changes to tables or stored settings, also `tests/upgrade-test.sh dist/nemvo-analytics-<old>.zip dist/nemvo-analytics-<version>.zip`.
6. Commit, tag and push:

   ```sh
   git tag -a v1.0.1 -m "Nemvo Analytics 1.0.1"
   git push origin main v1.0.1
   ```

The release workflow creates the GitHub release with the zip, and, with `WPORG_DEPLOY` on, commits the version to WordPress.org. Sites that have the plugin installed are then offered the update in wp-admin.

When a new WordPress version comes out, test the plugin with it and raise `Tested up to:` in `readme.txt`. Release this as a normal patch version, or commit only `readme.txt` to SVN `trunk/` and `tags/<current version>/`.

## Assets

`.wordpress-org/` holds what the directory shows besides the readme:

| File | Size | Shown as |
|---|---|---|
| `icon.svg`, `icon-256x256.png`, `icon-128x128.png` | 256 and 128 px | Plugin icon |
| `banner-1544x500.png`, `banner-772x250.png` | | Header banner |
| `screenshot-1.png` … `screenshot-7.png` | | Screenshots, captioned by `== Screenshots ==` in `readme.txt` |

They are generated from the dev container with its demo data: `cd tests/e2e && node screenshots.mjs`.
