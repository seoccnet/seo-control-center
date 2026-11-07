# Using the interface

The refreshed interface is available from version 0.9.36. Subsequent polish described here becomes available when that update is installed.

## Finding a project tool

The workspace menu contains Dashboard, the Quest Log ([chapter 26](26-quest-log.md)), Projects, Integrations and workspace administration. System administrators also see the installation's system tools. On a phone or narrow tablet, use **Open navigation** in the top bar. Close it with the same button, by clicking outside it or with Escape.

Project tools are grouped by purpose:

| Group | Tools |
|---|---|
| Traffic | Overview, Performance, Analytics |
| Research | Pages, Keywords, Rankings, Competitors |
| Improve | Opportunities, Site audit, Backlinks |
| Visibility | AI visibility, Local |
| Manage | Reports, Settings |

On a narrow screen, the active group opens automatically. Expand another group to choose a tool. The current tool stays highlighted. The theme button switches between light and dark mode; your installation's branding remains in place.

## Exporting the Pages library

In **Research → Pages**, apply the URL search and sort order, then choose **Export all matching URLs** to download every matching URL across pagination. Up to 10,000 matching URLs download immediately. For larger selections, up to 200,000 URLs, choose **Prepare CSV export**: background tasks write the file in the selected sort order, and it appears under **Prepared exports** with a **Download CSV** link when ready. A project prepares one export at a time, and prepared files are deleted after 24 hours. Selections above 200,000 URLs must be narrowed; the application never returns a truncated file. **Export this page as CSV** downloads only the current page.

Both exports include Google clicks/impressions, Bing clicks and the search period dates. Empty metric cells mean no imported data; zero remains zero. Bing figures use weekly buckets ending in the shown period. Exports read stored data and do not start provider requests.

## Reading the project Overview

The Overview reads from top to bottom.

1. **KPI row:** Google/Bing clicks, impressions, CTR and average position, with a sparkline of the period. When GA4 data is imported, organic sessions, key events and purchase revenue sit in the same row.
2. **Trends & outlook** sits beside **Where to act next**. Where to act next ranks queries by their estimated click gap: the clicks they would gain at the CTR of observed queries in the same position band. It also lists saved findings with their evidence.
3. **Why it changed:** **What moved** separates gaining and losing queries and pages. Beside it are **Recent SEO changes**, the ranking buckets and landing-page outcomes. Ranking changes use keywords checked on both dates.
4. **Growth & risks:** audit issues, backlinks, indexing and Core Web Vitals; researched demand; competitor and AI visibility; and local coverage.

Each card leads with its result and shows its observation dates. Open **About this data** at the bottom of a card for its sample, method and limits. Printed pages always show these notes. Sources without saved data appear in one **Not set up yet** strip, with a link to set them up. Opening Overview does not run an import or paid lookup.

All signed-in pages use the full width of the screen. Settings, account and project management pages place short form cards side by side; tables keep the full width.

The improved **Performance prognosis** uses up to 180 stored Google Search Console days, independently of the displayed range. It compares recent averages, linear trends and weekday patterns at the selected 7/14/28-day horizon. The validation table shows historical mean absolute error and test samples; **Compared forecast models** lists the competing errors. Four shared weekly origins are needed to select a tested method. A short-history fallback is explicitly unvalidated; sparse or stale data can suppress a forecast. Bing remains in the search summary, rather than mixing incomplete engine coverage in this forecast. Above the chart, one sentence says where each metric is heading at the chosen horizon compared with the average of the last 7 recorded days. Choose one metric to see a shaded error band: for each day ahead, 80% of past backtest forecasts fell inside it; it needs eight backtests per day and ends at the range shown in the metric's tile. The axis follows the recorded and projected values; when the band is wider, it is cut off at the chart edge and the line below the chart says so. The position axis starts at 1. **7-day average** draws recorded and projected lines as trailing 7-day averages, which smooths weekday swings; it leaves out the latest not-final days and the error band. When Google has preliminary numbers for the days after the last final day, each line continues as a dotted segment with a dot per day, so you can see whether the latest days follow the projected trend. A day Google is still collecting (always including today in Pacific time) is not drawn, because its totals are partial; hover it to read them. Hover a day to compare the estimate with the preliminary value; moving the pointer away returns to the last recorded day. Position is drawn upside down, so higher is better on every line. Recorded changes on consecutive days appear as one shaded band. Preliminary numbers never train the forecast. On **Forecasts**, Discover, Google News, News tab, image and video clicks get their own saved series when the site had clicks there in the last 28 days and the history is long enough.

