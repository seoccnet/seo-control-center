# 25. Page monitoring

## Download change evidence

Use **Search page URL** under **Detected changes** to find a URL or part of its path. Percent, underscore and exclamation marks are searched literally. **Change type** selects all changes, significant SEO-setting changes, or page-text changes. Apply filters to return to the first page; the matching count and pagination use the same filters. **Reset filters** restores the full retained history.

When filters are active, **Export filtered history as CSV** downloads matching retained changes across all pages, up to 500. A selected change opened from an alert remains accessible regardless of history filters and exports only that change.

Under **Detected changes**, choose **Export retained history as CSV** to download up to 500 retained changes across all history pages, newest first. When viewing one change from an alert or direct history link, **Export this change as CSV** downloads only that change. Authorized project readers can export; editing permission is not required.

Each changed field has its own row with change ID, page URL, before-check and detection times in UTC, significance, field name, before/after values and an optional linked annotation ID. Times describe observations; the exact edit time between checks is unknown. Comparisons use the same rules as the screen, so missing HTML on errors/redirects is not treated as removed metadata. Booleans are Yes/No and an absent value is Not present. Empty history downloads column headers only. Multiline headings, commas and Unicode are preserved; spreadsheet formula-looking text is escaped using the existing CSV protection.

Exports read saved evidence without fetching pages, calling providers or changing watches/annotations. History outside the newest 500 retained changes is not available in this export.

Open a project → **Improve → Page monitoring**. Watch important landing pages using exact HTTPS URLs on the project domain (including its www variant). Each project can watch up to 50 pages by default; workspace owners and administrators can change it for all projects of the workspace (1–200) with **Save limit** below the watchlist. The page shows how many pages are watched out of the limit. Project editors manage watches; authorized readers can review saved evidence.

## Add many pages

Expand **Add many pages**. Paste up to 200 page URLs, one per line, and/or tick pages from the list of pages with the most Google clicks that are not watched yet, then choose **Watch these pages**. The message says how many were added, already watched, over the limit or not on the project domain. When the watchlist is full, the section says so instead. Each new page is read within the hour for its *before* snapshot.

## Change notes get before and after checks

When you record a change note (see [SEO change annotations](19-seo-change-annotations.md)) for an exact page URL on the project domain, SEOcc watches that page if it is not watched yet and the limit allows, reads it right away unless it was read in the last day, and reads it again 3 days later. The change found by those checks can then verify the outcome of a completed opportunity for that page (see [Did the change work?](07-competitors-and-opportunities.md)). Site-wide notes start no checks.

From **Pages → page details**, choose **Monitor this page for weekly SEO changes** to prefill its URL, then explicitly select **Watch weekly**.

## Weekly checks

Choose **Watch weekly**. A new page is read within the hour, then each watch is checked every seven days. The dispatcher queues at most 50 due pages per hour across the installation. One bounded background job checks one page, respecting robots.txt, public-address protections and the existing crawler's response-size limits. Reads and watchlist changes do not fetch the website during the browser request.

The first complete check establishes a baseline and does not create a change alert. Subsequent checks compare the title, meta description, first 20 H1 texts and total H1 count, resolved canonical, noindex (HTML and HTTP header directives), and HTTP status. Text is bounded to the crawler's existing limits. JavaScript-generated content is not rendered; redirects are recorded without following them. Metadata on non-HTML responses is unavailable rather than evidence that a title or canonical was removed.

Robots blocks, network failures and truncated responses retain the last complete evidence and show a check message. An HTTP error response such as 404 or 503 is observed status evidence and can be compared. A stopped watch is no longer checked; its detected change history remains. Re-adding it establishes a new baseline and invalidates old queued work. If the project's domain changes, old watches show a message and must be replaced.

## Before and after

Expand **Latest saved evidence** on a watched page. **Detected changes** shows the values before and after, with the two check timestamps in UTC. History retains the newest 500 changes per project, displayed 25 at a time. The actual website edit time between observations is unknown; an observed change is not proof of an SEO problem or traffic impact.

Canonical, noindex and HTTP status changes count as significant. They feed the existing deduplicated alert system, subject to your personal alert preferences and project access; configured team webhooks use their existing choices. Title, description and H1 changes remain visible in history without sending routine text-change alerts. Under **Notifications**, choose **Important page SEO settings changed** to enable or mute this alert type.

## Record a change and compare impact

Review the detected change and enter the actual edit date if known. The form defaults to the detection date in UTC; comparisons using that date are approximate context. Choose **Record annotation and compare impact** to save an exact-page annotation containing the observation interval and a bounded before/after summary, then open the existing impact comparison.

Repeated clicks reuse the linked annotation rather than creating duplicates. If it was explicitly removed, the action can recreate it. The impact report uses the existing equal 14/28/90-day windows, data coverage checks and Google/GA4 evidence. It may be unavailable until enough time and imported data exist. Association does not prove causation.

No page is edited or published by this feature. It adds no paid provider calls. Existing read-only AI annotation and impact tools can read the annotation after you save it. An MCP client with the **Page watches** permission can start or stop watches with `page_watch_set` (up to 50 URLs per call, within the project limit).
