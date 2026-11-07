# Opportunity scoring

Analysis is local only. Rules receive one project snapshot and make no provider or database calls. The cron schedule remains six-hourly and existing subject hashes are unchanged.

## Source-aware lifecycle

Each rule declares source requirements. Each finding records its branch-specific dependencies. Sync preserves scores and statuses when current coverage cannot re-evaluate those dependencies. Fresh disappearance resolves an open finding. Done and dismissed statuses retain the existing lifecycle policy. A disconnect is never evidence that a condition improved.

Coverage is retained on project_opportunity_runs by a forward-only additive migration. UI and MCP read that snapshot through OpportunityRepository. Dates and source states are escaped in the existing view. Golden freshness boundaries, missing-source score preservation, fresh resolution and tenant separation have unit/PDO regression tests.

## Impact, confidence, effort and priority

Current impact uses bounded logarithmic click estimates (25 × log10(1 + monthly clicks)), with explicit heuristic alternatives for technical/local/link findings. Confidence is assigned by the shared evidence model described below. Effort is 1–3; some query rules already use observed research difficulty. Priority = impact × confidence / 100 / effort. Eligible observed outcomes calibrate incremental click estimates as described below; causal effects are not inferred.

## Stored-data limits

Joint query/device CTR samples and GA4 engagement metrics are not stored, so CTR uses aggregate query observations and value uses available Organic Search key events/revenue. Existing forecasting already has empirical forward validation and remains independent of opportunity gain assumptions. Technical findings without query/page associations cannot produce measured GSC outcomes; missing estimates stay unknown.

## CTR calibration

The bounded Google query snapshot trains position bins after brand heuristics. Expected CTR = (observed non-brand clicks + 1,000 × global CTR) / (observed non-brand impressions + 1,000). Missing positions use the global curve. Evidence shows the bin sample and aggregate-device limitation. Device totals alone cannot identify query/device CTR. LowCtr requires at least 200 impressions and a 95% Wilson upper bound below half expected CTR. AI Overview observations are possible explanations, not causal findings. Existing empirical forecasting remains independently validated.

## Evidence confidence

Every detection passes through ConfidenceModel. The score starts at 35 and combines observed impressions (up to +25), daily position SD from at least three observations (+15 down to −10), source age relative to refresh cadence (up to +10), and independent position agreement (+10 or −10). Unknown samples/history cost five each; unknown query volume/difficulty cost ten/five. Page findings do not require keyword metrics. Scores are clipped to 0–100, are heuristic reliability measures and are not probabilities. Evidence lists each contribution and source date. The worst observed volatility is used conservatively. Daily history reads are bulk, scoped and bounded; one position is not called stable. Optional corroborating sources become stored lifecycle dependencies.

## Value and intent

Raw estimated monthly click gains are nullable; unknown volume is never converted into a measured zero gain. Complete fresh GA4 windows supply page key-event and revenue rates with a 200-session project-rate prior. Value impact = 25 × log10(1 + monthly clicks × page/project relative value × intent), clipped to 0–100. Relative value is bounded 0.25–4. Query intent is an explicit English-pattern heuristic: transactional 1.25, commercial 1.1, informational 0.9, navigational 0.75, ambiguous/unsupported 1. Page-specific rates assume one added click becomes one landing session and describe observed Organic Search correlations, not causal conversions. Key events can exceed sessions. Evidence shows the inputs, rates, expected events/revenue and currency; rates unknown or unavailable fall back to clicks. Query/page associations use the largest stored impression share and disclose that approximation. ContentGap passes incremental gain after subtracting current clicks, while still showing its gross target. Technical findings without measured gains retain their qualitative impact basis.

## Trends and sampling

Google analysis selects the top 500 queries/pages by impressions and records the total count in coverage. A truncated sample does not prove disappearance: unseen search findings retain their status. Traffic declines need a >1.96 approximate equal-window click-rate z score as well as a 30% drop from at least 20 prior clicks. Three 28-day windows distinguish sustained drops from mixed trends when page history exists. Prior-year completed-month ratios require 13 consecutive marked rollups and both page-month records; monthly context is approximate. Seasonal dips within 10% of that context are suppressed; remaining seasonal-context declines lose ten confidence points. Rank moves must exceed twice daily tracker SD when at least three observations exist; otherwise volatility is explicitly unknown.


