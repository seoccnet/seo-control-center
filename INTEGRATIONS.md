# Integrations

GA4 diagnostics (local, unreleased): failures identify service-account authentication versus GA4 report requests. Fixed allowlisted explanations distinguish disabled APIs, token scope, permission/policy/project refusals, invalid OAuth assertions and quota. Unknown/malformed/truncated errors stay generic; raw provider descriptions, messages and metadata are excluded. This does not establish the cause of the existing live 403 until the patch is deployed and a new import runs.

CrUX error diagnostics (local, unreleased): recognized Google ErrorInfo reasons identify server IP, HTTP referrer or API restrictions, a disabled API, invalid keys and invalid consumer projects. Only fixed explanations are logged; unknown/malformed/truncated responses retain the generic HTTP error. Raw Google messages and metadata are never logged. HTTP 404 remains missing coverage; 429/5xx remain retryable. This patch is not included in the signed 0.9.63 packages.

## Browser crawl and CrUX expansion — 0.9.63 (2026-10-07)

Explicit browser crawls now support 1/10/50/100 pages with resumable resource statuses/waterfalls, five archived diagnostics and browser findings in Opportunities and printable/email reports. Separate mobile Lighthouse tasks use saved IDs and free retrieval. CrUX adds independent desktop history and opt-in weekly page/device watches (52 snapshots; 50 watches/project; 50 due dispatches/hour). Persisted sitemap startup and robots cache perform one HTTP request per step; robots redirects use guarded public HTTPS hops. New forward-only migrations 000059–000061 preserve mobile data and correct the missing rendered_audit ENUM value that caused a live 0.9.62 HTTP 500 before task submission.

Stale-worker failure, cancellation writes, concurrent watch limits, old-domain jobs, malformed progress, empty Lighthouse diagnostics and expired queue leases are guarded. No new runtime dependencies or automatic paid weekly rendering. General scroll/click/consent journeys, complete unloaded CSS traversal and a hard OS-DNS timing bound remain open; live provider rendering remains unverified until this build is installed. This section supersedes the earlier one-page/mobile-only descriptions. Audit: an internal audit


## Browser-rendered page diagnostics (Unreleased)

Site audit offers an explicit `analysis.execute` action for one known HTTPS page on the current project domain, gated by `site_audit` and an active DataForSEO account. `site_audit.render` separates robots/DNS preflight, paid submission and free retrieval, making at most one HTTP call per turn. The mobile OnPage task sets `max_crawl_pages=1`, resources/JavaScript/browser rendering/XHR on, and no forced sitewide checks. Estimate: $0.0051 per page (pricing checked 2026-10-07); the existing client reserves and settles actual reported charges. Free `on_page/pages` reads accept only the checkpointed task ID.

Existing project-scoped `research_results` stores kind `rendered_audit`; `ResumableLookup` checkpoints submission before polling. Known task IDs resume without resubmission; unknown submission outcomes block replacement checks pending usage review. Payload request UUIDs reject stale jobs, and per-project locks prevent overlap. Unfinished checks stop after 48 hours; same-page retries retrieve saved tasks. Previous published evidence is retained. No migration/dependency, automatic paid weekly rendering or live request during implementation.

The fixed custom script is below the provider's 2,000-character limit. Evidence caps: first 100 links, 20 H1s, up to 60 loaded resource entries, CSS backgrounds from first 100 DOM elements, first 40 image sources, 100 total resource references. Declared references are distinguished from loaded entries. Lab LCP/CLS stay separate from CrUX p75; no lab INP or overall Core Web Vitals pass is claimed. Scrolling, consent and later interaction can expose additional resources. This diagnostic does not replace the free crawl or change its health score.

