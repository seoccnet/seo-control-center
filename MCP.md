# MCP Design

## Writable MCP and data quality (0.16.0, ADR-036)

MCP is no longer read-only. Five write scopes exist: `annotations:write`, `tasks:write`, `briefs:write`, `opportunities:write` and `watches:write`. Existing tokens never gain them. The token form and the OAuth consent page list them separately and unticked; the in-app assistant's session access (`McpAccess::forSession`) gets read scopes only, so Ask AI keeps its confirm-in-chat writes.

| Tool | Scope | Service | Concurrency |
|---|---|---|---|
| `annotation_create`, `annotation_update` | annotations:write | AnnotationService (0.49.0: `annotation_create` with a `url` also drafts a measurement plan and freezes its baseline through MeasurementPlanService, unless `measure: false`; it returns `measurement_plan`) | `expected_version` (new `seo_annotations.revision`) |
| `seo_task_save` | tasks:write | TaskService | `expected_version` (0 creates) |
| `content_brief_create`, `content_brief_update` | briefs:write | BriefService (create prefills from `BriefBuilder` like the form) | `expected_version` (brief revision) |
| `opportunity_set_status` (open, done, dismissed, snoozed + `snooze_until`) | opportunities:write | OpportunityService | `expected_status` (no revision column) |
| `page_watch_set` (1–50 URLs, enabled true/false) | watches:write | PageMonitoringService | per-URL result; workspace watch limit (default 50, since 0.23.0) |
| `opportunity_outcome_confirm` (implemented true/false) | opportunities:write | OpportunityService | `expected_implementation` (since 0.23.0) |

Rules for every write tool (`Server\McpWriteTool`):

- Two checks: the token's scope and the creator's current permission (`projects.edit`) inside the service. Project visibility goes through `ToolContext` as for reads; foreign projects and foreign record IDs answer "not found".
- Update tools merge the given fields onto the current record. A version mismatch answers `Conflict: …` with the current version and never overwrites; briefs and tasks get neutral MCP wording instead of the browser form's (`WriteCall::run` `$conflict`). Other 409 answers, such as the 50-watch limit, keep the service's message without the `Conflict:` prefix.
- `client_request_id` (8–64 characters) makes a write idempotent per token and tool for 24 hours (`mcp_write_requests`). A retry returns the first result with `replayed: true`; the same id with different arguments is rejected; a failed write releases its id; an unfinished claim is forgotten after 10 minutes.
- At most 20 writes per minute per token (`McpServer::WRITES_PER_MINUTE`), counted inside the existing 60 calls per minute.
- No provider or paid call, no hard delete. Stopping a page watch keeps its change history.
- Audit: the service's own event with actor type `mcp`, the token name as actor label and `via` in metadata, plus `mcp.tool_called` with `write: true` and `replayed`. Written text is never stored in audit metadata.
- `via` (token name) is stored on notes, briefs and tasks and shown in the UI ("Last changed via …"). A later browser edit clears it. Opportunity status changes and watches carry `via` in the audit log only.
- `tools/list` marks write tools `readOnlyHint: false`, `destructiveHint: false`.

`data_quality` (projects:read) lists every dataset the opportunity engine knows (`SignalCollector` coverage) as available, stale, partial (sampled) or missing, with last date (null when none), a label, group and a note on how the source behaves and interpretation rules (missing is not zero; partial is not exhaustive; Google and Bing positions or GA4 sessions and clicks are not interchangeable). Optional `group` filter; `context` holds the timeline, page monitoring, alerts and outcomes. It reads stored data only and is phase B's starting point for the Data quality page (the research workflows plan).

**Speed tests (0.26.0, ADR-040 F2):** `lab_tests` (pages:read) lists the project's saved profiles: profile_id, url, device, source (`pagespeed` or `dataforseo`), paid, weekly, next_run_at. Each has its latest 8 `runs`, newest first (run_id, status, trigger, created and finished times, `result` with score 0–100 and lcp_ms, cls, tbt_ms, fcp_ms, speed_index_ms, lighthouse_version, plus error and cost), and `field`, the CrUX record of the same page and device. Lab and field values are kept apart. Since 0.38.0, each finished run with an older finished run after it has `comparable_with_previous` and `not_comparable_because` (another Lighthouse major version, throttling or screen). Each profile has `diagnostics` for its latest finished run: `lcp` (element, snippet, resource, phases, and since 0.38.1 `checks` from the Lighthouse 13 request discovery insight), `render_blocking`, `diagnostics` (audits below 0.9 with estimated savings), `also_flagged` (0.38.1: titles without a saving or value), `resources`, `largest_requests`, `config` and `report` (SHA-256 and bytes; the report itself is not stored), or null for runs before 0.38.0. Read-only: profiles and runs are managed in the app.