Opportunities also use stored query/URL shares and position flips to identify overlapping visibility, successful URL Inspection snapshots to flag indexing concerns, and a complete bounded audit graph to suggest contextual internal links. Multiple ranking URLs alone are not a cannibalization finding. Failed, missing or stale inspections do not prove absence from Google. Internal-link suggestions require an audited indexable source and are withheld above 2,000 pages or 5,000 graph links. Audit traffic/value sums distinct affected URLs across stored rows rather than three examples; missing rows remain unknown. Page and origin CrUX measurements remain distinct. Recent relevant annotations and title/canonical/noindex watch changes apply one −15 confidence adjustment with “measure before acting” evidence and the existing annotation impact comparison. Alert context links existing alerts without creating or sending messages. All analysis uses local records, with no provider request. Per-keyword and per-URL freshness protects old findings even when other records in the same source are fresh.


The findings list, CSV export and MCP list consolidate a normalized query (case and whitespace) or landing page (host/path, trailing slash and query-string variants) into one primary finding, chosen with unblocked work first, then priority and a stable UUID tie-break. Other rule observations and actions remain supporting evidence. Existing stored subject hashes, UUIDs, tasks and status histories are retained: done/dismissed/snoozed decisions suppress duplicate open findings for the same canonical subject. The catalog is bounded at 5,000 stored rows; an incomplete catalog withholds open groups rather than overlook closed decisions. Page action plans are available at `/opportunities/by-page` and the scoped read-only `/opportunities/page-plans` endpoint, up to 200 plans. Structured monthly click estimates are nullable; old rows remain unknown until reanalyzed. Page plans take the larger of summed distinct primary-query estimates and the page estimate, instead of adding overlapping page/query gains. This is a scenario estimate, not a causal forecast.


Effort is refined from observable scope: 2–10 touched URLs imply at least medium effort, more than ten high; content work above 1,500 words is at least medium and above 3,000 high; known keyword difficulty ≥30 is medium and ≥60 high. Unknowns retain the rule estimate rather than imply easy work. Fresh stored noindex/canonical or inspection concerns appear as prerequisites before visibility/content work; dependent work follows unblocked findings, then numeric priority, then stable UUID. Priority arithmetic remains impact × confidence / 100 / effort. Time-to-value ranges (near 1–4 weeks, medium 4–8, longer 8–12+) are planning assumptions, not promised effects. `/opportunities/weekly` and the scoped `/opportunities/weekly-plan` read endpoint show up to ten findings with at most three per primary type. MCP `opportunities_list` accepts `view: weekly` and returns the same bounded selection and ordering reasons. A sparse type mix can produce fewer than ten items.


Marking a task or finding done captures a nullable Google baseline for the preceding 28 Pacific calendar days, excluding completion day, and creates scoped UUID outcomes for days 28 and 56. Task/finding completion on the same date shares one idempotent episode; the first baseline is preserved and linked annotations provide context. At day 28, days 1–28 are measured; day 56 measures days 29–56. All 28 import marks and an observed subject are required; missing data retries later, never becomes zero. Non-query/non-page technical findings remain unmeasurable from stored GSC. An hourly local job at :43 handles at most twenty due checkpoints, rotates missing rows, and retries them once per UTC day; due dates use Pacific labels with two days for GSC availability. No providers are called. The comparison widget `/opportunities/outcomes` shows raw baseline/actual clicks, position, CTR and monthly-normalized change, explicitly as correlation.

Project/type realization factors use eligible 56-day completions from the last year, at least five samples and at least 200 impressions in each window. Individual observed monthly change / raw predicted gain ratios are clipped 0–2 and blended with ten neutral prior observations; the final factor is bounded 0.25–1.5. Raw and calibrated predictions are stored separately so calibration does not compound. Technical exposure and unknown gains are not calibrated as incremental gain. Factor, sample count, raw estimate and adjusted estimate appear in evidence and MCP. Outcomes do not isolate implementation effects from seasonality, demand, ranking changes or other edits.


Opportunity detail pages expose numeric priority arithmetic, calibration/value formulas, confidence reasons, source dates, nullable raw/adjusted click estimates and related findings. Read-only JSON detail is at `/opportunities/{finding_uuid}/data`, with normal project authorization and foreign-project/finding 404s. UI and CSV share filters `type`, `page_url` (exact normalized URL/path), `min_impact` (0–100), `effort` (1–3), and `data_basis`. MCP `opportunities_list` accepts those filters, `view: weekly`, optional `opportunity_id` for scoped detail, and `status: snoozed`; all MCP access remains read-only under existing opportunities:read and selected-project constraints. Result-size limits still apply.