**Interaction limits (checked 2026-10-09).** The [On-Page task](https://docs.dataforseo.com/v3/on_page-task_post/) and [Instant Pages](https://docs.dataforseo.com/v3/on_page-instant_pages/) documentation offers no scroll, click, wait-for-element or multi-step fields. The only related options are `custom_js` (at most 700 ms; on task_post at most 2,000 characters) and `disable_cookie_popup`, which suppresses rather than accepts the consent prompt. SCC already uses both. The owner decided not to add a second rendering provider, so scroll, click and consent journeys stay out of scope.

## Resumable DataForSEO batches (Unreleased)

Domain overview, backlink profile, domain/platform mention metrics and AI answer sample jobs checkpoint each normalized single-request result in research_results.params._batch. Completed steps include empty responses; previous published items remain unchanged until final completion, which removes the checkpoint. Safe queue retries reuse saved steps. Repeating a failed request with identical settings resumes saved progress after retry exhaustion or a budget stop; successful runs and changed inputs start fresh. Existing leases prevent overlapping workers, and checkpoint writes lock and compare the current request before updating. Removed/changed queued competitor domains stop before provider calls.

Before every paid step, save a pending marker. Clear it after successful persistence or a known uncharged transient rejection/budget refusal. Transport failures, server/search-engine errors, malformed results, settlement failures and crashes retain it, preventing replay; an identical explicit request returns 409 for unresolved pending work for 24 hours after the failed lookup was requested; after that an explicit request starts over without the old progress (scheduled runs stay blocked). HTTP 401/402 refusals are known rejections even when DataForSEO's body reports a top-level 20000; the task's 4xxxx code is used, or 40100/40200. Provider rate-limit errors with nonzero/missing reported cost also retain uncertainty. These cases require usage review, not an automatic second purchase. Existing per-call budgets, credentials, entitlement and request permission checks remain unchanged. No new schema or dependency.

### AI pricing verification (2026-10-05)

Standard USD/MTok rates in config/assistant.php: Sonnet 5.5 input/output 2/10, Opus 5.5 4/20 (cache read 0.2), Haiku 4.5 1/5. GPT cache writes are separated from total input tokens and charged at 1.25 times ordinary input; cache reads use each model's configured rate. Chat and digest reserve the higher ordinary/cache-write estimate before sending, then settle reported usage. Existing ledger entries are preserved. These estimates are not provider invoices. Sources: [Anthropic pricing](https://platform.claude.com/docs/en/about-claude/pricing), [OpenAI prompt caching accounting](https://developers.openai.com/api/docs/guides/prompt-caching), [OpenAI pricing](https://developers.openai.com/api/docs/pricing).

## AI request hardening (Unreleased)

Chat and opt-in digest summaries share workspace/provider/key-fingerprint cooldowns in the existing rate limiter. Retry-After is honored up to 24 hours; transient failures add exponential backoff and jitter. No worker sleeps or automatically repeats a failed call. Chat caps an identical step at three attempts/hour and each question at eight calls, with at most six tools per response. HTTP timeouts are bounded to 10–60 seconds; browser timeout is 75 seconds and uncertain requests offer a saved-conversation refresh before another send.

GPT store:false requests include reasoning.encrypted_content and replay original ordered response items, including IDs/phase, without duplicating display blocks. Claude excludes these provider-specific items. Malformed successful responses, transport failures and server errors are conservatively considered possibly charged: retain the reserved estimate and warn before explicit retries. Known rejections release the reservation; successful calls settle actual reported usage. Incomplete/empty answers stop without executing unfinished tool requests or another model call. Digest failures fall back to the existing non-AI summary.

Replay retains up to three recent question turns within 90 KB, preserving tool pair boundaries. Tool output stays valid JSON within 16 KB; digest facts within 24 KB; provider response bodies within 2 MB. Public guide retrieval and focused, paginated local reads avoid repeatedly sending broad documentation/data dumps. No model call is made if the current turn already exceeds the context bound. Provider invoices remain authoritative for actual charges.

## Google URL Inspection (Unreleased)

Google indexing reuses the linked Search Console property, encrypted OAuth token and webmasters.readonly scope. Enable Google Search Console API in the same Cloud project as the existing Google integration; no CrUX key or new scope is needed. POST https://searchconsole.googleapis.com/v1/urlInspection/index:inspect receives inspectionUrl, siteUrl and en-US languageCode. URL-prefix properties include their trailing slash; domain properties use sc-domain:.

The API provides Google's stored index version, without a live test or request-indexing action. SCC retains verdict, coverageState, robotsTxtState, indexingState, pageFetchState, lastCrawlTime, googleCanonical, userCanonical and crawledAs. Since 0.14 it also keeps up to five `sitemap` and `referringUrls` entries and the `richResultsResult` verdict with each detected type and up to five deduplicated issues (message, ERROR/WARNING). Older stored results read these as empty. `Inspection::canonicalMismatch()` (both canonicals known and different, ignoring a trailing slash) and `richResultErrors()` drive the *Google chose another canonical* and *Rich result errors* filters. Unknown verdicts stay unknown. Verdict/canonical changes after two successful checks feed existing alerts and do not prove a technical cause. Generic errors never overwrite successful evidence.

Google documents 2,000 daily and 600 per-minute inspections per site, with separate Cloud-project limits. SCC has lower local budgets (20/property/minute, 1,000/installation/UTC day); other tools/installations share Google's quota. Official sources: [endpoint](https://developers.google.com/webmaster-tools/v1/urlInspection.index/inspect), [result fields](https://developers.google.com/webmaster-tools/v1/urlInspection.index/UrlInspectionResult), [usage limits](https://developers.google.com/webmaster-tools/limits). Guide 23 covers setup and interpretation.

## Integration architecture

### In-app setup tutorials (unreleased)

Google tutorials now include locally bundled, unmodified screenshots from Google documentation with source and CC BY 4.0 attribution, prerequisite checklists, numbered steps, per-step setup links and provider demo/training links. The integration overview makes Search Console, GA4 and CrUX guides directly available; their settings/project screens reuse the same component. Other providers retain their setup guides with the shared refined layout. Image provenance: docs/integration-tutorial-assets.md. No third-party image requests or embeds on tutorial load.

GA4 splits account creation, JSON key download, property Viewer access, credential save and numeric property linking into separate steps. The Google demo is explicitly report-only and cannot connect through the Data API. CrUX covers mobile/desktop results, weekly watches, URL coverage and the outgoing hosting IP, which can differ from the hosting account IP. Console links contain no customer project, property, key or credential identifiers. Viewing a tutorial performs no setup actions.

Every provider settings page includes an expandable step-by-step guide with prerequisites, setup, verification, common errors and official links. Guides cover installation-wide Google OAuth, workspace Search Console/Bing/DataForSEO/GA4/CrUX/Ask AI/MCP and project property/site/IndexNow setup. The GA4 project report also links the reporting setup guide. The integration overview presents the available providers as a responsive card grid; setup forms preserve their permission gates and blank-field behavior.

Tutorials do not create accounts, grant permissions, save credentials or run checks automatically. CrUX specifically distinguishes a saved key from verified access, explains server-side key restrictions and HTTP 403/429, and treats insufficient field data as unavailable. GA4 distinguishes a numeric property ID from a measurement ID and requires property-level service-account access. IndexNow uses SCC's generated key file and existing verification workflow. MCP tutorials preserve opt-in scopes, including `pages:read`, and separate external client access from Ask AI's paid model account.

External services are adapters, not domain dependencies.

The application stores normalized internal data.

## Google Search Console

Responsibilities:

- OAuth connection;
- available property discovery;
- project/property mapping;
- asynchronous imports;
- sync state tracking;
- token refresh;
- encrypted token storage;
- safe handling of revoked credentials.

Dashboards should read imported local data, not depend on live Google responses.

### Implementation (0.2)

- **OAuth client per installation.** A system administrator enters a Google Cloud *Web application* OAuth client under *System → Google* (`oauth_clients`, secret encrypted). Redirect URI: `{APP_URL}/integrations/google/callback`. Google only accepts HTTPS on a public domain or `http://localhost`, so `.test` development hosts cannot complete the flow. While the consent screen is in *Testing*, Google expires refresh tokens after 7 days.
- **Scopes:** `openid email https://www.googleapis.com/auth/webmasters.readonly` (read-only). `access_type=offline`, `prompt=consent`, PKCE (S256) and a single-use `state` bound to the user's session and current workspace (10-minute expiry). The callback is rate-limited (20 per 10 minutes per user).
- **Connections** are workspace-owned (`connections`, one per Google account per workspace). Tokens live in `connection_tokens`, encrypted with contexts `connection.refresh:{id}` / `connection.access:{id}`. Access tokens are refreshed 2 minutes before expiry; `invalid_grant`/401 marks the connection `error` ("needs reconnection") and is audited as `integration.google.authorization_lost`.
- **Property discovery** via `GET /webmasters/v3/sites` into `google_properties`. Only `siteOwner`, `siteFullUser` and `siteRestrictedUser` properties can be linked.
- **Project link:** one property per project (`project_search_console`), set by users with `integrations.manage`; the connection must belong to the project's workspace.
- **Disconnect** revokes the refresh token at Google (best effort), deletes local tokens, unlinks projects and keeps imported data.
- **Error mapping** (`GoogleErrors`): 401/`invalid_grant` → authorization lost (permanent); 429/5xx → retryable (honours `Retry-After`); other 4xx → permanent.
- **Outbound HTTP** goes through `Core\Http\Client\HttpClient`: HTTPS only, TLS verified, no redirects, per-call host allowlist (`accounts`/`oauth2`/`openidconnect`/`www.googleapis.com`).
- **Search Analytics limits:** max 25,000 rows per request (paginate with `startRow`), dates in Pacific Time, `dataState=final` by default, Google returns top rows only; avoid combining `page` and `query` dimensions over long ranges.

### Search Console imports (0.2)

- **Trigger.** Linking a property queues an import immediately; the scheduled task `search_console.sync` (every 6 hours) requests one for every linked project; *Import now* on the Search performance page queues one manually (`integrations.manage`). Requests collapse through an idempotency key per project and 10-minute window.
- **Job `search_console.import`** (queue `imports`) holds a per-project lease lock (`search_console.import:{project}`), so overlapping runs never import the same project twice. It works until 15 seconds before the cron run's budget, then queues a continuation. Retryable Google errors (429/5xx/network) are retried by the queue; everything else fails permanently.
- **Step 1 — daily totals.** One request with dimension `date` over the retention window (`SEARCH_CONSOLE_BACKFILL_DAYS`, default 486 days ≈ 16 months, ending yesterday in Pacific Time), `type=web`, `dataState=final`. Repeated at most every `SEARCH_CONSOLE_TOTALS_REFRESH_HOURS` (default 6). Newly finalized days appear here; days without data are absent.
- **Step 2 — per-day detail**, newest day first: four requests per day (`query`, `page`, `device`, `country`), each up to `SEARCH_CONSOLE_MAX_ROWS_PER_DAY` rows (default 5,000; Google returns the top rows by clicks). The day's rows are replaced in one transaction and the day is recorded in `search_console_import_days`, so a re-run never duplicates. Only days with impressions are imported.
- **Joint query/page detail (Pages):** one additional paginated request per day in the latest 28-day window, with dimensions `query,page` and the same row cap. It has its own completion marker, including empty responses. Existing imports backfill this window resumably. Single-dimension page totals remain authoritative; anonymized queries mean joint rows can sum to less. Joint rows do not receive monthly rollups.
- **Extras with each totals refresh (0.14).** Right after step 1, `SearchConsoleImporter::refreshExtras()` makes seven more requests. They are best effort: a failure is logged and retried with the next refresh, and only lost authorization stops the import.
  - Preliminary totals: dimension `date`, `type=web`, `dataState=all` for the last 10 days up to today (Pacific Time). Google's `metadata.first_incomplete_date` marks days still being collected. Stored in `search_console_fresh_daily`, replaced each time; readers show only days newer than the newest final day, so final data always wins. The Overview prognosis plots them as markers (`forecast.preliminary`); comparisons, forecast training and opportunities use final data only.
  - Other surfaces: dimension `date`, `dataState=final` over the retention window, once each for `discover`, `googleNews`, `news`, `image` and `video` (`SearchType`). Upserted into `search_console_search_types`. Discover and Google News have no position (stored as 0).
  - Sitemaps: `GET /sites/{site}/sitemaps` (same read-only scope), replacing `search_console_sitemaps`. Counts arrive as strings; `contents[].submitted` is summed. Google's `indexed` count is not used.
- **Property change.** The sync state remembers which property the data came from. When a project is linked to a *different* property, the next import deletes the old performance data before importing (unlinking alone keeps data).
- **Revoked access** (401/`invalid_grant`) marks the connection *needs reconnection*; imports then stop without calling Google until the user reconnects.
- **Reading.** The dashboard reads only local tables (never Google on page load). Ranges end on the newest imported day. Averages are weighted by impressions, as in Search Console. Query rows exclude anonymized queries, so they sum to less than the daily totals.
- **Quota.** A full 16-month backfill is about 1 + 4 × (days with data) requests, well within Search Console's per-site limits; on shared hosting it completes over the following hours of cron runs.

## Bing Webmaster Tools

Implemented in 1.1 (`app/Modules/Bing`). It is free and needs no administrator setup.

- **Connection:** one API key per workspace (`integrations.manage`). The user creates it in Bing Webmaster Tools → Settings → API Access; one key covers all of the account's verified sites.
  - The key is checked with `GetUserSites` before it is stored encrypted (`bing_profiles.api_key`, AAD `bing.api_key:{workspace_id}`, registered for key rotation).
  - The verified sites are cached with the connection. *Refresh sites* reloads them.
  - A key Bing rejects marks the connection *Access refused*, and imports stop until a new key is saved.
- **Client:** `Api\BingWebmasterClient` calls the JSON endpoint `https://ssl.bing.com/webmaster/api.svc/json/<Method>?apikey=…`. The host is allowlisted, and `apikey` is in the redactor's list. Error mapping:
  - 401/403, or a message naming the API key or `NotAuthorized`: authorization lost;
  - 429 and 5xx: retryable;
  - anything else: permanent.
- **Project link:** a project links to one of the workspace's verified sites (`bing_project_sites`). Linking another site deletes the previous site's data.
- **Import:** `bing.import`, per project with a lease lock. It makes three calls, each of which returns Bing's whole history (about six months), so the stored data is replaced in one transaction:
  - `GetRankAndTrafficStats`: clicks and impressions per day (no position);
  - `GetQueryStats` and `GetPageStats`: weekly buckets with the impression-weighted average position. At most 1,000 rows per week and dimension are kept.

  Then, best effort (0.14; a failure is logged and leaves the performance data stored):
  - `GetCrawlStats`: per day pages crawled, crawl errors, pages in the index, inbound links and status code counts (301 and 302 combined as redirects);
  - `GetLinkCounts`: inbound links per page of the site, paged, at most `MAX_LINK_PAGES` (5) pages per import.

  Both are replaced together in `bing_crawl_stats` and `bing_link_counts`.

  `bing.sync` queues every linked project at 05:47 and 17:47. Linking and *Import now* queue an import directly. Both jobs require the feature `bing_webmaster`.
- **Dates:** WCF JSON dates (`/Date(ms±hhmm)/`) are read as Pacific Time days, like Bing's reports.
- **Reading:** the Bing performance view (`/projects/{id}/performance/bing`) offers 7 days to 6 months.
  - Ranges end on the later of two days: the newest imported day, and three days before today (Pacific Time). Bing sends only days with impressions, so anchoring on the newest row alone would show an old period for a site that has had none since.
  - When the newest data is more than 14 days old, the page says so and points to Bing's indexing tools.
  - The query and page tables sum the weekly buckets that end inside the range. CSV export works as on the Google view.
  - *Crawl & index* shows the latest index count and its change in the range, crawled pages, crawl errors and robots.txt blocks, plus the 25 most linked pages (site-wide, not range-bound).

## IndexNow

Local unreleased WordPress companion: `integrations/wordpress/seocc` builds into the allowlisted `resources/integrations/seocc.zip` using `php bin/build-wordpress-plugin.php`. The IndexNow controller exposes a project-authorized, `integrations.manage`-gated no-store ZIP download. The standalone plugin uses WordPress settings/nonce/capability APIs, a virtual root key endpoint and opt-in WP-Cron notifications. Single-site HTTPS Home addresses at the domain root or in a subdirectory (0.2.0: key file served under the Home path, `keyLocation` follows it, submissions limited to URLs under it; SCC's key check accepts the same site path from 0.9.93, and SCC's own submissions are then limited to URLs under it) and, from 0.3.0, multisite networks with per-site settings, key files and queues; inactive sites skipped; network deactivation/uninstall clean every site; no SEOcc login credentials, vendor calls, application dependencies or database migration. Results stay in WordPress; SCC sitemap monitoring/history is unchanged. Core lifecycle tests intercept HTTP; target web-server routing and live acceptance remain unverified. Rebuild the plugin ZIP before signing any SCC release that changes its source.

Implemented in 1.1 (`app/Modules/IndexNow`). It is free and switched by `bing_webmaster`. Submissions go to `https://api.indexnow.org/indexnow`, which shares them with every participating engine: Bing, Yandex, Seznam, Naver and others. Google does not take part.

- **Key:** per project, 32 hex characters. It is public by design: the site must serve `https://{host}/{key}.txt` containing the key.
  - *Check key file* tries the project's domain with and without `www.`, and stores the host that answers.
  - The page also offers the file for download.
- **Manual:** up to 1,000 URLs on the verified host, one per line, queued as `indexnow.submit`.
- **Sitemap:** `indexnow.sync` runs every 6 hours (`19 */6`) and queues `indexnow.sitemap` for each project with automatic submissions on. That job:
  1. reads the sitemap, or a sitemap index with up to 50 children, at most 50,000 URLs (gzip is read too);
  2. keeps only URLs on the verified host;
  3. compares them with `indexnow_urls`. The first check only records the baseline. Later checks submit new URLs and changed `lastmod` values, up to 10,000 per run.

  URLs whose submission failed keep their old `lastmod`, so they are offered again.
- **Safety:** the key file and sitemaps are on hosts users entered, so `IndexNowClient` refuses hosts that resolve to private or reserved addresses (`Core\Http\Client\HostResolver`). Other limits:
  - redirects are not followed (users enter the final address);
  - documents are limited to 20 MB;
  - sitemaps with a DOCTYPE are rejected, and XML is read without network access or entity loading.
- **Answers:** 200 and 202 count as accepted (202: the key is still being validated). 403 and 422 are logged as rejections; 429 and 5xx are retried by the queue. Every submission is logged in `indexnow_submissions` and shown on the page.

## DataForSEO

Customers supply their own credentials.

Required behaviors:

- encrypted credential storage;
- connection verification;
- provider-specific adapter layer;
- async tasks;
- rate-limit awareness;
- retries for retryable failures;
- no endless retries for authentication/validation failures;
- usage and cost accounting;
- daily/monthly budgets;
- per-operation cost limits;
- idempotency for expensive jobs;
- temporary raw response retention.

### Implementation (0.3, part 1)

- **Credentials per workspace.** Owners/admins (`integrations.manage`) enter the DataForSEO *API* login and password under *Integrations → DataForSEO*. They are verified with the free `GET /v3/appendix/user_data` call before being stored; the password is encrypted (`dataforseo_profiles.password`, context `dataforseo.password:{workspace_id}`) and never shown again. Checks are rate-limited (10 per 10 minutes per user). Disconnect deletes the credentials and keeps usage history and budgets.
- **One client.** Every call goes through `Modules\DataForSEO\Api\DataForSeoClient` (HTTP Basic auth, host allowlist `api.dataforseo.com`, or `sandbox.dataforseo.com` when `DATAFORSEO_SANDBOX=true`). Provider adapters must not call DataForSEO any other way.
- **Budgets and cost accounting** (`Domain\Integrations\UsageLedger`, `BudgetPolicy`). Before a paid request the client *reserves* its estimated cost: the workspace's `api_budgets` row is locked, today's and this month's spending (UTC; charged costs plus open reservations) is checked against the daily, monthly and per-request limits, and a `reserved` row is written to `api_usage`. A request over budget throws `BudgetExceededException` and is never sent. Afterwards the reservation is *settled* with the `cost` DataForSEO reports (or *released* when the request was certainly not sent). Transport failures after sending are charged at the estimate, because DataForSEO may have billed them. Reservations still open after an hour (crashed worker) are closed at their estimate by `dataforseo.cleanup`.
- **Error mapping** (`DataForSeoErrors`, response `status_code` and HTTP status): 401 / 401xx → credentials rejected (permanent; the profile is marked *needs new credentials* and further paid calls are refused until reconnected); 40202 / 429 → retryable rate limit (60 s default back-off); 402 / 402xx → billing problem (permanent); 5xx / 5xxxx → retryable; other 4xx → permanent. Task-level errors are checked per task with `DataForSeoErrors::taskError()`.
- **Raw responses** of paid calls are stored gzip-compressed in `provider_responses` for `DATAFORSEO_RAW_RESPONSE_DAYS` (default 7) and purged hourly.

### Keyword metrics and SERP (0.3, part 2)

- **Contracts.** `Domain\Keywords\KeywordMetricsProvider` (search volume, CPC, competition) and `Domain\Rankings\SerpProvider` (organic results as a `SerpSnapshot`, with SERP feature types and `positionOf($domain)`). DataForSEO implements both in `Modules\DataForSEO\Adapters`; nothing outside those adapters knows DataForSEO's field names.
- **Markets (0.44.3).** `Domain\Keywords\Markets` is the catalog, generated from the official lists:
  - countries: Google Ads geotargets of type Country or top-level Region, without Russia and Belarus;
  - languages: DataForSEO's Google SERP languages;
  - Google Ads languages;
  - DataForSEO Labs country and language pairs.

  Search volume sends `language_code` only for a Google Ads language and otherwise asks for the location alone. Labs calls are refused without a request for uncovered pairs, and send Norwegian as `nb`. SERP and local checks accept every catalog language.
- **Keyword metrics** use `POST /v3/keywords_data/google_ads/search_volume/live`: up to 1,000 keywords per request and one request per market (country + language), estimated at $0.075 per request. `competition_index` (0-100) is stored as competition, the `competition` label as level.
- **SERP** uses `POST /v3/serp/google/organic/live/advanced` (desktop or mobile, depth 10-100), estimated conservatively at $0.002 per 10 results. Not used by the UI yet; rank tracking (0.4) builds on it.
- **Updates** run in the `keywords.metrics` job (queue `analysis`, per-project lease lock, continuation when the cron run's time is used up). Only keywords without metrics or with metrics older than 30 days are requested. Budget stops and permanent provider errors fail the job without retries and are shown on the project's Keywords page; rate limits and server errors are retried.
- **Markets** are a small catalog of Google Ads location codes and languages in `KeywordLocale`; extend it as customers need more countries.

### Rank tracking (0.4, part 1)

- Uses `SerpProvider::organic()` with depth 100 on the device chosen for the project; the project's primary domain and its subdomains count as own results (`SerpSnapshot::resultFor()`).
- The `rankings.check` job (queue `rankings`, payload with the UTC day) checks keywords in batches of 20 with a per-project lease lock and continues in the next cron run when time is up. Budget stops and permanent errors end the day's check with an error on the Rankings page; the hourly scheduler tries again, and the budget check refuses without spending until the budget allows it.
- Each keyword has a history page (`/projects/{project}/rankings/{keyword}`): position chart, all checks of the last 12 months with URL, SERP features and top 3 domains, and the Search Console totals of the exact query (`SearchPerformanceReader::queryTotals()`).
- The Keywords page suggests untracked Search Console queries (top 50 by clicks over the last 28 days of imported data); adding them costs nothing until metrics or rankings are fetched.
- The Rankings page reads only stored snapshots and `ranking_daily`: visibility, average position, top 3/top 10 with the change against the check about 7 days earlier, a 90-day visibility chart, the 10 biggest winners and losers, and the keyword table (position, change, best, volume, URL, SERP features).

### Competitors (0.5, part 1)

- `Modules\Competitors\CompetitorTracker` implements `SerpObserver`: `RankChecker` passes it every SERP it fetched and calls it after each day aggregate, so competitor positions and `competitor_daily` are updated in the same run at no extra cost (ADR-025).
- Modules register their observers with `Modules\Rankings\SerpObservers` (competitors, AI Overviews); the rankings module works without any of them.

### AI Overviews (1.2)

- Rank checks request the SERP with `load_async_ai_overview`, so Google AI Overviews that load after the page arrive with their sources. DataForSEO may bill a small surcharge for this; the usage ledger records the cost DataForSEO reports.
- `DataForSeoSerp` turns the `ai_overview` item into `SerpSnapshot::$aiOverview`: up to 20 sources (its own references and those of its parts), in order, without repeats.
- `Modules\AiVisibility\AiOverviewTracker` (a `SerpObserver`) stores per keyword, device and day whether an AI Overview was shown and where the project's domain or its subdomains were cited, plus the sources of the latest check.
- *Project → AI visibility* shows the share of keywords with an AI Overview, how often the project is cited (with the change against the check about 7 days earlier), the keywords with their citations and cited competitors, and the most cited sources. It needs no extra feature; the data comes with rank tracking.
### Keyword research (1.3)

- `Domain\Research\ResearchProvider`, implemented by `Modules\DataForSEO\Adapters\DataForSeoLabs` (DataForSEO Labs, Google, live): `ranked_keywords` (up to 1,000, by estimated traffic), `domain_intersection` with `intersections: false` and the competitor as `target1` (the keyword gap, up to 500), `keyword_ideas` (up to 300 for up to 20 seeds), `competitors_domain` (20, without the project's own domain) and `domain_rank_overview` (one request per domain: live endpoints take one task per request).
- Cost estimate for the budget reservation: $0.012 per task plus $0.00012 per row; the settled cost is what DataForSEO reports.
- `Modules\Research`: *Keywords → Keyword research* queues `research.fetch` (queue `analysis`, feature `keyword_metrics`, `analysis.execute`, 30 lookups per project and hour, one queued lookup per kind and subject). The job stores the normalised rows in `research_results`; while a new lookup is queued the previous rows stay visible. Temporary provider errors are retried; the last attempt, budget stops and permanent errors are shown on the page.
- *Track selected keywords* adds rows through `KeywordService::add()` (`projects.edit`, keyword limit and locale of the lookup).

### AI assistant mentions (1.3)

- `Domain\Research\AiMentionsProvider`, implemented by `Modules\DataForSEO\Adapters\DataForSeoAiMentions` (DataForSEO AI Optimization → LLM Mentions, live): `target_metrics` (one request per domain and platform: total mentions and AI search volume) and `search_mentions` (25 questions per platform, most searched first, with the answer, the sources and the project's source rank). Platforms: `chat_gpt` (United States, English only) and `google` (AI Overviews and AI Mode, in the project's market).
- Cost estimate: $0.10 per request plus $0.001 per row; the settled cost is what DataForSEO reports.
- The lookups are research kinds `llm` and `llm_prompts` (migration `000031`), queued from *AI visibility → AI assistants* through `research.fetch` with the same rules as keyword research.

### Local SEO (1.3)

- `Domain\Local\LocalSearchProvider`, implemented by `Modules\DataForSEO\Adapters\DataForSeoLocal` (live): `serp/google/maps/live/advanced` with `location_coordinate` "lat,lng,{zoom}z" and depth 20 ($0.002 per 100 results), and `business_data/google/my_business_info/live` with `location_coordinate` "lat,lng,radius" (radius in metres from the zoom; $0.0054 per lookup).
- `Modules\Local\LocalService` finds the business in the Maps results by its Google ID (`cid`, learned from the first match or the profile), otherwise by the project's domain (or a subdomain), otherwise by the exact business name. The profile is looked up by `cid:{cid}` once known, otherwise by the name.
- `local.check` (queue `rankings`, feature `rank_tracking`, per-project lease lock) checks the keywords not checked today and then the profile, stops before the cron run's budget ends and continues. Temporary errors are retried; the last attempt, budget stops and permanent errors are shown on the page.

### Ranking grids and weekly AI mentions (1.3)

- A ranking grid places size × size points `spacing` metres apart around the business's location (north up, row by row from the north-west; longitude spacing corrected for the latitude) and runs one Maps query per point with the area's zoom. `local.grid` checks the missing points, so an interrupted grid continues where it stopped.
- The weekly AI mention check queues the same `llm` research lookup as *Check mentions* (domains: the project and its competitors, the project's keyword market). Every completed `llm` lookup also writes `ai_mention_history`.
- `SignalCollector` reads the latest completed site audit (errors and warnings only) for `SiteIssueRule`; `ReportBuilder` reads it for the report's site audit section.

### Backlinks (1.3)

- `Domain\Backlinks\BacklinksProvider`, implemented by `Modules\DataForSEO\Adapters\DataForSeoBacklinks` (DataForSEO Backlinks, live, `rank_scale` `one_hundred`, subdomains included): `summary` (one request per domain: rank, backlinks, referring domains, nofollow domains, spam score, broken backlinks), `referring_domains` (live links by rank; new and lost domains with a `first_seen`/`lost_date` filter on the last 30 days and `backlinks_status_type` `lost`), `anchors` and `domain_intersection` (the competitors as targets, the project excluded; the link gap).
- Cost estimate: $0.024 per request plus $0.000036 per row; the settled cost is what DataForSEO reports. Accounts need the Backlinks API activated; otherwise the lookup fails with DataForSEO's message.
- `Modules\Backlinks\BacklinksService` stores the lookups in `research_results` (kinds `bl_*`, `analysis.execute`, feature `backlinks`) and queues them as `backlinks.fetch`. Every completed `bl_profiles` lookup also writes `backlink_history`; the monthly check queues it every 30 days.
- **Page links:** `/v3/backlinks/backlinks/live` returns normalized source URL, target URL, anchor, domain rank, follow flag and first/lost dates. `bl_links` and `bl_lost_links` each store at most 1,000 rows; lost links are restricted to the last 30 days. These are samples, not complete counts. Pages and MCP read the samples without a provider call.
- **Weekly lost-link monitoring:** off by default, enabled with both `projects.edit` and `analysis.execute` on Backlinks, with an estimated combined cost shown. The daily scheduler queues lost links and lost referring domains every seven days after a successful link check. Existing ADR-030 alerts still only read stored lost domains. Fetch jobs hold a per-project/kind lease and reject foreign or deleted projects before spending.

### Spending forecast (1.3)

- `Domain\Integrations\SpendForecast` builds the top-bar spending widget (`Modules\DataForSEO\SpendWidget`) from `UsageLedger::dailySpend()`, the spending per UTC day and operation since the first day of last month.
- **Forecast:** this month's spending plus the average of the last 14 days for the days left in the month. A new account is averaged from its first spending day.
- **Status:** `warn` when the forecast or today's spending reaches 80 % of a limit; `over` when it reaches a limit, or when the account balance runs out before the end of the month.
- **Categories:** operations are grouped by prefix (`serp_`, `keyword_metrics`, `labs_`, `llm_`, `local_`, `backlinks_`).

## AI assistant (Claude or GPT, ADR-028)

- **GPT:** `Modules\Assistant\OpenAi\OpenAiModel` (Responses API `POST /v1/responses`, `store: false`, function tools with `strict: false`). The Claude-shaped history is translated per call: text becomes messages, `tool_use` becomes `function_call`, and `tool_result` becomes `function_call_output` (prefixed `Error:` when it failed). Cached input tokens are priced at the model's `cached` price. Errors: 401/403 means the key was refused; `insufficient_quota` means no credit; 429 and 5xx are temporary.
- **Choice and budget:** the model chosen determines the provider (`LanguageModels::providerOf()`). Spending is booked as provider `ai_assistant`, operation `assistant_chat.anthropic` or `assistant_chat.openai`.

- **Model:** `Domain\Assistant\LanguageModel`, implemented by `Modules\Assistant\Anthropic\AnthropicModel` (Messages API, `anthropic-version: 2023-06-01`, HTTP timeout `ASSISTANT_TIMEOUT`, default 60 s). The system prompt and the tool definitions are marked for prompt caching.
- **Models and prices:** in `config/assistant.php`, in USD per million tokens. Cache writes are priced at 1.25× input; cache reads use the configured model rate (Opus 5.5 uses 0.05×, Sonnet 5.5 and Haiku 4.5 use 0.1×). Anthropic reports tokens, not money, so the ledger books the cost calculated from the tokens.
- **Settings:** the provider, key, model and budgets are per workspace (provider `ai_assistant` in `api_budgets` and `api_usage`, operation `assistant_chat.{provider}`).
- **Claude workspace ID (optional):** a personal or service-account Claude key that can act in several Claude workspaces must name one per request. Without one, Claude refuses with "This API key is not scoped to a workspace … anthropic-workspace-id header". The settings page takes an optional `wrkspc_…` ID (Claude Console → Settings → Workspaces), stored in `assistant_settings.provider_workspace`. It is sent as `anthropic-workspace-id` on the key check, chat and digest calls, and only for Claude. That refusal is shown as advice to fill in the field. Workspace-scoped keys leave the field empty, and no header is sent.
- **Request flow:** `Modules\Assistant\AssistantService` makes one model call per browser request:
  - Before each call it reserves the estimated cost, then settles it with the reported tokens.
  - Tool calls run in-process with `McpAccess::forSession()`: every scope, limited to the projects the signed-in user can see.
  - When tools were used, the answer is `working`, and `assistant.js` asks for the next step (`POST /assistant/continue`).
  - `opportunity_set_status` becomes `pending` until `POST /assistant/confirm`. It then runs through `OpportunityService::setStatus()`, which checks the permission and writes the audit log.
  - A user message stops after 8 model calls.
- **Assistant-only tools:**
  - **`fix_plan_save`:** stores a per-page change list (CSV download; the chat shows it as a `file` message).
  - **`site_audit_start`:** a write that starts after the user confirms, like `opportunity_set_status`.
- **Ask AI on opportunities:** each opportunity has a button (`data-assistant-ask`) that opens the chat with a prepared question naming the opportunity's public ID.
- **Context:** the browser sends the page path. A project in it is used as context only when the user can see that project.
- **Endpoints:** `GET /assistant`, `POST /assistant/messages`, `/assistant/continue`, `/assistant/confirm`, `/assistant/new` (JSON; CSRF header `X-CSRF-Token`). The settings page is *Integrations → AI assistant* (`/integrations/assistant`, `integrations.manage`).

## Provider contracts

Potential interfaces include:

```text
KeywordMetricsProvider
SerpProvider
BacklinkProvider
DomainMetricsProvider
```

Domain/application code should consume normalized DTOs/value objects.

## Future integrations

Possible future modules:

- additional analytics providers beyond Google Analytics 4;
- Microsoft Clarity;
- alternate SERP/keyword providers;
- backlink providers;
- reporting/delivery integrations.

Adding a provider must not require redesigning core domain models.

## Page data integrations

### Core Web Vitals (0.9.33)

Responses must identify the requested PHONE URL (or explicitly document same-origin normalization), never an origin record. Truncated/malformed responses, invalid/future/reversed dates and entirely missing metrics are rejected. Individual missing metrics remain unavailable. Future collection dates do not feed opportunities.

Configure a Google Cloud key with the Chrome UX Report API enabled under **Integrations → Core Web Vitals** (`integrations.manage`); restrict the key to that API. From a known URL under **Pages**, an editor can request a free field-data check (`projects.edit`, feature `site_audit`). Jobs query `https://chromeuxreport.googleapis.com/v1/records:queryRecord` for PHONE URL-level LCP, INP and CLS p75; the key goes in the redacted `X-Goog-Api-Key` header. There is no silent origin fallback. Low-traffic pages may have no field data; that does not mean they are fast or slow. Each result includes its rolling collection dates. The audit displays these metrics separately from crawler timings. Opportunities consider available collection windows ending within the last 35 days, using LCP >2,500 ms, INP >200 ms or CLS >0.1.

References: [CrUX API](https://developer.chrome.com/docs/crux/api). Google quotas still apply. There is no automatic paid provider fallback or browser request to the audited URL.

### Google Analytics 4 (0.9.33)

Enable the Google Analytics Data API in Google Cloud and grant a service account **Viewer** access to the GA4 property. The owner pastes its JSON credential under **Integrations → Google Analytics 4** (`integrations.manage`); it is encrypted, never echoed, and can be disconnected with a blank form without deleting imported data. This is separate from Search Console OAuth, whose scopes are unchanged. Link a numeric property ID under **Project → Analytics**, then import manually or daily. Imports use the existing `search_console` feature; stored results remain readable.

The API reads `date`, `hostName`, `landingPagePlusQueryString`, `sessions`, `keyEvents` and `purchaseRevenue`, filtered to Organic Search and the project's exact bare/www hostnames. URLs reconstruct the reported host/path on HTTPS; they are not joined to click data by a guessed path. Conversions means GA4 key events and can be fractional under attribution. Revenue is purchase revenue in the property's currency, including negative adjustments; it is not lifetime customer value. Dates use the property's timezone (displayed in results). Daily imports refresh 28 completed date labels; manual imports support 28/90/180/365 days and retain older rows, with one report capped at 10,000 daily rows, not 10,000 unique pages. Row limits, other-row data loss and privacy thresholds are displayed. Per-date coverage includes successful empty days. Equal-length period comparisons require complete compatible coverage and no quality warning in either compared periods. Page details use their displayed period; printable/e-mail reports mark missing imported dates as partial.

Authentication signs an RS256 assertion in memory for `analytics.readonly` and sends it only to Google's fixed OAuth endpoint; reports go through the allowlisted shared HTTP client. No extra runtime SDK is needed. [Google quickstart](https://developers.google.com/analytics/devguides/reporting/data/v1/quickstart), [metric schema](https://developers.google.com/analytics/devguides/reporting/data/v1/api-schema).

### Additional local locations and review samples (0.9.33)

**Local → Locations and review texts** lists additional branches, their own keywords, latest 12 daily checks, profile rating/review counts and newest review samples. An editor adds each exact numeric Google Maps CID and its coordinates, language and keywords. Branch matching uses CID only, so a shared chain website cannot match the wrong store. Additional branches now support manual 3×3/5×5/7×7 ranking grids, 250–5,000 metre spacing, latest 12 runs and per-point resumable storage. Estimates are shown before paid execution; scheduled weekly branch checks remain point checks. Primary local settings, history and grids remain available.

### Project alert webhooks (Unreleased)

Notifications offers opt-in future project alerts through a Slack incoming webhook or a public HTTPS JSON endpoint. `integrations.manage` is required, with creator access rechecked at delivery. Optional bearer authentication is available for JSON. Destination URLs/tokens are encrypted, never redisplayed, and the last 20 delivery attempts contain only sanitized status metadata. Slack destinations are restricted to its incoming-webhook hosts and path. The generic event exposes `event`, `id`, `type`, `title`, `lines`, `url`, `created_at`, and `X-SCC-Event-ID` supports receiver deduplication. Changed configuration invalidates queued old-version deliveries. One attempt per alert avoids automatic duplicate messages after ambiguous failures; this is separate from personal email mutes and excludes budget alerts/digests. Setup: [Alerts guide](docs/guide/17-alerts-and-digest.md).

### Sampled audit resources (Unreleased)

Internal page discovery is unchanged; an independent resource frontier checks up to 15 HTML asset references and five external destinations per page, capped at 500 distinct targets per audit. HTTPS/public-address/robots guards apply to each host; reads are capped at 1 KB, and redirects are not followed. Missing alt attributes, mixed-content assets, confirmed 404/410 targets and temporary failures become page-scoped audit evidence. 403/429, redirects and excluded targets are not proven broken links. No browser rendering, CSS asset traversal, lazy-load discovery or JavaScript execution is added.

Paid checks require `analysis.execute` and `rank_tracking`; enabling weekly checks additionally requires `projects.edit`. Weekly checks are off by default, show the estimate, use the workspace budget, and do not fetch reviews automatically. Profile checks cost the existing estimate, $0.002 per keyword plus $0.0054 per profile (actual provider cost is settled). Branch settings allow label/keyword changes and stopping weekly checks without deleting history. CID, area and language are fixed for the branch's history; a different business or search area needs a new branch. Removing a branch explicitly removes its history.

Manual review imports are available once the primary has a discovered CID, or immediately for a configured branch. They submit `/v3/business_data/google/reviews/task_post` at normal priority for 100 newest reviews (estimate $0.0075), through the usage ledger. GET `/task_get/{saved task UUID}` is free and is polled every five minutes, up to 24 hours. No review lookup runs in a web request. A submission response lost in transit is not automatically resubmitted: inspect System → Jobs and usage before manually requesting another import. Samples include original review text when available, author, rating, timestamp and owner reply. All text is escaped; missing reviews are not proof of deletion. [Task API](https://docs.dataforseo.com/v3/business_data-google-reviews-task_post/), [pricing](https://dataforseo.com/pricing/business-data/google-reviews-api).

Zero mentions: `target_metrics/live` answers a target with no mentions with "20000 Ok." (still billed, about $0.10). In that response every `aggregated_metrics` breakdown is an empty list, `total_count` is 0, `items` is empty, and there is no `total` object (observed live on 2026-10-10). Only that complete empty shape is stored as 0 mentions and 0 AI search volume. A missing aggregate, a missing `total` next to non-empty breakdowns, or any malformed total is still rejected, because missing data is not zero.

Gemini/Perplexity mention tracking remains unavailable: DataForSEO's current LLM Mentions database exposes `chat_gpt` and `google` platforms. [Supported mention platforms](https://docs.dataforseo.com/v3/ai_optimization-llm_mentions-target_metrics-live/).

**AI answer checks (ADR-032).** `Domain\Research\AiAnswerSampler`, implemented by `Modules\DataForSEO\Adapters\DataForSeoAiAnswers`, sends up to 10 user prompts to `POST /v3/ai_optimization/gemini/llm_responses/live` and `/v3/ai_optimization/perplexity/llm_responses/live` ([Gemini](https://docs.dataforseo.com/v3/ai_optimization-gemini-llm_responses-live/), [Perplexity](https://docs.dataforseo.com/v3/ai_optimization-perplexity-llm_responses-live/)). The model comes from the free `GET .../llm_responses/models` list and must report `web_search_supported` (preferred `gemini-2.5-flash`, `sonar`); Gemini sets `web_search: true`, Perplexity Sonar searches by default. Answer text is read from `items[type=message].sections[].text`, sources from `annotations` (`direct_url` preferred over the Vertex redirect `url`; non-http(s) URLs dropped; up to 30). Cost: $0.0006 per task plus `money_spent`; reserved at $0.06 per answer and settled to DataForSEO's reported cost. Research kind `llm_answers`, one resumable step per platform and prompt. Results are sampled answers, not mention frequency, and are not used in history or scores. Since the ADR-032 amendment, a prompt that two platforms answer with the same competitor and without the project becomes a low-confidence prompt-gap opportunity.

**Opportunity intelligence (ADR-034).**
- `ResearchProvider::keywordSuggestions()` calls `POST /v3/dataforseo_labs/google/keyword_suggestions/live` (one seed per request, `include_seed_keyword`, up to 100 rows, by volume). Ranked keywords, the keyword gap, ideas and suggestions all send `include_serp_info: true`; the adapter keeps `serp_info.serp_item_types` and `avg_backlinks_info.referring_domains` when present.
- `Domain\Research\DomainStrengthProvider`, implemented by `Modules\DataForSEO\Adapters\DataForSeoDomainStrength`, calls `POST /v3/backlinks/bulk_ranks/live` with up to 1,000 targets and `rank_scale: one_hundred`. The reservation is $0.024 plus $0.0001 per domain, settled to the reported cost. Only requested domains are stored; a rank outside 0–100 fails the lookup.
- `Domain\Research\SeedSuggester`, implemented by `Modules\Assistant\AssistantSeedSuggester`, makes one tool-free model call in the research job, reserved against the AI budget (operation `seed_ideas.{provider}`). The output is cleaned by `SeedIdeas::clean()`.

## Site audit

Implemented in 1.2 (`app/Modules/SiteAudit`) and switched by `site_audit`. It crawls the project's own site; no provider or account is needed.

- **Start:** *Project → Site audit → Start audit* (`analysis.execute`), or weekly when a project editor switches it on. One audit per project runs at a time.
- **Sitemap:** the first crawl run reads the sitemaps that robots.txt lists on the site's hosts, otherwise `/sitemap.xml` (sitemap indexes up to 20 child sitemaps, `.gz` files, up to 20 MB each). Their URLs join the crawl as unlinked pages, up to 80 % of the page limit. A link found later gives them their click depth; pages no checked page links to are reported as *Not linked internally* when the audit did not hit its limit.
- **Crawl:** `site_audit.crawl` starts at `https://{project domain}/` and follows the links between pages, shallowest first, up to the project's page limit (100 to 2,000; default 500). Each cron run crawls until 40 seconds before its time budget ends, then queues a continuation. An audit whose crawl has not moved for 2 hours is marked failed.
- **Fetching:** `SiteCrawler` requests one page at a time with the user agent `SEO-Control-Center/{version}`. It:
  - only fetches the project's domain with and without `www.`, over HTTPS, without port numbers; `http://` links are reported, not fetched;
  - resolves every host first and refuses private or reserved addresses (`HostResolver`);
  - follows the robots.txt rules for `SEO-Control-Center`, otherwise for `*`;
  - records redirects (and queues their internal target) instead of following them;
  - reads at most 2 MB of HTML per page and does not follow links on pages with `nofollow` (meta tag or `X-Robots-Tag`);
  - follows paginated listings (`?page=`, `?p=`, `?paged=`, `?pg=`, `?pagenum=`) up to page 10.
- **Analysis:** when no page is left, `IssueDetector` checks the pages and stores the issues. Content checks apply to indexable HTML pages only. For duplicate titles and descriptions, the pages of one paginated series count as one page. The health score is 100 minus the share of checked addresses with an error (full weight) or with warnings only (half weight).
- **Results:** issues by type with the change since the previous completed audit, up to three pages that link to a broken page, all checked pages and a CSV export. The last 5 finished audits are kept.

## Brand-name matches in AI answers (Unreleased)

AI visibility → Brand-name mentions supports one public brand name (120 characters) per project, a separately chosen Google market/language and disabled-by-default weekly opt-in. ChatGPT always uses US/English. The existing DataForSEO LLM Mentions live endpoints receive `target=[{keyword: name, search_filter: include, search_scope: [answer], match_type: word_match}]`; full terms may contain additional words, so matching is not an exact-phrase guarantee. Four requests fetch counts and up to 25 answer examples per platform, estimated at $0.452; the existing ledger reserves/settles each request and enforces workspace budgets. Missing/invalid count metrics fail rather than becoming zero. Stored reads are free; no credentials or real paid calls were used during development.

Examples retain question, 300-character plain excerpt, provider response date, other sources and distinct project-domain source rank/URL. Name matches can include namesakes and overlap with domain citations; never sum these counts. Charts display the latest completed check per UTC day in the current configuration's latest 12 attempts. This is sampled ChatGPT/Google AI coverage, not general web/news/social monitoring; Gemini/Perplexity remain unavailable. See guide chapter 20 for costs, opt-in and interruption handling. No new dependency or ADR policy change; scheduled PDF attachments are deferred by the owner.

### Local opportunity value analysis

Opportunity analysis makes no GA4 or other provider request. It uses stored complete 28-day GA4 imports only when project linkage and workspace credentials remain present, and excludes thresholded/limited windows. Organic Search sessions, key events and revenue can inform relative page value; engagement and channel-attributed conversion rates are unavailable in the stored schema. Estimates explicitly state the one-click/one-session assumption and correlation limitation. Missing/stale/incomplete data falls back to clicks for new findings and preserves scores previously dependent on that source.
