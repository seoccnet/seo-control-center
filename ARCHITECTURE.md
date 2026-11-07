# Architecture

Forecast review is documented in [docs/forecast-review.md](docs/forecast-review.md). ForecastingModule registers a pure server engine, forward calibration, workspace/project-scoped repository and controller after Overview. Explicit ProjectsEdit/CSRF-protected capture stores immutable issue-date predictions and enables pausable weekly tracking; GET operations only read and score saved outcomes. Bounded hourly dispatch resolves active projects, saves actual outcomes daily and issues all three horizons weekly through the existing queue. Three additive tables retain predictions, accepted actuals, tracking state and calendar knowledge dates. Source bindings prevent cross-property calibration, and missing query/page dates are never inferred zero. No provider calls or production Node runtime are involved.

Project Overview's persisted evidence dashboard is documented in [docs/overview-dashboard.md](docs/overview-dashboard.md). `OverviewEvidence` receives repository/read services through an explicit factory after project authorization. `OverviewCalculations` contains pure comparison/scoring operations; views contain no SQL. Completed audit reads do not change audit lifecycle state, and the page performs no provider calls. Source coverage and bounded samples govern comparison eligibility.

## Page monitoring (Unreleased)

PageMonitoringModule follows SiteAudit and reuses SiteCrawler for robots-aware bounded HTTPS checks. Project editors select up to 50 exact-domain URLs. The repository locks the project row for capacity and the watch row for atomic baseline/change persistence. Watch generations reject stopped/re-added work; successful job IDs suppress immediate replays. Hourly lease-protected dispatch queues up to 50 due URLs installation-wide; each check has a per-URL lease and validates current project identity/domain before fetching and saving. Failed or truncated responses never replace a complete snapshot. Snapshot comparisons avoid treating non-HTML metadata as removed. Two additive tables hold current watches and the latest 500 changes per project.

PageChangeReader supplies significant persisted changes to the existing alert detector, which uses immutable change fingerprints and existing delivery/access preferences. Alert links resolve the scoped change directly rather than relying on the first history page. Active-project/workspace joins prevent dispatch or successful evidence persistence after soft deletion. Explicit annotation creation locks the change, uses AnnotationService authorization/validation/audit, reuses a live linked annotation and redirects to ChangeImpactService's existing report. Detection dates represent observation bounds, not exact edit times. No new provider, runtime dependency, website mutation or AI write permission.

## SEO team tasks (Unreleased)

The Opportunities module registers a separate TaskRepository, TaskService and controller. One saved task belongs to one finding; analysis and finding status writes never update task metadata. Project/workspace-scoped PDO and in-memory adapters support filters and 25-row pagination. TaskService reuses active membership, Authorizer, BriefRepository and AnnotationRepository to validate assignment and project-owned links. HTTP and read-only seo_tasks_list share the service; no provider, job, notification, dependency or ADR policy change.


## Google indexing monitoring (Unreleased)

IndexingModule follows SearchConsole and registers a fixed-host Google URL Inspection adapter using GoogleAccessTokens, a curated evidence DTO, project/property-bound repository, service, controller, queue handlers and hourly watchlist dispatcher. It uses the existing SearchConsole feature entitlement. Manual requests and watch changes require ProjectsEdit; authorized viewers read saved evidence. PageService and indexing_status MCP/Ask AI reuse the read service and never make API calls. Newly inspected URLs join the Pages read catalog.

At most 500 exact URLs and 50 weekly watches per project are tracked. The repository serializes capacity checks using the live project row; successful evidence and failed-attempt state remain separate. A connection/property digest binds records and job payloads; jobs recheck ownership, active connection, property and URL before the call and before saving. One lease-protected job performs one inspection attempt. The bounded change-event list feeds the existing alert detector and delivery preferences; first results and failed checks create no change alerts.

## SEO change impact (Unreleased)

Annotations' ChangeImpactService reuses authorized AnnotationService, SearchPerformanceReader, PageRepository, Ga4Repository and Clock. Equal bounded windows exclude the change day; incomplete/future windows receive no comparison. Google status describes clicks/impressions direction. GA4 quality and current currency/timezone guards apply independently. HTTP and MCP share the service. No new persistence, migration, provider, job or dependency; reports recalculate from current evidence.

## Content briefs (Unreleased)

`Modules\ContentBriefs` registers after Pages and before MCP. BriefBuilder reuses PageService, KeywordRepository, RankingRepository and OpportunityRepository through explicit factories. It captures bounded local evidence with dates; it never calls a provider, crawls competitor content or invokes a model. BriefService owns validation, project-edit authorization, revision checks and identifier-only audit events. Controllers resolve the authorized Project first and contain no SQL.