The impressions × CTR click model is used only when it lowers historical error by at least 5% against the linear-click baseline; otherwise the baseline is retained. With short history the compound estimate is explicitly unvalidated. Additional Google position and impression history are tested as CTR inputs, using predicted future inputs rather than future actual measurements. An input needs at least 5% lower historical error on four shared origins before it is used. Endpoint error ranges need eight origins and show historical errors, not guaranteed probability coverage. Overlapping tests are not independent; neither audits nor planned SEO work automatically increase the prediction.

Only complete comparable imports support period deltas. An absent query is not treated as zero. GA4 engagement is not stored; key events per session can exceed one and do not represent a user conversion rate. The CTR gap assumes the observed peer CTR is attainable, rather than promising additional traffic. Changes shown next to metric movement do not prove causation. Missing CrUX coverage is not a passing speed result.

## Finding and comparing rows

**Pages** searches all known URLs for the project. Enter part of a URL or path, choose a sort order and 25, 50 or 100 URLs per page, then select **Apply filters**. Traffic sorts use the imported period shown above the table. Previous and Next retain your filters. Reset clears them. A dash means no imported measurement.

Other read-only tables with eight or more loaded rows offer **Filter loaded rows**, **Sort loaded rows** and **Rows per view**. These work immediately on the rows already delivered to your browser. They do not search records on another server page. Use the report's main filters and pagination to change which records are loaded. The count below the table states how many loaded rows match your filter.

Tables with bulk-selection checkboxes retain their existing filters and selection controls, so adding local pagination cannot hide a selected row. Small tables remain simple. Wide tables scroll horizontally within their card; keyboard users can focus the table region and use the arrow keys.

## Understanding charts and statistics

On **Keywords**, expand **Add keywords** for manual entry or **Suggestions from Search Console** to select imported queries. Entry opens automatically when there are no tracked keywords or when submitted values need correcting. Keeping these sections closed makes the tracked list easier to reach.

The **?** icon beside a section heading explains what its data means, its coverage or how to use the section. Open it with a click or keyboard activation. Escape closes it and returns focus to the help control. Help text does not contact an AI or external provider.

Line charts display their first, latest and highest values. Ranking charts show the best displayed position because lower positions are better. These describe the displayed series; use the report's coverage notices to understand its imported data. Focus the chart and use Left/Right to inspect individual points, or expand **View underlying data** for a table of the figures. Date ranges and server-side report filters continue to control the report as before.

## Recording and reviewing SEO changes

Open **Manage → Change timeline** in a project to record dated title, content, link, technical or release changes. Leave the URL blank for a site-wide note or enter an exact project URL. The timeline has date/URL filters and pagination; authorized editors can edit and remove notes. Overview, Google/Bing performance and keyword-history charts show recorded dates with an expandable context list. Read [SEO change annotations](19-seo-change-annotations.md) for limits, permissions and careful interpretation.

## Keeping your installation current

From **Overview → Save forecasts and review actual outcomes**, choose 7, 14 or 28 days and review the stored-data predictions for the site, sampled queries/pages and GA4 organic outcomes. Project editors can save eligible predictions and start weekly tracking, with a pause control. Cron checks outcomes daily and issues all three horizons every seven days using saved data. Original predictions stay fixed when later imports arrive; accepted actuals are saved so they survive source-history retention. Revisit to see actuals and errors. The review page labels its server-generated methods separately from the Overview chart.

