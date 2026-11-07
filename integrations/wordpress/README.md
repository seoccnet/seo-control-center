# SEOcc WordPress companion

Source: `seocc/`. Installable ZIP: `resources/integrations/seocc.zip` in the SCC project. Rebuild after source changes using `php bin/build-wordpress-plugin.php`. The explicit five-file archive allowlist excludes tests, WordPress core, SCC credentials and local databases. SCC's existing signed-release mechanism includes the ZIP as a resources file; the standalone WordPress plugin is not independently signed.

The plugin supports WordPress 6.5+ and PHP 8.1+, single sites and multisite networks. It serves the key under each site's Home address rather than writing website files. It has no dependency on the SCC application runtime and sends only opt-in public URL notifications directly to IndexNow. Follow the packaged readme for setup and privacy details.

## Reproduce real-core tests

1. Download official WordPress core from `https://wordpress.org/latest.zip` and extract it into the ignored `build/wordpress/` directory. Alternatively set `SCC_WORDPRESS_TEST_ROOT` to an extracted core directory with a trailing slash.
2. Configure `.env.testing` with `TEST_DB_DATABASE` ending in `_test` and test-only MySQL credentials. The user must be allowed to create/drop temporary test databases.
3. Run `php integrations/wordpress/tests/test-plugin.php` with the project's configured PHP runtime, then again with `SCC_WORDPRESS_TEST_HOME_PATH=/blog` to cover a subdirectory Home address. Run `php integrations/wordpress/tests/test-multisite.php` for a real subdirectory network (installed by `network-install.php` in its own process).

The harness creates a unique `scc_wp_indexnow_<random>_test` database, installs WordPress, tests real post/settings/cron/plugin APIs, intercepts all outbound HTTP, disables mail and cron spawning, and drops only that database in a finally block. No live IndexNow calls or production WordPress installation are used. A child probe tests actual WordPress GET/HEAD key responses. Test credentials are read at runtime and never packaged.

Production web-server routing, CDN behavior and live IndexNow acceptance still require verification on the target site. A successful local test does not prove those hosting conditions.
