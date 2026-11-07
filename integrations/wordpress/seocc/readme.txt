=== SEOcc ===
Contributors: seocontrolcenter
Requires at least: 6.5
Requires PHP: 8.1
Stable tag: 0.3.1
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Serve your SEOcc-generated IndexNow key and queue notifications when public WordPress content changes.

== Installation ==

1. In SEOcc, open project Settings → IndexNow and generate the key. Copy the displayed 32-character key.
2. Upload the plugin ZIP in WordPress Plugins → Add New → Upload Plugin and activate it.
3. In WordPress Settings → SEOcc, paste the key and save. Automatic notifications are off until you check the opt-in box.
4. Open the displayed key-file link. It must answer with only the key and HTTP 200, without a redirect or login page.
5. In SEOcc, choose Check key file. The exact host must match the WordPress Home address, including www if used. For a subdirectory Home address (such as https://example.com/blog/), enter that path (/blog/) as the Site path before checking. SEO Control Center 0.9.93 or later is needed for subdirectory checks; with older versions, step 4 confirms the file instead.
6. Enable automatic notifications in WordPress and save. Publish or update a public post to exercise WP-Cron. Last notification appears on the settings page.

Requires a public HTTPS website and ordinary WordPress request routing for .txt URLs. Single sites and multisite networks (subdomain, subdirectory or mapped-domain sites) are supported. The Home address may be at the domain root (https://example.com/) or in a subdirectory (https://example.com/blog/); the WordPress files may live in a different folder either way. With a subdirectory Home address the key file is served under that path (https://example.com/blog/{key}.txt) and, as the IndexNow protocol requires, only URLs under that path are submitted. Home addresses with a port, query, encoded characters or dot segments are not accepted. No rewrite-rule or physical web-root file is created. If the web server intercepts .txt URLs or a CDN serves an old response, configure routing/purge caches or host SEOcc's downloaded key file manually.

== Multisite ==

SEOcc can be network-activated or activated on individual sites. Settings are per site: each site has its own key, opt-in and last result in Settings → SEOcc, so set up every site you want to notify. Each site serves its own key under its own Home address and only notifies its own URLs. Archived, spam and deleted sites serve no key and send nothing. WP-Cron runs per site, so a quiet site's notifications wait for traffic to that site unless the host runs a real cron job for every site.

Network deactivation clears pending notifications on every site of the network except sites that also activated SEOcc themselves; settings are kept. Uninstall removes SEOcc options and pending events from every site.

== Behavior ==

Public posts, pages and public custom post types trigger background notifications; updates, permalink changes, unpublishing and deletion of previously public content also notify the previous URL. Drafts, private or password-protected posts, attachments, revisions and autosaves do not create notifications. Activation does not submit historical content.

One notification is scheduled per changed URL; repeated saves deduplicate pending identical events. WP-Cron processes notifications with a 10-second HTTP timeout, no redirects and at most three delayed retries for network errors, HTTP 429 and server errors. HTTP 200/202 is acceptance, not proof of indexing. WP-Cron depends on site traffic unless the host runs a real cron job.

Deactivation cancels pending plugin events and preserves settings. Removing the key or disabling notifications clears pending events. Uninstall removes plugin options and pending events; no website content or SEOcc data is removed.

The plugin submits directly to IndexNow. Its results appear in WordPress, not SEOcc's submission history. SEOcc's existing sitemap polling remains independent; enabling both may send duplicate change notifications. Avoid running a second WordPress IndexNow plugin at the same time.

== Privacy and external service ==

After explicit opt-in, changed page URLs, the website host, the public IndexNow key and its location are sent to https://api.indexnow.org/indexnow. No post body, author data, SEOcc login token or license key is sent. The public key URL is served whenever a key is saved, even with automatic notifications off, so SEOcc and engines can verify it. One local last-result record contains time, HTTP status, attempt and a fixed message; provider response bodies are not stored.

IndexNow documentation: https://www.indexnow.org/documentation
IndexNow terms: https://www.indexnow.org/terms

== Changelog ==

= 0.3.1 =
Settings text: subdirectory sites enter their Home path as the Site path in SEO Control Center's Check key file.

= 0.3.0 =
Supports WordPress multisite networks: per-site settings, key files and notifications; inactive sites are skipped; network deactivation and uninstall clean up every site.

= 0.2.0 =
Supports subdirectory Home addresses: the key file is served under the Home path, keyLocation follows it, and submissions are limited to URLs under it.

= 0.1.0 =
Initial single-site companion plugin with SEOcc key serving, opt-in content-change notifications, bounded retry behavior and settings/deactivation/uninstall cleanup.