**Article ideas (0.27.0, ADR-041 G1):** `article_ideas` (keywords:read) lists up to 50 ideas, best first, without hidden ones. Each has idea_key, kind (`new` or `update`), main_keyword, volume, keywords (keyword, volume, difficulty), competitors (domain, found, url, position, traffic, keywords), your_page, `prediction` (traffic low/expected/high, difficulty level with reasons, backlinks low/high with basis or null, months low/high with basis), score and reasons. Also the planned competitors, market, last lookup and built_at. Read-only: the paid lookup is started in the app. Since 0.28.0 each idea has `outline`: null until read in the app, otherwise status, requested and fetched times, subtopics (topic, competitors = article urls covering it, own = already on your page for an update), questions, pages (url, state, reason, words, H2/H3 headings) and your_page.

**Topic plans (0.29.0, ADR-041 G2):** `topic_plans` (keywords:read) lists the project's plans, newest first:
- plan_id;
- pillar (idea_key, main_keyword);
- members (type idea/search/page, key, label, volume, kept, url);
- links (internal from/to/anchor, pages from your crawled pages with paragraph, audit, status, built_at), or null until built;
- backlinks (status, checked_at, pages with referring_domains/backlinks/rank, referring_domains_to_match, cost), or null until bought in the app.

Read-only. 64 tools.

**What worked (0.45.0):** `what_worked` (pages:read) is the results ledger of the project's SEO changes with a measurement plan.
- `by_kind` and `by_plan` give, per group: measured, improved, declined, unchanged, insufficient, open, controlled, and median effect (percent or positions; better is positive).
- `changes` lists every result, newest first, at most 60.
- `reminders` lists plans whose baseline is not frozen, and recent page changes without a plan.

Read-only. 65 tools.

**Overlap review (0.36.0, ADR-042):** `overlap_review` (pages:read) lists overlap candidates, strongest first. Kinds:
- `near_duplicate`: body text mostly the same, template text excluded;
- `shared_query`: several pages for the same searches, from Topics & owners; often fine, and harmful mainly when the leading page switches;
- `synonym_ideas`: article ideas on the same subject in other words;
- `split_archives`: topic archives on one subject.

Each candidate has members, evidence, uncertainty, `distinct_purpose` (different page kinds or purposes), a merge preview for pages (state, 28-day clicks and impressions, redirect or canonical target, topics owned, proposed retained address with reason) and the person's `review`. Nothing is merged automatically. `counts` are every candidate per kind; at most 100 per kind are listed (0.36.1). Filter by `kind`; paging is `offset`/`limit`.

**Internal-link classes (0.35.0, ADR-042):** `internal_links` gives each link a `class` and a `confidence`:
- classes: `editorial` (in the running text), `related` (related-article card), `product` (product or cross-sell card), `template` (menu, header, footer, sidebar, breadcrumbs), `other`;
- confidence: `high`, `medium`, `low`.

For audits crawled before 0.35.0, `class` is null; the counts group those body links as "Not classified" (0.35.1).

`links_in_by_class` and `links_out_by_class` count them, with a template link repeated on many pages counted once. Suggestions add `left_out_other_intent`: matches dropped because the paragraph is about another purpose than the target. They also add `coverage`: crawl size and date, and the limits suggestions are built under.

**Brief claims (0.34.0, ADR-042):** `brief_claims` (pages:read) lists the factual claims recorded on content briefs, newest change first. Each claim has its wording, the content it appears in, `claim_scope` and `source_scope`, the source (address, publisher, date checked, passage), the person's verdict `status`, the note and the version, plus automated `flags`:
- `no_source`;
- `own_site` and `competitor` (not independent evidence);
- `breeder_mismatch`, `cultivar_mismatch`, `version_mismatch`, `market_mismatch`, `sample_mismatch`;
- `source_scope_unknown`;
- `no_passage`;
- `stale`.

Flags never change a verdict. Filter by `status`; paging is `offset`/`limit`.

**Content inventory (0.33.0, ADR-042):** `content_inventory` (pages:read) lists one row per page address, plus CMS drafts or plans without one. Each row has:
- a state: live, noindex, canonical elsewhere, redirect, retired, error, seen by Google, sitemap only, published or scheduled in the CMS, draft, planned;
- every source, each with its own status, detail and date: CMS (CSV or manual), crawl, sitemap, Search Console, brief, published from an idea, topic plan, topic owner;
- `candidates`: unconfirmed possible matches for records without an address;
- `coverage` per source.

Filters are `state`, `source` and `q`; paging is `offset`/`limit` with `next_offset`.

