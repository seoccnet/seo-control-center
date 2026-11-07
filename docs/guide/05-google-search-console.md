# 5. Connecting Google Search Console

SEO Control Center reads your Search Console data with **read-only** access and stores it on your server. The charts load from your own database, not from Google.

Connecting has two parts:

1. **Once per installation:** create a Google "app" (OAuth client). This takes about 10 minutes, and only the administrator does it.
2. **Per workspace:** connect a Google account, then link each project to its property.

Open **Integrations → Connection guides** for an illustrated Search Console walkthrough with original Google screenshots, verification links and direct setup links. The administrator’s **System → Google app** page has a separate OAuth guide. Other provider guides are available on their settings pages.

## Part 1: The Google app (administrator, once)

In the [Google Cloud Console](https://console.cloud.google.com/):

1. **Create a project**, for example "SEO Control Center".
2. **APIs & Services → Library:** search for **Google Search Console API** and click **Enable**.
3. **APIs & Services → OAuth consent screen** (also called *Google Auth Platform*):
   1. Choose the audience appropriate for your users (External for accounts outside your Google Workspace organization). Enter the app name, your support e-mail and your domain.
   2. Scopes: add `.../auth/webmasters.readonly`.
   3. In **Testing**, add the intended Google accounts as test users. Refresh tokens for this access can expire after seven days. Follow Google's publishing and verification requirements for your app's audience before moving to production.
4. **APIs & Services → Credentials → Create credentials → OAuth client ID:**
   - Application type: **Web application**.
   - **Authorized redirect URI:** `https://seo.example.com/integrations/google/callback`. Use your own domain, exactly as in the site address.
5. Copy the **Client ID** and **Client secret**.

In SEO Control Center, go to **System → Google app**, paste both values and save. The secret is stored encrypted.

If Google blocks consent, review the app audience, test-user list, authorized redirect URI and any required verification in Google Auth Platform. Confirm that the OAuth client belongs to this SEOcc installation before approving access.

## Part 2: Connect your Google account

1. Go to **Integrations → Connect a Google account**.
2. Sign in with the Google account that has access to your sites in Search Console, and allow access.
3. Your properties appear in the list. **Refresh properties** reloads them after you add a site in Search Console.

## Part 3: Link a project

1. Open the project and go to **Settings → Google Search Console → Manage link**.
2. Choose the property: a domain property (`sc-domain:example.com`) or a URL property.

The first import starts right away:

- The most recent days arrive within minutes.
- Up to **16 months** of history follow over the next hours.
- After that, new data is imported every 6 hours. Google's data is 2–3 days behind, as in Search Console itself.

With each import SEOcc also reads:

- **Latest days, not final yet:** Google's early numbers for the last two or three days, shown in their own table on **Performance**. Today's row is marked *today so far*. These numbers change a little until Google finalizes them; then they move into the normal figures. The Overview prognosis shows them next to its projection; comparisons, forecast training and opportunities use final data only.
- **Other Google surfaces:** clicks and impressions from **Discover**, **Google News**, the **News tab**, **image search** and **video search**, compared with the previous period. They are not included in the web search figures. A surface only appears when it had impressions.
- **Sitemaps:** each sitemap Google knows for the property, with errors, warnings and when Google last read it. See **Google indexing** in the project.

## Bing Webmaster Tools

Bing's index also powers Yahoo, DuckDuckGo and Ecosia, and the web search of ChatGPT and Copilot. Connecting it is free and takes a few minutes.

1. Sign in to [Bing Webmaster Tools](https://www.bing.com/webmasters) and add your site. **Import from Google Search Console** there does it in one step, verification included.
2. In Bing Webmaster Tools, open **Settings** (top right) → **API Access** → **API Key**, accept the terms and click **Generate API Key**.
3. In SEO Control Center, go to **Integrations → Bing Webmaster Tools**, paste the key and click **Connect Bing**. The key is checked with Bing and stored encrypted.
4. In the project, go to **Settings → Bing Webmaster Tools → Manage link** and choose the site.

The first import runs within minutes, then twice a day. Open **Performance** and switch to **Bing** to see clicks and impressions per day and the top queries and pages.

Bing also appears on the project **Overview**, as a row under the Google figures. The dashboard's project cards add Bing's clicks and impressions to Google's and show the split, for example *Google 323 · Bing 13*.

**How Bing's data differs from Google's:**

- **History:** about six months.
- **Daily totals:** clicks and impressions only. Bing reports positions for queries and pages, not for the whole site per day.
- **Queries and pages:** reported per week. The tables cover the full weeks that end in the selected range.
- **Crawl & index:** below the queries and pages, the Bing view shows how many of your pages are in Bing's index and how that changed in the period, how many pages Bing crawled, crawl errors and pages blocked by robots.txt. **Most linked pages** lists the pages other sites link to, as Bing counts them. It's free, but Bing-only; **Backlinks** has the fuller picture.
- **No recent data:** Bing only reports days with impressions. If the newest data is more than two weeks old, the Bing view says so; check the site's indexing in Bing Webmaster Tools (Site Explorer, URL Inspection).

## IndexNow

IndexNow tells Bing and the other participating engines (Yandex, Seznam, Naver and more) right away when a page is new or has changed. Google does not take part.

1. In the project, go to **Settings → IndexNow → Manage** and click **Set up IndexNow**.
2. Click **Download key file** and upload the file to the root folder of your website (with cPanel: File Manager → `public_html`), so it opens at `https://your-domain/<key>.txt`.
3. Click **Check key file**.
4. Enter your sitemap address and tick **Submit new and changed URLs automatically**. Every 6 hours the sitemap is read. The first check only records the URLs; after that, new pages and pages with a changed `lastmod` are submitted.
5. **Submit URLs now** sends up to 1,000 addresses by hand, for example after a larger update.

Leave the key file on your site. The results of every submission are listed on the page.

### WordPress companion

Project Overview shows integration managers an optional **Use WordPress? Meet SEOcc** notice with a download and setup link. **Hide this notice** remembers dismissal for your user and project in this browser. The permanent explanation, installation steps and download remain in **Settings → IndexNow**, even after dismissal. Viewing or hiding the notice installs nothing and sends no notification.

For a WordPress website, choose **Download SEOcc WordPress plugin** on SEOcc's IndexNow page. Upload the ZIP in WordPress **Plugins → Add New → Upload Plugin**, activate it and open **Settings → SEOcc**. Paste the key generated by this SEOcc project and save. Open the displayed key-file URL, then use **Check key file** in SEOcc. After verification, explicitly enable notifications in WordPress.

The plugin serves the key URL without writing a website file. It requires a public HTTPS Home address, WordPress 6.5+ and PHP 8.1+. In a multisite network, SEOcc can be network-activated, but each site has its own Settings → SEOcc: paste the key of the SEOcc project for that site's domain on every site you want to notify. Archived, spam and deleted sites send nothing. With a subdirectory Home address such as `https://example.com/blog/`, the key URL is `https://example.com/blog/{key}.txt` and only URLs under `/blog/` are notified; enter `/blog/` as the **Site path** when you choose **Check key file** in SEOcc. SEOcc's own manual and sitemap submissions then also send only URLs under that path. If the host intercepts `.txt` requests, use the manual key-file method or correct routing first.

WordPress queues notifications for public content changes, permalink changes and removal of previously public content through WP-Cron, with at most three retries for temporary failures. Draft/private/password-protected content, revisions, autosaves and attachments are excluded. Activation does not submit existing content. The last notification result appears in WordPress; these direct submissions are separate from SEOcc's submission history and sitemap jobs. Enabling both may duplicate notifications. Disabling notifications or deactivating the plugin cancels pending plugin work.

## Good to know

- **Totals versus rows:** the query and page tables leave out anonymised queries, as Search Console does, so they add up to less than the totals.
- **Disconnecting** revokes access at Google and keeps all imported data.
- **"Needs reconnection":** Google withdrew access (password change, revoked access, or an app still in *Testing*). Click **Connect** again. Imports resume by themselves.