Editors can snooze a finding through its detail page until a valid project-local date after today and within one year. This hides equivalent open findings, preserves UUID/history and records an audit event. At the next local analysis on/after that date (normally within the six-hour schedule), a bulk system history transition reopens it; missing sources continue to preserve the finding. A complete fresh analysis can resolve a disappeared condition. Reopening/done/dismissed clears the snooze date. No new provider calls or MCP write scope are introduced.

Validation fixture: 10,000 stored queries and 2,000 pages, top 500 per dimension, twenty in-memory analysis runs. Original analyzer median 29.145 ms (max 47.142); completed analyzer median 74.097 ms (max 85.863); peak 30 MiB. This measures CPU/model work and excludes PDO/network latency. Expanded source arrays beyond this fixture are bounded independently.

## Search Console and Bing extras (0.15.0)

Four detections use the data added in 0.14. Each has its own coverage key, so stale or missing data withholds a finding instead of resolving it.

| Rule | Subject | Requires | Detects |
|---|---|---|---|
| `IndexingRule` | `indexing:{url}` (unchanged) | `indexing`, `inspection:{hash}` | Adds both canonicals as evidence when `Inspection::canonicalMismatch()`, retitles the finding and selects the `indexing:canonical` plan. |
| `IndexingRule` | `indexing:rich:{url}` | `indexing`, `inspection:{hash}` | Rich-result issues with severity ERROR on a page with impressions or an audit record, regardless of verdict. Impact is half the page's click impact (at least 30); no click estimate, because the uplift from rich results is unknown. |
| `SitemapRule` | `indexing:sitemap:{url}` | `sitemaps` (refreshed within 7 days) | Sitemaps with errors (impact 45) or only warnings (25). |
| `BingCrawlRule` | `indexing:bing:index`, `indexing:bing:errors` | `bing_crawl` (latest day within 10 days) | Index count down by ≥ 10 % and ≥ 20 pages against the last day on or before 28 days ago; crawl errors ≥ 5 % of crawled pages and ≥ 20 in that window. |
| `SurfaceDeclineRule` | `surface:{search_type}` (type `traffic_decline`) | `search_console_surfaces` | Surface clicks down ≥ 30 % from ≥ 20 against the preceding 28 days, the page-decline thresholds. Not gated on the page sample. |

`SignalCollector` reads surface totals only when Google web data is fresh, over the same two windows as web search. Filter bases: `indexing` also matches `sitemaps` and `bing_crawl`, `search_console` matches `search_console_surfaces`, and `bing` matches `bing_crawl`. Bing link counts are not used: they have no history or competitor comparison, and DataForSEO backlinks already drive link findings.

## Business potential, BID and zero-click risk (ADR-034)

With a business profile, business potential (0–3, `BusinessPotential`) replaces the generic intent weight in impact: rules give 0.40 / 0.75 / 1.00 / 1.20 and team ratings give 0.25 / 0.60 / 1.00 / 1.30. The profile and the ratings are configuration, not source dependencies. Format fit and rankability are evidence only. The verdict is *Reconsider* for B = 0, or when the search is above the difficulty limit with no weak top-10 result and no page-one ranking. It is *Target* for B ≥ 2 and D ≥ 2 with a fitting or unknown format, and *Consider* otherwise.

Zero-click risk is a points policy: +2 for an AI Overview, +1 for a direct-answer feature, +2 for definition or quick-fact wording, +1 for a plain question, −2 for comparison, purchase, local or tool wording, and +1 when observed CTR at positions 1–5 is far below expected. A score of 3 or more lowers confidence by 10 (high); 1–2 lowers it by 5 (medium). Estimates are never reduced. Prompt-gap findings from sampled AI answers are capped at confidence 30.

## Action plans and growth notices (not score inputs)

Each finding's action plan comes from `PlaybookLibrary` (ADR-031): one playbook per kind of finding and per site audit issue type, filled in with the finding's query and page. Plans never enter impact, value, confidence, effort, estimated clicks, priority or weekly selection. The site audit's growth notices (`missing_structured_data`, `missing_author`, `deep_page`) become `SiteIssue` opportunities with an impact floor of 20 (errors 55, warnings 30), plus the usual page-count term; they do not affect the audit health score.