BriefRepository has scoped PDO and in-memory adapters. Create captures evidence on the server; later edits retain it. Atomic updates/removals match the expected revision, returning conflict rather than overwriting newer work. The conflict form preserves submitted text. Project navigation and contextual links expose the workflow; text/print exports reuse the saved record. Published is a workflow label, with an explicit link to create a prefilled annotation. MCP/Ask AI read saved briefs under pages:read; there is no AI write tool or new provider exception.

## Quest Log (0.13.0)

`Modules\Quests` registers after every module whose state its quests read (after Alerts, before Dashboard and Admin) and replaces Get started (ADR-035). Quest definitions are a code catalogue in `Domain\Quests`; `QuestLogService` evaluates visibility (`QuestAccess`: FeatureManager, Authorizer, assistant availability), Foundations and learning progress. Foundations reads `Setup\FoundationsChecks` (the former `GettingStarted`, one read-only check per step) live for the selected project. Learning steps ask `QuestEvidence`, a composite of module-owned readers (`Modules\<X>\Persistence\Pdo<X>QuestEvidence`, `SearchConsoleQuestEvidence`) that run indexed existence queries on their own tables; Quests never touches another module's repositories. There is no event bus: checks run on view and in the hourly `quests.recheck` job. Dashboard and the project overview know nothing about Quests; the card is a `LayoutWidget` in the `dashboard` and `project-overview` slots.

## Architectural style

SEO Control Center is a modular monolith optimized for self-hosted shared hosting.

The system is intentionally not a microservice architecture.

## High-level flow

```text
External sources
Google Search Console / DataForSEO / future providers
                    ↓
              Provider adapters
                    ↓
               Normalization
                    ↓
              Internal SEO model
                    ↓
            Analysis / aggregation
                    ↓
     Dashboard / Reports / MCP / API
```

## Dependency rule

Infrastructure may depend on domain contracts.
The domain must not depend on a specific external provider implementation.

```text
HTTP controllers ─┐
CLI commands      ├──> Application services ──> Domain
Cron jobs         │                                  ↓
MCP tools         ┘                         Repository/provider interfaces
                                                     ↓
                                             Infrastructure adapters
```

## Kernel components

The HTTP kernel is application-owned (ADR-016): an in-house PSR-11 container with explicit factories, an in-house PSR-15 middleware pipeline, PSR-7 messages from `nyholm/psr7`, and routing through a `Router` interface currently backed by `nikic/fast-route`.

## HTTP lifecycle

```text
Request
→ Bootstrap
→ Request ID
→ Security headers
→ Session
→ CSRF when required
→ Authentication
→ Rate limiting
→ Route match
→ Authorization
→ Validation
→ Controller
→ Application service
→ Repository/provider
→ Response
```

## Async lifecycle

```text
User action / scheduler / webhook
→ create idempotent job
→ persist job
→ HTTP returns
→ cron acquires scheduler lease lock, enqueues due jobs, releases it
→ worker takes a worker slot and claims a job with SKIP LOCKED
→ execute bounded unit of work
→ persist results
→ dispatch domain/application event
→ aggregate/analyze as needed
→ complete/retry/fail
```

## Data strategy

The application owns a normalized internal model.

Do not mirror provider payloads as the canonical schema.

Historical SEO data is append/snapshot oriented.

Examples:

- ranking snapshots;
- keyword metric snapshots;
- search performance daily rows;
- competitor visibility snapshots.

Dashboard-heavy metrics should be pre-aggregated where raw queries would be expensive.

## Event strategy

Use internal events to reduce module coupling.

Example:

```text
SearchConsoleImported
→ OpportunityRecalculationListener
→ queue AnalyzeProjectOpportunities job
```

The first event bus may be synchronous. Expensive listeners should queue work.

## Tenancy and authorization

Roles are per workspace; project membership restricts non-admin roles; project roles may narrow permissions only (ADR-013). All checks go through one authorization service.

## Cache strategy

Required cache interface with a filesystem implementation as the default.

Optional future adapters:

- database cache;
- Redis cache.

The product must not require Redis.

## Page evidence and integrations

### Page and landing-page read models (Unreleased)

`Modules\Pages` combines stored URLs and evidence from Search Console, Bing, rank checks, AI Overviews, the latest completed audit, backlink samples and GA4. It does not fetch providers when a browser or MCP client reads a page. Joint Search Console query/page rows are separate from marginal page totals. Cannibalization is a possible competition signal (at least 200 query impressions, at least two pages with 50 impressions and 10% share in the top 20), not a declaration that one page must be removed. Internal link suggestions use crawl inbound counts, relevant titles/H1s and existing edges; they exclude nonindexable or alternate-canonical pages.

`Modules\WebVitals` fetches URL-level CrUX PHONE p75 metrics through a bounded job. `Modules\Analytics` fetches GA4 Organic Search landing-page data through read-only service-account authentication. Both store normalized results; source dates, missing data and limits remain visible.

