# Google indexing monitoring

Use **Project → Visibility → Google indexing** to see what Google last reported about an important URL. This helps investigate a page that stopped receiving traffic, a redirected page or an unexpected canonical.

## Connect Google once

1. Follow [Connecting Google Search Console](05-google-search-console.md). Enable **Google Search Console API** in the same Google Cloud project as your existing integration.
2. Connect your Google account under **Integrations**. Your account must have access to the relevant Search Console property.
3. Link that property under the project's **Settings**. Domain properties cover their subdomains; a URL-prefix property covers only its exact protocol, hostname and path prefix.

The existing read-only Search Console permission is sufficient. There is no separate API key, CrUX key or additional OAuth permission for indexing checks.

## Inspect an important page

1. Open **Google indexing**, or use **Inspect or watch this URL** in Page details.
2. Enter the exact HTTP(S) URL on your project's domain. Keep the original path and query-string capitalization. URLs with credentials, ports or fragments are rejected.
3. Choose **Request Google check**. Your hosting cron processes the queued request. Refresh later to see the saved result.
4. Expand **Review saved Google evidence**. The successful-check time says when SEOcc received the result; **Last Google crawl** says when Google reported crawling the page. They are different timestamps, shown in UTC.

Google's API returns its **stored index version**, which can lag changes on your website. It does not run a live page test, request indexing or edit your website. For a live test or an indexing request, use Google Search Console yourself.

## Read the evidence correctly

| State | What it means |
|---|---|
| Reported indexed | Google returned a PASS verdict at the saved check time. |
| Indexing concern | Google returned FAIL, PARTIAL or NEUTRAL. Read its coverage description before deciding what to fix. |
| Unchecked | No successful result is saved. This does not mean the URL is absent from Google. |
| Older than 14 days | The last successful result is older than two weeks. It can be useful history, but should not be treated as current. |
| Check failed | The latest attempt failed; the last successful evidence remains visible. A failure does not prove deindexing. |
| Unknown verdict | Google did not return a recognized verdict. SEOcc does not infer one. |

Compare **Google-selected canonical** with **User-declared canonical** and the intended page URL. When both are known and differ, the page shows *Google chose a different canonical than the page declares*. A redirect or duplicate can legitimately use a different canonical.

The saved evidence also lists the sitemaps that contain the URL, a sample of up to five pages Google found linking to it, and the **rich results** Google detected (for example product snippets or breadcrumbs) with their errors and warnings. Errors mean that rich result may not show; Google's Rich Results Test gives the details. Combine this with Site audit and your own Search Console investigation. Missing crawl or canonical fields mean **Not reported**, not a confirmed defect.

## Indexing history of one address

Choose **History** next to a saved URL, or open **Google indexing → History** and enter an address. Six states are kept apart, each with its evidence and date:

| State | Comes from |
|---|---|
| Public | The latest completed site audit: an HTTP 2xx answer. A redirect or error is *No*. |
| Technically indexable | Public, no noindex, no canonical to another address, and not blocked by robots.txt in Google's last check. |
| Known to Google | The latest Google check. *URL is unknown to Google* is *No*. |
| Indexed | Only a Google check with a PASS verdict. A recorded request never counts. |
| Request submitted | An indexing request you recorded. |
| Recrawl observed | Google's reported last crawl is after the recorded request. A crawl on the day of the request, before you recorded it, stays *Unknown*, because it may have come first. |

*Unknown* means there is no evidence yet, not that something is wrong. The **timeline** below lists, newest first:
- site audit fetches;
- when SEOcc's IndexNow sitemap check first read the address in your sitemap (it may have been listed earlier, so this is not a publication date);
- IndexNow submissions, which reach Bing, Yandex and others but not Google;
- each Google check, noting verdict and canonical changes;
- the crawls Google reported;
- recorded requests.

Google checks are kept as history from 0.37.0 on, up to 30 per address.

### Record an indexing request

SEOcc never asks Google to index a page and does not use the Indexing API. To make a request:

1. On the history page, open the address in Search Console URL Inspection with the link provided.
2. Choose **Request indexing** there and wait for Google's confirmation.
3. Back in SEOcc, choose how you made the request and the day of the request (in the project timezone). Enter Google's confirmation as evidence, then **Record request**. The address is added to the saved URLs.
4. After a few days, choose **Request Google check**. When Google's last crawl is after the request, *Recrawl observed* becomes *Yes*. *Indexed* changes only with Google's verdict.

Project editors record requests; viewers can read the history.

## Watch priority URLs weekly

Choose **Watch weekly** next to a saved URL. Focus on your homepage, valuable landing pages and pages affected by recent changes. You can watch up to 50 URLs per project. Cron queues up to 50 due URLs per hour across this installation; each watch becomes due seven days after its last request or attempt. A failed check gets the queue's bounded retries, then waits until its next weekly cycle.

Choose **Stop weekly checks** to remove a watch. Its saved evidence stays readable; a request already queued can still finish. Checks are not enabled for every page automatically.

After **two successful checks** report a different indexing verdict or Google-selected canonical, SEOcc records a change alert. The first result and failed checks create no change alert. Control personal delivery under [Notifications](17-alerts-and-digest.md); existing project team delivery also applies. An alert reports a change in evidence, not proof that an SEO edit caused it.

## Filters, limits and troubleshooting

Search exact URL text and filter the inspection list by watchlist, reported state, unchecked, failed or stale evidence, **Google chose another canonical** or **Rich result errors**.

**Sitemaps in Search Console** above the list shows each sitemap of the linked property with submitted URLs, errors, warnings and when Google last read it. It is refreshed with every Search Console import and makes no extra inspection calls. Open Search Console's Sitemaps report for the details of an error. Results are paginated in groups of 25. The list holds at most 500 saved URLs per project; other Pages URLs are not inspected automatically.

Manual requests for one URL are spaced at least ten minutes apart. Conservative local budgets allow 20 provider calls per property per minute and 1,000 per installation per UTC day, including retries. Google's quotas are shared with your other tools and can be reached sooner. See Google's [URL Inspection documentation](https://developers.google.com/webmaster-tools/v1/urlInspection.index/inspect) and [usage limits](https://developers.google.com/webmaster-tools/limits).

- **No result yet:** check that cron runs and review **System → Jobs**. Queued work is not a successful inspection.
- **Google rejected the check:** confirm the API is enabled, the connected account has property access, and the exact URL lies inside that property.
- **Reconnect Google:** restore the connection under Integrations. Failed checks preserve previous results.
- **Local budget reached:** wait and request another check later; avoid repeatedly checking unchanged pages.
- **Property changed:** saved evidence belongs to its original connection/property. Relinking hides old evidence; request a fresh check for the new property. Old queued work is discarded.

Project editors request checks and manage watches. Authorized viewers, Ask AI and MCP clients with `pages:read` can read saved evidence; the MCP `indexing_status` tool also returns the history states and timeline. **Ask AI** can explain coverage and suggest investigations, but it cannot run inspections or alter watches. Its normal configured AI costs apply; the indexing read tool makes no Google call.