**Article ideas across markets (1.1.0):** `article_ideas` returns `markets`: the markets a lookup covers, each with `market` (location:language key), `label` and `cost` (estimated USD for all competitors in it). `market_source` is `profile` (business profile), `keywords` (the tracked keywords' markets, most keywords first) or `default`; `skipped_markets` lists markets DataForSEO Labs has no data for (market, label, reason), which are never bought; `estimated_cost` is competitors × markets × the price per lookup; `last_lookup.markets` lists the markets the last lookup bought. The top-level `market` is kept for compatibility, as the labels joined. Each idea has `market` (its label, or several joined) and `markets` (market, label, volume, searches, traffic low/expected/high, difficulty, competitors). An idea in several markets is the same search in the same language: its volume and `prediction.traffic` add the markets up, `keywords[].volumes` gives each search's split, and competitors carry their `market`. Ideas of different languages are never joined. Each `published` article has `markets`.

`article_ideas` adds `inventory` per new-article idea: inventory rows that already cover its main search, such as a draft or a page without rankings.

**Business measurement (0.32.0, ADR-042):** `business_measurement` (pages:read) reports, over the last 28 complete GA4 days, the overall state and the state of each business event:
- `not_configured`: no business event defined.
- `not_observed`: GA4 recorded none over fully imported days. This is measurement uncertainty, never zero business value.
- `observed`: with the site-wide `total` and the `organic` count.
- `failed`: the last event import failed; the reason holds the error.
- `unknown`: GA4 not connected or linked, or days missing.

Each event has a reason. The tool also returns GA4 organic sessions next to Search Console clicks over the imported days of the same dates (`partial` when Search Console lacks the last days; null when none is imported), the owner's test checks and the measurement limitations. Defining events and running checks happen in the app.

**Prediction check (0.30.0, ADR-041 G3):** `article_ideas` adds `published`: articles marked as published from ideas (or from a brief saved as published). Each has:
- tracking_id, idea_key, main_keyword, searches, kind, difficulty, url, published_on, source;
- predicted: clicks_per_month (low/expected/high) and months_to_rank;
- measured: checked_at, clicks_per_month from the last 4 weeks, best_position, top10_week, months_to_top10, months_since_published and weekly rows; null until the first daily check.

**Internal links (0.25.0, ADR-040 F1):** `internal_links` (pages:read), from the newest completed site audit. With `url`: `links_in` and `links_out` of that crawled page, each with url, anchor, place (body, nav, header, footer, aside; null for a redirect) and, for body links, the paragraph (first 200 each way, body links first), plus `found`. Without `url`: `suggestions` (source_url, target_url, topic, cluster_id, anchor, paragraph, score), 25 per page with `offset`/`next_offset`, filter `q` on topic or page address, `total`. `link_text_recorded` is false for an audit crawled before 0.25.0 (places then null, no suggestions); suggestions also report `audit_running` (0.25.1) and `suggestions_built_at` (0.25.2; null until the background build has run). Since 0.25.2 only body-text links count as already linked. Reads `site_audit_links`, `site_audit_link_contexts` and `link_suggestions` only. 56 tools.

**Verified outcomes (0.23.0, ADR-039 E2):** `opportunity_outcomes` (opportunities:read) returns the latest 50 checkpoints of completed opportunities (outcome_id, opportunity_id, type, subject, page_url, completed_on, checkpoint_days, predicted and observed figures, `implementation`, `implementation_source`, `implementation_at`, `overlaps` with kind note/completion/page_change, label, date and ref). `opportunity_outcome_confirm` (opportunities:write) sets `implemented` true (confirmed) or false (not_implemented) through `OpportunityService::confirmOutcome`, audited as `opportunities.outcome_confirmed`; `expected_implementation` from the latest read guards concurrent changes. `page_watch_set` uses the workspace's watch limit and its description says so. 55 tools.

**Results-page changes (0.22.0, ADR-039 E1):** `serp_changes` (rankings:read) lists the stored change events of the project's tracked device, newest first, 50 per page (`offset`), each with keyword, keyword_id, checked_on, compared_with, kind, subject, detail and a plain `summary`; filters `kind` (a kind or the groups features, competitors, yours), `q` (keyword), `subject` (domain, URL or feature), `from`, `to`. Also `counts_30_days` per kind and `competitors_30_days` (new_urls, entered, left per tracked competitor). Reads `serp_events` only.

**Investigations (0.21.0, ADR-038 D3):** `investigations_list` (keywords:read; optional `status`, `offset`, 50 per page, newest change first, `counts` per item kind, `version`) and `investigation_get` (with `entries`: item_id, kind, ref_type, ref_id, label, detail, observed_on, via, created_at). Under the opt-in scope `investigations:write`: `investigation_save` creates (no `investigation_id`; `client_request_id` for idempotency) or merges the passed fields onto an existing one at `expected_version` (title, question, status open/concluded/archived, conclusion; concluded needs a conclusion); `investigation_add` appends 1–30 `items` or, with `analysis_id`, a finished analysis as entries (`AnalysisEvidence`). Both go through `InvestigationService` (`projects.edit`, audit `project.investigation_*` with `via`). Nothing is bought; starting a paid analysis stays outside MCP.

**Research on file and score breakdown (0.20.0, ADR-038 D3):** `research_on_file` (keywords:read) returns `lookups` (kind, label, competitor subject, market, inputs summary, status, requested/fetched dates, age in days, `fresh` within 30 days, row count; research and backlink kinds), `analyses` (newest finished "Why they outrank you" analysis per topic within a year: topic, keyword, results-page source and cost, AI status, `fresh` within 14 days) and `results_pages` (count and date range of stored tracked results pages). `opportunities_list` rows and `opportunity_details` (`present()`) carry `score_breakdown`: `priority`, `formula` and seven `factors` (demand, relevance, impact, confidence, effort, risk, evidence), each `{key, label, value, basis}` read from stored evidence (`Domain\Opportunities\ScoreBreakdown`).

**Data quality page (0.17.0, phase B):** `data_quality` now comes from `Modules\DataQuality\DataQualityService`, the same service as *Manage → Data quality*. Without `group` it adds `imports` (Search Console: 90-day window, covered/missing days, missing ranges newest first up to 20, measured zero days, lag; Bing: span and lag; GA4: state, covered days, limited/thresholded, sessions, key events and revenue with `recorded`/`none_recorded`/`no_sessions`, timezone, currency and attribution limits) and `compatibility` (warnings and notes before comparing). With `group` it returns only that group's datasets, as before. Missing days come from `SearchPerformanceReader::coveredDates()`, the dates `coveredDays()` counts.

**Topics and owners (0.18.0, ADR-037 C2):** `clusters_list` (keywords:read; status owned/contested/unowned/overlap/retired, `q`, 25 per page, counts; since 0.18.2 topics with no relevant search and no own traffic, rankings or manual owner are hidden unless `include_unrelated` is true, and `counts.hidden` says how many) and `cluster_get` (members with demand, candidate pages with shares and best position, overlap with kind/harmful/switches/weekly leaders) read the clusters the last opportunity analysis saved. `cluster_set_owner` (scope `clusters:write`) sets an exact own-domain URL or `null` (clear) at `expected_version`, through `ClusterService::setOwner` with `projects.edit`, audit `clusters.owner_set` and `via`.

**Relevance (0.18.0, ADR-037):** `keyword_research` adds `relevance` (`verdict` relevant/unsure/not_relevant/unchecked, `reason`, `source` rules/team) per keyword and a `relevance` filter (all, hide_not_relevant, relevant, unsure, not_relevant; default all). `keyword_set_relevance` (scope `keywords:write`, label "Mark keywords relevant or not relevant") sets up to 50 keywords to relevant (rating 2, a finer positive rating is kept), not_relevant (rating 0) or auto (rating removed) through `ResearchService::setRelevance`, with the creator's `projects.edit` permission, audit `research.relevance_set` and `via`.

`annotations_list` adds `version` and `via`; `seo_tasks_list` adds `version` and `via`; `content_brief_details` adds `version` (same as `revision`) and `via`.

## AI/MCP hardening (Unreleased)

The registered catalog contained 29 read tools at this point (34 since complete data coverage). New tools reuse existing scopes: guide_list, guide_search, guide_read and project_overview use projects:read; analytics_summary uses pages:read; project_monitoring uses projects:read, additionally opportunities:read for alerts and current integrations.manage permission for workspace budgets. Overview sections independently check their data scopes. Monitoring omits recipients, secrets, hooks and delivery errors. All project reads retain current-user, workspace and token-selected-project restrictions.

Public guide content is generated by `php bin/sync-ai-guide.php` into resources/guide/catalog.json, included by the existing signed-resource packaging rules. Run it after public-guide or version changes and before packaging. Only numbered public chapters are allowlisted; internal developer docs are never served. Reads default to 3,000 characters (maximum 6,000) and expose next_offset. Search returns up to ten short excerpts.

Search reads accept explicit from/to dates (maximum 486 days); daily series are opt-in. Combined query/page lists disclose their 5,000-row-per-engine candidate bounds and never present that sample as exhaustive. Rankings, opportunities and stored keyword lists expose offsets; backlink target filtering is exact and its existing 1,000-link sample remains explicit. Site audits expose ten saved runs and paginated issue details. Local branch reviews/grids are opt-in; reviews default to twenty when requested. Analytics reads expose current currency/timezone, imported-day coverage, quality flags and observed totals separately from complete totals. Missing or limited data is not inferred zero.

MCP results are bounded to 40 KB; Ask AI uses 16 KB. Structured truncation keeps valid JSON and marks the dropped fields and rows. Paged lists (`offset`/`limit`, with `total` and `next_offset`) are `article_ideas` (ideas), `topic_plans`, `outcomes`, `lab_tests` (profiles), `competitors_list`, `projects_list` and `search_segments`, plus the earlier offset tools. When a response cuts a paged list, `next_offset` counts the records actually returned, so every record stays reachable. A cut list that cannot be paged is named in `result_limits.message` with "narrow the filters"; the message never asks for an offset the tool lacks (ADR-042). Clients should request small focused pages, inspect limits/coverage and fetch additional pages only when needed. No read tool refreshes providers, starts paid lookups or guarantees every dashboard record fits one response. Existing seven scopes and ADR policies are unchanged.

## Complete data coverage (Unreleased)

The catalog has 34 read tools, so an AI client can read every data family shown in the dashboard. Five tools are new and two are extended:

- `seo_report` (projects:read): the weekly/monthly report from `ReportBuilder`, for the same period rules as the printable report and PDF. Each section is null unless the token also has that family's scope: search, rankings, pages (GA4), opportunities (audits and opportunities). `to` must end a complete period.
- `forecasts` (search:read): `ForecastService::overview` for horizons 7/14/28 with method, prediction, calibrated interval, training window, reason when no forecast, optional daily points, scored saved forecasts and the event calendar. Series bindings stay internal; nothing is captured or saved.
- `web_vitals` (pages:read): the latest CrUX snapshot per URL/origin (PHONE or DESKTOP, paginated) with metrics above Google's good thresholds. With `url`, the weekly history and watch state come back. No CrUX call.
- `page_changes` (pages:read): watched pages with their last checked fields, and detected changes as field-by-field before/after values, filtered by literal URL fragment and significance, 25 per page. `change_id` returns one change. Reads go through the new `PageWatchReader` interface, implemented by `PageMonitoringRepository` and scoped to the workspace.
- `browser_audit` (opportunities:read): the saved rendered/Lighthouse result through `ResearchRepository` directly. The service is not used because it can mark stale runs as failed. It returns grouped `RenderedFindings`, bounded per-page evidence without provider task IDs, and the run history.
- `ai_visibility` adds `answer_samples` (Gemini/Perplexity answers to the user's prompts, ADR-032). It returns excerpts by default, and full text and citations with `include_answer_details`.
- 0.43.0: `ai_visibility` adds `crawler_access` and `citation_readiness`. `crawler_access` is the latest robots.txt check: per AI crawler its kind (search or training), state, whether it has its own group and the blocked top pages, plus llms.txt. `citation_readiness` is the top Search Console pages with their checklist and how-well-tested levels. `analytics_summary` (still `pages:read`) adds `ai_referrals`: visits, key events and revenue from AI assistants in all channels, last 28 imported days against the 28 before. All of these read stored data only.
- `project_monitoring` adds `kind: indexnow`: setup state, verified host, sitemap, auto-submit, last error and recent submissions, never the key.

All new reads are bounded, start no provider request, and pass through `ToolContext` project checks. Ask AI reuses the same registry, so it gains the same tools.

## Opportunity intelligence (Unreleased)

`opportunities_list` adds `bid_verdict` (target/consider/reconsider or null), `business_potential` (0–3 or null) and `zero_click_risk` (high/medium/low or null) to each finding, and accepts `verdict` and `zero_click` filters. Values come from the stored evidence of the last analysis; no new scope, tool or provider call. The business profile itself is not exposed over MCP.

## SEO team tasks (Unreleased)

seo_tasks_list is a read-only opportunities:read tool: saved assignments, deadlines, progress, exact-page/search/priority/status/assignee/overdue filters and 25-row page metadata. Due dates use the project timezone. Task progress is separate from finding status. Notes/titles are untrusted context; no task write tool, provider call or new scope. Linked record content remains in the existing separately scoped brief/annotation tools.


## Google indexing monitoring (Unreleased)

indexing_status requires existing pages:read, project_id and exact HTTP(S) url. It returns a property-bound saved inspection (or null), watch/status/last-success/attempt/error and curated Google index evidence with interpretation notice, plus (0.37.0) `states` (public, indexable, known, indexed, request, recrawl as yes/no/unknown/not_applicable with evidence) and `timeline` (site audit, sitemap, IndexNow, Google checks and crawls, recorded requests; newest first). ToolContext retains token-selected project and current user access restrictions. page_details adds the same google_indexing evidence and notice. Both are strictly local reads, with no API calls or inspection/watch writes. Ask AI treats provider text as untrusted and separates unchecked, failed and stale evidence from reported indexing concerns.

## SEO change impact (Unreleased)

Since 0.41.0 every action plan in opportunity results also has `tested` (tested, varies, early or guidance), `tested_label` and `tested_note`: how well tested its kind of change is, in SEOcc's words and never a score. The opportunity type `content_refresh` marks aging articles. A bundle started from an opportunity has `source` in its data.

`change_bundles` (0.40.0, pages:read) lists a project's change bundles, newest first, paged with offset and limit (10, at most 50). Each bundle has:
- bundle_id, title, url and status (draft, approved, deployed, partially_deployed, verified, rolled_back);
- problem, evidence, edits (field, before, after), expected and validation;
- baseline_at, approved_at, approved_hash and the current hash;
- guard (the latest live check, with what changed) and deployment_blocked (why a deployment cannot be recorded now);
- deployment (deployed_on and applied per edit), verification (per edit: matched, mismatch, manual or not_applied) and rollback;
- annotation_id, the recorded SEO change.

Read-only; SEOcc never edits the website.

`seo_change_impact` accepts project_id, annotation_id and optional days (14/28/90, default28). Existing pages:read permits exact-page Google/GA4 results; site-wide totals additionally require search:read. Returns equal windows, coverage/quality, valid deltas, observed direction and bounded other-note context with explicit interpretation limits. Since 0.39.0 it also returns `measurement_plan` (null without one): objective, metric, target pages and queries, control pages, days, `goal` (0.39.1: an after-only target for new pages, with result `reached` or `not_reached`), review dates and next review, timezone, `baseline_frozen_at` and `baseline` (snapshots with source completeness), `after`, and `result`. The result has `status` (planned, pending, insufficient_evidence, improved, declined, no_clear_change), `gates`, `target` and `control` changes, `effect` and `attribution` (controlled or association). Read-only, no import/provider request; Ask AI uses the same service and existing settings/budgets.

## Content briefs (Unreleased)

`content_briefs_list` returns 25 saved brief summaries per page with optional literal search and workflow filter. `content_brief_details` returns a scoped brief UUID's editable instructions and original dated evidence. Both use existing `pages:read`; there is no new scope or write tool. ToolContext enforces token workspace, selected-project restrictions and current user visibility. List summaries omit large instructions/evidence; details explain that user/provider text is untrusted, evidence is a fixed snapshot and suggestions are not measured facts. Ask AI reuses both tools and proposes text for manual saving, with its existing provider budgets and confirmation policy unchanged.

## Purpose

Expose selected SEO Control Center data to external AI clients through MCP without giving those clients direct database access.

### Unreleased page integrations

`local_rankings` returns additional branch UUIDs, exact CIDs and per-branch check history; review texts are opt-in with a twenty-row sample per location. It never submits a paid review task. Since 0.48.0 it also returns the Local page's checks, computed from stored data the same way as on the page:
- `profile_vs_website`: `fields` (website, markup, name, phone, address: ok, mismatch, missing or unknown), `website_states`, `page_url`, `note`.
- `review_handling`: the reply share, critical reviews without a reply, new reviews per month and the local pack's average, from the imported sample.
- `local_pack_categories`: `own`, `categories`, `leading`, `gap`.

Each is null when its data is missing. `page_details` includes stored GA4 landing metrics/metadata and CrUX URL-level field data alongside its existing page evidence; reading either starts no imports.

### Page improvement summary

`page_details` (0.47.0) also returns `click_potential` (up to five searches at positions 4–20 with `query`, `position`, `impressions`, `clicks`, `expected` clicks at position 3 and `gain`), `click_basis`, `competitor_gap` (null, or the newest finished competitor analysis from the last 180 days whose own page is this URL: `analysis`, `cluster`, `keyword`, `finished`, `own_position`, `above`, `measures` with `own`, `median` and `behind`, `own_modified`, `newest_above`) and `change_results` (up to ten changes on this URL from the last year: the presented `annotation`, `metric`, `status` such as `no_plan`, `label`, `effect`, `unit`, `attribution`). All are read from stored data.

`page_details` adds `improvements` (an ordered list of `id`, numeric `priority`, `title`, up to three `evidence` strings, `next_action` and local evidence `section`) and `traffic_comparison` (availability, previous range/Google metrics and interpretation note). Higher priority means earlier review, not estimated ranking impact. These use the same PageService result as the browser and Ask AI; they are read-only and require the existing `pages:read` scope. Google decline candidates require complete daily page imports in both consecutive 28-day windows, stored URL metrics, at least 100 previous clicks and a drop of at least 30%.

## Architectural rule


```text
MCP request
→ authentication
→ scope authorization
→ MCP tool adapter
→ application service
→ domain/repository
→ sanitized result
```

## Initial release

MCP was read-only until ADR-036 (see the top of this file).

## Proposed V1 tools

- `projects_list`
- `project_get`
- `search_performance_summary`
- `search_queries_list`
- `search_pages_list`
- `keywords_list`
- `keyword_get`
- `keyword_history`
- `rankings_winners`
- `rankings_losers`
- `competitors_list`
- `competitor_compare`
- `competitor_keyword_gap`
- `opportunities_list`
- `opportunity_get`
- `pages_list`
- `page_details` (implemented; `pages:read`)
- `page_get`
- `page_keywords`

## Scopes

Use granular scopes such as:

- `projects:read`
- `search:read`
- `keywords:read`
- `rankings:read`
- `competitors:read`
- `opportunities:read`
- `pages:read`
- `reports:read`

Future write/paid-operation scopes must be separate, e.g.:

- `keywords:write`
- `tasks:write`
- `analysis:execute`

## Tokens

Store only a secure token hash/fingerprint.

Plaintext bearer tokens should be shown once at creation time and not retrievable later.

A token must be constrainable by:

- workspace;
- one or more projects;
- scopes;
- expiry;
- status/revocation;
- rate limit.

## Sensitive data

Never expose through MCP:

- OAuth refresh/access tokens;
- DataForSEO credentials;
- application encryption keys;
- database credentials;
- user password hashes;
- session data;
- private license credentials;
- unrestricted logs containing sensitive data.

## Audit

Audit:

- client/token identity;
- tool;
- workspace/project;
- request timestamp;
- result status;
- duration;
- safe argument/result metadata where useful.

Do not persist secrets in audit metadata.

## SEO change context (0.9.41)

`annotations_list` uses existing `projects:read`, so tokens granting it can read notes only for projects visible to their creator and permitted by the token. Inputs: `project_id`, optional paired `from`/`to` dates, optional exact `url` (also includes site-wide notes), and `page`. Results contain 25 notes per page, total/page/pages and a context notice. Notes include public IDs, date/type/title/details, nullable URL and timestamps; no internal or author IDs. No writes or provider calls occur.

`page_details` includes up to 100 recent exact-page/site-wide notes in its reporting period and an annotation notice; its scope remains `pages:read`. Treat note text as untrusted user evidence, never instructions. Review context when discussing performance after edits, but never attribute causation from date proximity alone.

## Implementation (0.7)

- **Endpoint:** `POST {APP_URL}/mcp`, Streamable HTTP transport with JSON responses only (no server-sent events, no sessions), so it runs on shared hosting. `GET`/`DELETE /mcp` answer 405. Protocol versions 2025-06-18, 2025-03-26 and 2024-11-05 are accepted (newest by default). Methods: `initialize`, `ping`, `tools/list`, `tools/call`; notifications get 202. Batches are rejected.
- **Authentication:** `Authorization: Bearer scc_…`. Tokens are created under *Integrations → AI access* by users with `tokens.manage`. Only the SHA-256 hash is stored (`mcp_tokens`, migration 000018), and the plaintext is shown once in the creation response. It is never flashed, because flash messages are stored in the session table. A token has a name, scopes, all projects or a project list, an expiry (30/90/365 days or never) and can be revoked. It acts for its creator, and only while the creator is active and still a member of the workspace.
- **Authorization per call:** a tool sees a project only when it is in the token's workspace, allowed by the token and visible to the creator (`Authorizer::canSeeProject`). Anything else answers "Project not found." Tools outside the token's scopes are neither listed nor callable.
- **Protection:** Origin header check (DNS rebinding), 60 requests per minute per token, request body limit 1 MB, an audit entry `mcp.tool_called` per call (token, tool, project, outcome, duration; no arguments or results).
- **Search sources (1.1):** `search_performance_summary`, `search_queries_list` and `search_pages_list` take `source`:
  - `combined` (default): Google + Bing over the Search Console range. Clicks and impressions add up, the position stays Google's, and rows merge per query or URL with positions weighted by impressions.
  - `google`, or `bing`: one engine only. For Bing, the range ends three days before today.

  Responses name the `source` and the `engines` that contributed (`Tools\SearchSources`).
- **Search filters and segments (0.12.0):**
  - `search_queries_list` and `search_pages_list` accept `contains` (with `match` any or all), `exclude`, `min_words`, `min_impressions`, `min_clicks`, `min_ctr`/`max_ctr` (fractions), `min_position`/`max_position`, `presence` (`new` or `lost`), `sort` (clicks, impressions, ctr, position, clicks_change, impressions_change, position_change), `order` and `compare_previous`.
  - With any of these arguments, the list comes from `SearchPerformanceReader::matching()`. It is one SQL aggregation over every stored Search Console row in the range, with HAVING bounds, `LIKE … ESCAPE '!'` text filters and window-function totals. The response contains `total_matching`, `matching_totals`, `previous_range`, and per row `previous` and `change`.
  - Filtered lists use Search Console only. With `source: bing`, filters run over Bing's top 5,000 rows, and the response says so.
  - The unfiltered list reports its real candidate limit (500 per engine) and whether the list was truncated.
  - `search_segments` (scope `search:read`) returns Search Console metrics per country (ISO 3166-1 alpha-3) or device, with click and impression shares, covered days and optionally the previous period. These are site-wide breakdowns: the import stores no page-by-country or query-by-country rows.
- **Tools (read-only):** `projects_list`, `search_performance_summary`, `search_queries_list`, `search_pages_list`, `keywords_list`, `rankings_summary`, `keyword_history`, `competitors_list`, `competitor_compare`, `opportunities_list`, and since 1.3 `site_audit` (scope `opportunities:read`), `ai_visibility` and `local_rankings` (scope `rankings:read`), `keyword_research` (scope `keywords:read`) and `backlinks` (scope `competitors:read`). `site_audit` with an `issue_type` lists the affected pages with their crawled status, redirect target, title, meta description, H1, canonical, noindex and word count. ChatGPT and Claude.ai connect with an OAuth sign-in instead of a token (ADR-029): discovery under `/.well-known/oauth-protected-resource` and `/.well-known/oauth-authorization-server`, dynamic registration at `/oauth/register`, the approval page `/oauth/authorize` (PKCE S256, owners and admins) and `/oauth/token`, which issues an ordinary MCP token named `{app} (sign-in)` plus a rotating refresh token. The in-app AI assistant (ADR-028) uses the same tool classes, with the signed-in user's project visibility instead of a token. The 1.3 tools read stored data only; they never start a crawl or a paid lookup, and use existing scopes so that existing tokens see them. Results are JSON (`structuredContent` plus a text copy) with public IDs only. `pages_list` and `page_details` now use the Pages read model with the new `pages:read` scope. Existing tokens must explicitly grant that scope; no scopes are automatically elevated. `page_details` takes the exact HTTP(S) URL and returns its search queries, latest rankings and citations, audit issues, matching open opportunities, internal-link suggestions, possible cannibalization and stored backlink samples. `backlinks` also accepts `links` and `lost_links`. `keyword_get`, `competitor_keyword_gap` and `opportunity_get` remain covered by existing tools.
- **Hosting:** CGI/FastCGI/LiteSpeed setups drop the `Authorization` header. `public/.htaccess` passes it on as an environment variable, and `public/index.php` restores it.
- **Testing locally:** security software that inspects traffic can empty the responses of paths named `/mcp` on the testing machine, over HTTP and HTTPS alike. That happened with Avast's network filter during development. Test from a machine without such filtering, or check the audit log to confirm that calls arrive.

## Brand-name mention context (Unreleased)

Existing `ai_visibility` adds `brand_name_mentions`: configured name/market and word-matching caveats, latest completed result, latest attempt and up to 12 history rows for the current name/market/domain configuration. Completed results include count/optional volume by platform and named answer fields (question, excerpt, source rank/URL, other sources, response date). History/latest-attempt omit repeated example arrays. Counts from failed/processing steps are partial evidence, not completed snapshots or zeros. Name and domain counts overlap and must not be added; namesakes and sampled provider coverage remain explicit.

Scope remains `rankings:read`; ToolContext resolves current user/project/token visibility. No new scope, token elevation, provider lookup or write action. Ask AI reuses this same tool. Unconfigured names have null completed results. Guide chapter 20 gives practical prompts.

### Opportunity source coverage

`opportunities_list` includes project-scoped `coverage` (source → status and nullable date) and a coverage notice from the last analysis. Findings include Required sources evidence. Missing/stale coverage preserves old findings and scores; it is not proof that the recommendation is still current. Existing scopes, bounds and project access checks apply.

## Opportunity evidence and workflow reads

`opportunities_list` remains read-only in opportunities:read. It accepts page_url, min_impact, effort, data_basis, view: weekly, status: snoozed and optional opportunity_id for detail. Detail includes formulas, coverage, reasons and bounded supporting findings; estimates/observed outcomes remain correlations. Current user, token project selection and tenant checks apply before every read. Snooze/status changes use authenticated, CSRF-protected UI controls and audit logs; no MCP write tool or new scope is added.