The review reads up to 486 stored dates and requires sufficient observed history. Annual patterns and dated calendar assistance compete only when comparable tests support them. Record exact holiday/event occurrences with the same name for comparable events; SEOcc does not guess dates or assume traffic gains. Newly entered dates cannot affect earlier backtests. Forward ranges stay unavailable until 20 completed disjoint forecasts exist for the same source, method and horizon; their empirical 80% target is not guaranteed future coverage. Missing or restricted GA4 data stays unavailable.

Download new versions from the project's GitHub releases and upload them under **System → Updates**. Nothing is installed automatically.

Before clicking **Install**, back up the database and application files through SEOcc and your hosting panel, keep an offline encryption-key backup, and check the backup acknowledgment. SEOcc's automatic database backup is additional protection, not a complete hosting backup.


## Connecting providers

Open **Integrations**, choose a provider's settings and expand its **step-by-step guide**. Each guide explains what you need, the provider setup, what to enter in SEOcc, how to verify the connection and common errors. Installation-wide Google OAuth setup is under **System → Google app**. Project settings contain Search Console/Bing links and IndexNow; Analytics contains the GA4 property link.

Saving a CrUX key does not verify it. Request a check for a known URL, allow cron to run, and inspect its result. A 403 usually requires checking the Cloud project's enabled API and the key's restrictions. “No data” means the exact URL lacks sufficient eligible field data; it does not mean the URL passes Core Web Vitals. Follow the guide before retrying a failed job.

SEOcc keeps the existing role permissions, credential encryption and provider budgets. Tutorials never start a provider request automatically. Read the form's instructions before saving: on CrUX and GA4, a blank credential removes the connection, while some other forms keep an existing secret when left blank.

### Reviewing opportunity coverage

Open Data coverage at the last analysis before acting on SEO findings. Each source shows available, stale or missing and its latest observation date. Old findings stay open when the engine cannot safely recheck them; their scores stay unchanged. Refresh the relevant integration using its existing controls, then analyze again. Analysis itself reads local data and purchases no provider data.

### Opportunity score evidence

Opportunities show the calibrated CTR sample, confidence contributions, impact formula and any stored GA4 key-event/revenue assumptions. Missing or stale inputs remain unknown. Coverage includes top-N-of-total Google samples; unseen findings stay open when a sample is truncated. Declines include a significance check, three-period context and seasonal context when complete monthly history exists. GA4 estimates use stored Organic Search sessions, are correlations, and are not promises of causal conversions. Device/query CTR samples and outcome calibration are not yet available.


Opportunities also use stored query/URL shares and position flips to identify overlapping visibility, successful URL Inspection snapshots to flag indexing concerns, and a complete bounded audit graph to suggest contextual internal links. Multiple ranking URLs alone are not a cannibalization finding. Failed, missing or stale inspections do not prove absence from Google. Internal-link suggestions require an audited indexable source and are withheld above 2,000 pages or 5,000 graph links. Audit traffic/value sums distinct affected URLs across stored rows rather than three examples; missing rows remain unknown. Page and origin CrUX measurements remain distinct. Recent relevant annotations and title/canonical/noindex watch changes apply one −15 confidence adjustment with “measure before acting” evidence and the existing annotation impact comparison. Alert context links existing alerts without creating or sending messages. All analysis uses local records, with no provider request. Per-keyword and per-URL freshness protects old findings even when other records in the same source are fresh.