Additional local branches use `LocationService` and additive tables instead of changing the legacy primary location's ownership or history. A branch check persists one keyword per job step and uses exact CID matching. Reviews separate a single paid task submission from free result polling; an ambiguous submission failure requires a manual retry after checking usage.

## Failure isolation

Updates are uploaded by an administrator and verified with `ReleaseManifest` and `ReleasePackage` before `UpdateInstaller` applies them. Nothing checks a vendor server (ADR-052).

### Shared interface (0.9.36 and subsequent polish)

`layouts/app` loads `workspace-ui.css` after the baseline stylesheet and before branding overrides. It uses the existing theme tokens; no frontend dependency or build step is added. The project-header partial renders five native disclosure groups, all expanded without JavaScript. The shell module progressively enables mobile workspace navigation and collapses inactive project groups on narrow screens.

`table-controls.js` wraps data tables in keyboard-scrollable regions and adds local filtering, sorting and pagination to uniform read-only tables with at least eight rows. Labels explicitly say **loaded rows**; these controls do not request or search unloaded server records. Tables containing selection checkboxes are excluded so hidden selected rows cannot change existing bulk-action behavior. `data-no-table-controls` opts out when a table has authoritative server pagination, as Pages does. Server-side Pages search and sorting are implemented through PageService/PageRepository, with prepared literal search parameters and a fixed sort whitelist.

`section-help.js` adds native disclosure help beside known section headings using a fixed, escaped-by-construction text dictionary. It skips headings inside another summary and visually hidden headings. `integration-guide` is a server-rendered reusable partial on integration forms; tutorials and chart data remain readable without JavaScript. Line charts retain the existing SVG geometry and keyboard inspection; summary values describe the displayed series, not an additional provider measurement. Branding, access checks, CSRF and paid-job workflows remain unchanged.

Bordered table insets explicitly override baseline first/last-column spacing; URL cells retain their truncation constraints. Performance columns and local ranking grid tracks shrink within their cards on narrow screens. Keyword entry and suggestions use native disclosures, with entry expanded for first use or validation errors. Table regions are named after their section, and help lookup accepts plain heading text alongside provider badges.

Provider failures do not make the core application unavailable.

### Completing feature gaps (Unreleased, 2026-10-05)

Alerts now load validated project thresholds from AlertRepository. WebhookService is a separate delivery adapter behind WebhookRepository and NotificationScheduler: scans queue identifiers only, and the worker decrypts the current configuration, rechecks its creator's access and claims the event before sending. Personal email preferences and the ADR-030 fingerprint policy are unchanged.

GA4 history uses per-day import manifests rather than a replace-all snapshot. Current and immediately preceding equal-length periods are compared only with complete compatible coverage and no quality warning in either compared period. Page details read the page's displayed period. Provider calls remain queued and bounded.

Audit page discovery remains internal. Sampled references enter a separate, capped frontier; resource issues are aggregated by page/type before the existing score, issue persistence and downstream readers. Cancellation is checked between resource requests. Additional branch grids reuse LocalGrid geometry and paid provider accounting with exact CID matching; each continuation is bound to the grid UUID and stored points are not looked up again.

ReportCustomization resolves workspace overrides against installation branding without a shared tenant cache. Per-project ReportSections controls printable, HTML and text email rendering; raw stored data and report builders remain available to existing readers. Scoped stylesheet/logo routes authorize the project before returning workspace assets. No PDF dependency, frontend build step or new runtime package is introduced.

Every integration must have clear connection/sync status and retry behavior.

## SEO change annotations (0.9.41)

`Modules\Annotations` registers immediately after Projects. Controllers call AnnotationService, which checks project visibility and `ProjectsEdit` for mutations, then a Project-scoped repository. PDO and in-memory implementations bind workspace/project. Mutations require CSRF and audit events.

Overview, SearchConsole, Bing, Rankings and Pages receive the service through explicit factories. Context reads are bounded to existing reporting dates; metrics and jobs are unchanged. LineChart maps change dates onto its point-index scale, interpolating sparse dates only for marker placement. Core geometry does not depend on the module. Shared views show one marker per date and an accessible note disclosure. MCP/Ask AI expose read context only, without new scopes, provider calls or background tasks.

## Opportunity engine (0.6)

`Modules\Opportunities\SignalCollector` reads stored data only: the last 28 days of Search Console data and the 28 days before, the latest rank check and the one about 7 days earlier, and competitor gaps. It builds `Domain\Opportunities\ProjectSignals`. Pure rules in `Domain\Opportunities\Rules` turn the signals into `Opportunity` objects with evidence. `OpportunityRepository::sync()` stores them and keeps the workflow status.

| Rule | Detects | Confidence | Effort |
|---|---|---|---|
| Quick win | tracked keyword at position 4–20, or untracked query at average position 4–20 with ≥ 100 impressions; not when the low-CTR rule reports the query (a snippet problem, not a ranking problem) | 80 / 60 | low (≤ 10), medium |
| Low CTR | query or page on page one with ≥ 200 impressions and a CTR below half the expected CTR for its position | 65 | low |
| Ranking decline | tracked keyword in the top 20 that lost ≥ 5 positions (or left the results) since the comparison check | 75 | medium |
| Traffic decline | page with ≥ 20 clicks before and ≤ 70 % of them now (pages in both windows only) | 70 | medium |
| Competitor gap | competitor on page one and ≥ 10 places ahead (ADR-025) | 65 | high if you do not rank, else medium |
| Content gap | query with ≥ 100 impressions at average position > 20 | 50 | high |

Impact is the estimated monthly clicks at stake, on a log scale: `25 × log10(1 + clicks)`, capped at 100. The expected CTR comes from the same curve as visibility (`Domain\Rankings\Visibility`). Priority is `impact × confidence / 100 / effort`. The thresholds are constants on each rule class. Change them together with the tests and this table.

## Brand-name AI mentions (Unreleased)

The Research module owns `BrandMentionService`, `BrandMentionRepository` and `BrandMentionScheduler`. AI visibility controllers resolve an authorized project before saving settings or requesting checks; the existing MCP `ai_visibility` tool reads saved results only. Explicit container factories bind the PDO repository and queue adapter. The existing `AiMentionsProvider` / DataForSEO adapter adds answer-text word targets; domain-based checks remain separate.

A check is four persisted steps (ChatGPT count/examples, Google count/examples), one paid request per job. Before sending, a scoped conditional update claims the step. Each subsequent step rechecks current settings, active requesting user and project permission, with fresh membership decisions. Changing the name, market or project domain selects a SHA-256-keyed history series. The interface and MCP read the latest 12 attempts, show only completed results as the main snapshot and never sum overlapping name/domain counts. History rows in MCP omit duplicate answer examples; only the latest completed result includes them.

## Opportunity scoring

Opportunities use pure rules over bounded local snapshots. The source coverage map is persisted with each run and exposed in the UI and MCP. Findings retain required source names in evidence. Missing, future-dated or stale data preserves existing status and scores; only fresh observations may resolve a finding. Legacy merged search findings conservatively require both Google and Bing. See docs/opportunity-scoring.md for policies and remaining calibration work.

Action plans (ADR-031) are a separate path that never touches scoring. `Domain\Opportunities\PlaybookLibrary` builds a `Playbook` from a finding's type, stored subject and evidence; `SeoGuardrails` holds the shared rules used by playbooks, content briefs and the assistant instructions. `OpportunityService` adds the plan to the finding detail, page plans and (via `TasksController`) the task page, and to their JSON and the MCP detail call.

### Opportunity confidence model

ConfidenceReader supplies bounded daily query/page observations through PDO or an in-memory fake. Pure ConfidenceModel indexes the snapshot once and scores every rule finding before sync. It records the numerical contribution of sample size, daily position SD, cadence-relative freshness, source agreement and known/unknown research metrics. Sources consumed by confidence are added to the stored dependency footprint. Detection rules leave confidence at zero until this common scoring stage. No provider calls or runtime dependencies are introduced.

### Opportunity value model

SignalCollector reads bounded landing-page aggregates through Ga4Repository and joint query/page evidence through PageRepository. Domain ValueModel indexes paths and query associations once and weights raw monthly click estimates relative to project-wide value/session. Domain Intent classifies explicit English patterns, leaving ambiguous queries neutral. Rules expose nullable raw click estimates; the analyzer applies value and confidence before repository sync and records any additional consumed-source requirements. Existing tenant-authorized entry points and cron schedule are retained.

### Opportunity trend reads

TrendReader returns top-500 impression-ranked query/page samples, their three 28-day page windows, project name and optional prior-year monthly context. PDO aggregates periods in bulk and reads only marked rollups with a complete 13-month sequence. DeclineModel remains pure and explains significance/seasonal policy. Sample notices are stored in existing coverage JSON; sync cannot resolve absent query/page findings while the relevant sample is truncated. This introduces no schema migration or provider work.

## Opportunity evidence pipeline

Stored sources are collected once through explicit repository interfaces into ProjectSignals, including bounded confidence, trend, panel and outcome snapshots. Pure domain models calculate CTR, confidence, value, effort, dependencies and outcome calibration; each detection retains a required-source footprint. Persistence protects missing/stale sources and sample/subject boundaries. Canonical consolidation is a read projection retaining original UUIDs/hashes/history. Scoped detail/page/weekly/outcome views and the read-only MCP tool share application services. Completion captures nullable baselines, and a bounded local job measures 28/56-day outcomes. Forward-only additive migrations carry structured estimates, outcomes and snooze/history enum additions.