The findings list, CSV export and MCP list consolidate a normalized query (case and whitespace) or landing page (host/path, trailing slash and query-string variants) into one primary finding, chosen by priority with a stable UUID tie-break. Other rule observations and actions remain supporting evidence. Existing stored subject hashes, UUIDs, tasks and status histories are retained: done/dismissed decisions suppress duplicate open findings for the same canonical subject. The catalog is bounded at 5,000 stored rows; an incomplete catalog withholds open groups rather than overlook closed decisions. Page action plans are available at `/opportunities/by-page` and the scoped read-only `/opportunities/page-plans` endpoint, up to 200 plans. Structured monthly click estimates are nullable; old rows remain unknown until reanalyzed. Page plans take the larger of summed distinct primary-query estimates and the page estimate, instead of adding overlapping page/query gains. This is a scenario estimate, not a causal forecast.


Effort is refined from observable scope: 2–10 touched URLs imply at least medium effort, more than ten high; content work above 1,500 words is at least medium and above 3,000 high; known keyword difficulty ≥30 is medium and ≥60 high. Unknowns retain the rule estimate rather than imply easy work. Fresh stored noindex/canonical or inspection concerns appear as prerequisites before visibility/content work; dependent work follows unblocked findings, then numeric priority, then stable UUID. Priority arithmetic remains impact × confidence / 100 / effort. Time-to-value ranges (near 1–4 weeks, medium 4–8, longer 8–12+) are planning assumptions, not promised effects. `/opportunities/weekly` and the scoped `/opportunities/weekly-plan` read endpoint show up to ten findings with at most three per primary type. MCP `opportunities_list` accepts `view: weekly` and returns the same bounded selection and ordering reasons. A sparse type mix can produce fewer than ten items.


Marking a task or finding done captures a nullable Google baseline for the preceding 28 Pacific calendar days, excluding completion day, and creates scoped UUID outcomes for days 28 and 56. Task/finding completion on the same date shares one idempotent episode; the first baseline is preserved and linked annotations provide context. At day 28, days 1–28 are measured; day 56 measures days 29–56. All 28 import marks and an observed subject are required; missing data retries later, never becomes zero. Non-query/non-page technical findings remain unmeasurable from stored GSC. An hourly local job at :43 handles at most twenty due checkpoints, rotates missing rows, and retries them once per UTC day; due dates use Pacific labels with two days for GSC availability. No providers are called. The comparison widget `/opportunities/outcomes` shows raw baseline/actual clicks, position, CTR and monthly-normalized change, explicitly as correlation.

Project/type realization factors use eligible 56-day completions from the last year, at least five samples and at least 200 impressions in each window. Individual observed monthly change / raw predicted gain ratios are clipped 0–2 and blended with ten neutral prior observations; the final factor is bounded 0.25–1.5. Raw and calibrated predictions are stored separately so calibration does not compound. Technical exposure and unknown gains are not calibrated as incremental gain. Factor, sample count, raw estimate and adjusted estimate appear in evidence and MCP. Outcomes do not isolate implementation effects from seasonality, demand, ranking changes or other edits.


Opportunity detail pages expose numeric priority arithmetic, calibration/value formulas, confidence reasons, source dates, nullable raw/adjusted click estimates and related findings. Read-only JSON detail is at `/opportunities/{finding_uuid}/data`, with normal project authorization and foreign-project/finding 404s. UI and CSV share filters `type`, `page_url` (exact normalized URL/path), `min_impact` (0–100), `effort` (1–3), and `data_basis`. MCP `opportunities_list` accepts those filters, `view: weekly`, optional `opportunity_id` for scoped detail, and `status: snoozed`; these reads use opportunities:read and selected-project constraints. Status changes over MCP need the separate opportunities:write permission (chapter 8). Result-size limits still apply.

Editors can snooze a finding through its detail page until a valid project-local date after today and within one year. This hides equivalent open findings, preserves UUID/history and records an audit event. At the next local analysis on/after that date (normally within the six-hour schedule), a bulk system history transition reopens it; missing sources continue to preserve the finding. A complete fresh analysis can resolve a disappeared condition. Reopening/done/dismissed clears the snooze date. No new provider calls or MCP write scope are introduced.
