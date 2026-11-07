# Database Design

## AI/MCP read coverage and hardening (Unreleased)

No schema migration. GA4 totals and daily reads join existing current-currency/timezone import metadata, distinguish imported zero days from missing days, and remain project scoped. Landing-page pagination adds a stable URL tie-breaker and offset. Conversation serialization reuses existing lease locks; provider cooldowns/attempt caps reuse rate-limit buckets and spending accounting reuses usage reservations. No new provider data store or model cache is introduced.

## SEO team tasks (Unreleased, migration 000057)

Additive opportunity_tasks stores workspace/project/opportunity FKs, separate workflow status, optional public assignee/brief/annotation IDs, DATE due date, plain-text notes, exact page URL, revision and UTC updated_at. InnoDB/utf8mb4_unicode_ci; opportunity_id is unique. Joined reads/writes bind workspace/project and exclude deleted projects/workspaces. Revision-zero inserts reject duplicate concurrent creates; updates match the expected revision. Analysis leaves these rows unchanged. Invalid/removed linked records are rejected on save and unavailable on read. Recover with matching updater file/database backup; older files ignore the added table. No down migration or existing-data rewrite.


## Google indexing evidence (Unreleased, migration 000056)

Additive google_inspections and google_inspection_changes tables use InnoDB/utf8mb4 and cascading project FKs. Inspections are keyed by project/exact-URL SHA-256, with connection/property digest, URL, watch flag, curated JSON result, last-success/attempt UTC times, generic error and last successful job UUID. Reads bind the current digest. Tracking a URL after relinking replaces its binding and clears previous evidence. The Pages catalog includes tracked URLs.

Change events store only successful verdict/canonical transitions and retain the newest 100 per project. First results/failures create no events. Success uses a row lock and job replay guard; live project-row locks serialize the 500-URL and 50-watch caps. The due index supports bounded dispatch. Filters paginate the capped project list. No raw response payload or token is stored here.

Recovery uses the updater's matching file/database backup. This forward-only expansion preserves prior data and has no destructive down migration. Older files ignore the new tables; if reverting files while retaining this database, remove queued indexing jobs before restarting old workers, or restore the matching backup.

## Content briefs (Unreleased, migration 000055)

`content_briefs` stores internal id/public UUID, workspace/project, title/keyword/exact optional URL, workflow status, `published_at` (UTC, first time the brief was marked Published; cleared when it leaves Published, migration 2026_10_11_000100), validated plain-text sections JSON, original evidence JSON, revision, nullable creator, UTC create/update and soft-removal timestamps. InnoDB/utf8mb4_unicode_ci, cascading workspace/project FKs and creator SET NULL follow existing conventions. The scoped status/id index supports 25-row pagination; literal bounded search uses prepared LIKE parameters with escaped wildcard characters.

Migration `2026_10_16_000600` (ADR-039 E2, guarded ALTERs): `workspaces.page_watch_limit` (SMALLINT, default 50; the application clamps 1–200). `page_watches.reason` (`watch` or `change_note`, default `watch`) and `after_due_at` (UTC DATETIME, nullable; an "after" check due then, cleared once a check is attempted at or after it), with index `page_watches_after_due`. `opportunity_outcomes.page_url` (exact page address from the finding's evidence, nullable), `implementation` (`unverified` default, `verified`, `confirmed`, `not_implemented`; written to both checkpoints of a completion), `implementation_source` (up to 255), `implementation_at` (UTC) and `overlaps` (TEXT JSON list of kind/label/date/ref, at most 10). Existing rows stay unverified without overlaps. Migration `2026_10_17_000100` (0.23.1) fills `page_url` of rows that still have none from the opportunity's team task page (`opportunity_tasks.url`), else from the page in the opportunity's evidence; it touches only NULL rows.

`serp_events` (migration 2026_10_16_000500, ADR-039 E1): results-page changes between two comparable rank checks. Workspace/project (cascade), `project_keyword_id` (cascade), `device`, `checked_on` and `previous_on` (the compared check, at most 14 days older), `kind` (feature_added/removed, competitor_entered/left, new_url, own_entered/left/moved, format_shift), `subject` (feature, domain, URL or `from→to`, up to 2,048) with `subject_hash` (SHA-256), `detail` JSON (positions, URL, `above_you`, `own`), `created_at`. Unique per keyword, device, day, kind and subject hash (detection is idempotent); indexed by project/device/day and project/kind/day; pruned after 400 days by the daily catch-up. Derived from `ranking_snapshots`, whose storage (ADR-024) is unchanged.

`investigations` and `investigation_items` (migration 2026_10_16_000400, ADR-038 D3): research notebooks per project. `investigations`: internal id/public UUID, workspace/project (cascading FKs), `title`, `question`, `status` (open/concluded/archived), `conclusion`, nullable creator (SET NULL), `via` (MCP token name of the last change), `revision` (optimistic concurrency; adding or removing items also moves it), UTC create/update times; indexed by workspace/project/status/updated. `investigation_items`: public UUID, investigation (cascade), `kind` (source/check/limitation/note), optional `ref_type` (analysis, research, opportunity, topic, page, data_quality, url) and `ref_id` (ID, `kind[:subject]` or URL, up to 2,048), `label`, `detail`, `observed_on` (DATE), nullable adder, `via`, `created_at`. Limits are enforced in `InvestigationService` (300 items, 30 per call).

`competitor_analyses` (migration 2026_10_16_000300, ADR-038) stores one "Why they outrank you" run per topic request: internal id/public UUID, workspace/project (cascading FKs), the topic's `cluster_id` public UUID, label, main `keyword` and topic `keywords` JSON, demand, `status` (queued/running/done/failed), `serp_source` (stored/paid), location/language, the classified `results` JSON, `own_url`/`own_position`, `pages` JSON (page structure only: title, outline, counts, schema types, elements, dates, hash; never competitor text), `verdict` JSON (format, own type, coverage, diagnosis), `cost` (DataForSEO only), `error`, nullable requester and UTC request/start/finish times. Since D2 the `verdict` JSON also holds `links` (the internal-link plan), `explanation` (the validated AI answer) and `ai` (status, note and AI cost). Indexed by project/cluster/request time for the latest run per topic; the job starts a run only from `queued`, so one worker runs it.

Creation uses INSERT SELECT from the matching live workspace/project; find/list/count/edit/remove all bind both contexts. Update/delete increment revision only when the caller's expected revision matches. Evidence is immutable after creation, with an original-target line and dated/source-limited content. This forward-only additive migration leaves prior data unchanged. Recovery follows the updater's matching file/database backup; no down migration is added.

## Database targets

- MariaDB 10.6+
- MySQL 8.0+

See ADR-011. All tables use InnoDB and `utf8mb4` / `utf8mb4_unicode_ci`, declared explicitly in migrations.

Use portable SQL that runs on both. Avoid syntax that exists on only one of them (e.g. MySQL row aliases in `ON DUPLICATE KEY UPDATE`, MariaDB `RETURNING`).

## General rules

- Numeric primary keys for internal joins.
- Public identifiers are UUID version 7 (`public_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin`, unique): time-ordered for index locality, 74 random bits so they cannot be guessed. Generated by `Core\Support\Uuid::v7()`.
- Store application timestamps in UTC.
- Scope tenant data using `workspace_id` and `project_id` where appropriate.
- Use explicit indexes for common filters and time-series access.
- Use migrations for every schema change.
- Do not require customers to manually apply SQL patches.

## Initial table groups

### Identity

- `users` (includes `password_hash` and `system_role`: `none` | `admin`, see ADR-013). A separate password table was dropped: one hash per user, never selected except by the login query.
- `sessions` (ADR-019): SHA-256 hash of the cookie token as primary key, `user_id`, JSON `data`, IP, user agent, `last_activity_at`, `expires_at`.
- `user_mfa_methods` — TOTP secret encrypted with the key ring (context `mfa.totp:{user_id}`), `last_used_step` for replay protection
- `user_recovery_codes` — SHA-256 hashes, `used_at`
- `password_resets` (migration 000023): `user_id`, `token_hash` (SHA-256, unique), `expires_at`, `used_at`, `ip` (ADR-027)
- `mail_settings` (migration 000023): single row (`id` = 1): `transport` (none/smtp/php), SMTP `host`, `port`, `encryption`, `username`, encrypted `password`, `from_address`, `from_name`
- `email_verifications` (not created yet)

### Workspaces and permissions

- `workspaces` (`deleted_at`: soft delete since migration 000022; deleted workspaces are hidden, excluded from access checks, and their projects are soft-deleted with them)
- `workspace_users` (`workspace_id`, `user_id`, `role_id`, `status`) — one role per user per workspace
- `roles` (`workspace_id` NULL for seeded system roles; set for future custom roles)
- `permissions`
- `role_permissions`

Project-level access lives in `project_members` (below). There is no separate `user_roles` table. See ADR-013.

### Projects

- `projects`
- `websites` — the site a project is about (primary URL, CMS type, timezone). One per project in 0.x.
- `project_domains` — hostnames that belong to the website (e.g. `example.com`, `www.example.com`, `blog.example.com`), used to classify URLs as own vs. external.
- `project_settings`
- `project_members` (`project_id`, `user_id`, `role_id` NULL = inherit workspace role; may only narrow, ADR-013)
- Projects are soft-deleted (`deleted_at`); every query filters on `workspace_id` and `deleted_at IS NULL`.
- `competitors`

### Integrations

- `connections`
- `connection_tokens`
- `connection_sync_states`
- `google_properties`
- `dataforseo_profiles` — one DataForSEO account per workspace (`workspace_id` PK): API login, password encrypted (context `dataforseo.password:{workspace_id}`), status active/error, last known balance
- `api_budgets` — per workspace and provider: daily, monthly and per-request limits in USD (NULL = none); the row is locked `FOR UPDATE` while a reservation is checked

### Integrations

- `oauth_clients` — per provider: client ID, encrypted client secret
- `connections` — workspace-owned external accounts (`provider`, `account_email`, `status` active/error/revoked)
- `connection_tokens` — encrypted refresh/access tokens, access expiry
- `google_properties` — discovered Search Console properties per connection
- `project_search_console` — one linked property per project

### Search performance

Implemented in migration `000012`. Dates are Search Console dates (Pacific Time), stored as `DATE`.

- `search_queries` — per-project query dictionary: `query_hash` (SHA-256 of the full query, `BINARY(32)`, unique per project), `query` (first 500 characters)
- `pages` — per-project URL dictionary (`url_hash`, `url` up to 2048, `first_seen_at`); later page modules reuse it
- `search_console_daily` — property totals per day (includes anonymized queries); PK `(project_id, date)`
- `search_console_queries` — PK `(project_id, date, query_id)`, secondary `(project_id, query_id, date)` for per-query history
- `search_console_pages` — PK `(project_id, date, page_id)`, secondary `(project_id, page_id, date)`
- `search_console_query_pages` (migration `000041`) — joint query/page metrics, PK `(project_id, date, query_id, page_id)`, secondary `(project_id, page_id, date)`. Uses the existing scoped dictionaries; averages are impression-weighted.
- `search_console_query_page_days` (`000041`) — separate per-project/day import marker, also for zero rows. Joint imports backfill only the latest 28 days and are deleted on property-change purge. Historical joint rows remain stored, but Pages reads the latest 28-day performance window.
- `search_console_dimensions` — device and country per day; PK `(project_id, dimension, date, value)`
- `search_console_import_days` — days whose detail was imported
- `search_console_fresh_daily` (migration `2026_10_11_200000`, 0.14) — preliminary web totals (`dataState=all`) of the last 10 days with a `complete` flag; PK `(project_id, date)`. Replaced on every totals refresh; read only for days after the newest `search_console_daily` row.
- `search_console_search_types` (`2026_10_11_200000`) — finalized daily totals per other surface (`discover`, `googleNews`, `news`, `image`, `video`); PK `(project_id, search_type, date)`. Position is 0 where Google reports none.
- `search_console_sitemaps` (`2026_10_11_200000`) — the property's sitemaps as Google processed them (`url_hash`/`url`, index and pending flags, last submitted/downloaded UTC, errors, warnings, submitted URLs); PK `(project_id, url_hash)`. Replaced on every totals refresh.

The three extras tables are deleted with the rest on a property-change purge.
- `search_console_sync` — per project: property the data came from, status (`pending`/`importing`/`ok`/`error`), last totals refresh, last success, last error

Metrics are `clicks`, `impressions` (INT UNSIGNED) and `position` (DECIMAL(8,2), impression-weighted when aggregated). CTR is derived, never stored. All tables cascade on project deletion. Aggregations filter by `project_id` and a date range first, matching the primary keys.

**Monthly rollups** (migration `000020`, 0.8.3):

- **Tables:** `search_console_queries_monthly` and `search_console_pages_monthly` hold one row per month and query/page. Each row has `clicks`, `impressions` and `position_sum = SUM(position × impressions)`, so combined averages stay weighted by impressions.
- **Validity:** `search_console_rollup_months` marks the months whose rollup matches the daily rows. `replaceDay()` removes the mark of its month in the same transaction.
- **Rebuilding:** at the end of each import run, the importer rebuilds the months whose days are all imported (`refreshRollups()`), newest first, while the run has time left.
- **Reading:** the top-query/top-page reader takes whole months inside the range from the rollup when they are marked, and everything else (edge months, months not yet rebuilt) from the daily rows. Daily rows are read in primary-key order (`FORCE INDEX (PRIMARY)`), because the secondary history index does not contain the metrics.
- **Measured** with 2.4 million query rows (16 months × 5,000 a day), local MySQL 8.4, top 50 queries:

  | Range | Before | After |
  |---|---|---|
  | 3 months | 1.5 s | 0.22 s |
  | 12 months | 6.2 s | 0.73 s |
  | 16 months | 8.1 s | 0.87 s |

  The first rebuild of 17 months takes about 11 s. After that, the daily import rebuilds only the current month (about 1 s).

### Keywords and rankings

Implemented in migration `000014` (ADR-023):

- `project_keywords` — keywords tracked per project and market: normalized `keyword` (lower case, single spaces, max 80 characters), `keyword_hash` (SHA-256), Google Ads `location_code` and `language_code`; unique per project, hash and market
- `keyword_metrics` — latest metrics per **workspace**, keyword hash and market: `search_volume`, `cpc`, `competition` (0-100), `competition_level`, `monthly_searches` (JSON, up to 24 months, newest first), `fetched_at`; keywords without provider data are stored with NULL metrics so they are not bought again before the refresh window
- `project_keyword_sync` — per project: metrics update status (`pending`/`running`/`ok`/`error`), last error, last request and last success

Rank tracking, migration `000015` (ADR-024). Check days are UTC dates.

- `project_ranking_settings` — per project: `enabled` (off by default), `device` (desktop/mobile), `frequency` (daily/weekly)
- `ranking_snapshots` — one row per project keyword, device and day (unique): organic `position` (NULL = not in the top 100), `absolute_position`, ranking `url`, `serp_features` (comma list), `top_results` (JSON, first 10 organic results: position, domain, url); index `(project_id, device, checked_on)`
- `ranking_daily` — per project, device and day: keywords checked, ranked, top 3, top 10, average position of ranking keywords, visibility (%)
- `project_ranking_sync` — per project: status, last error, last complete check day, last success

The keyword table uses `ROW_NUMBER()`/`MIN() OVER` window functions and a CTE (MySQL 8.0+, MariaDB 10.2+).

### Pages

`pages` is the existing per-project Search Console URL dictionary. The Unreleased Pages module reads this dictionary alongside the other stored URL sources and their normalized evidence (see *Pages read model* below). The originally proposed `page_metrics` / `page_keyword_relations` tables are not created; joint keyword/page facts use `search_console_query_pages` and tracked rankings remain in their source tables.

### Competition

Implemented in migration `000016` (ADR-025):

- `competitors` — per project (max 10): `domain` (lower case, without `www.`; subdomains count), `source` (`manual`/`discovered`); unique per project and domain. (`competitors` replaces the table planned under *Projects*.)
- `competitor_rankings` — a competitor's position (NULL = not in the checked results) and URL per project keyword, device and day; PK `(competitor_id, device, checked_on, project_keyword_id)`
- `competitor_daily` — per competitor, device and day: the same aggregates as `ranking_daily` (visibility with the project's keyword volumes)

Planned later: `competitor_keywords` / `competitor_pages` from paid provider data (keywords the competitor ranks for that the project does not track).

### AI visibility

Implemented in migration `000029` (1.2):

- `ai_overview_snapshots` — per project keyword, device and check day: `present`, `reference_count`, the project's `own_position` among the sources (NULL = not cited) and `own_url`; PK `(project_keyword_id, device, checked_on)`
- `ai_overview_references` — the sources of the latest check per keyword and device (`position`, `domain`, `url`, `title`), replaced on every check

Both cascade on keyword and project deletion.

### Keyword research

Implemented in migration `000030` (1.3):

- `research_results` — the latest result per project, `kind` (`ranked`/`gap`/`ideas`/`competitors`/`overview`) and `subject` (the competitor's public ID for gaps, otherwise empty): `status` (`queued`/`done`/`failed`), `params` (JSON: market, domain, seeds, competitor), the normalised rows in `items` (JSON, up to 1,000), `item_count`, `error`, `requested_at`, `fetched_at`. Cascades on project and workspace deletion.

### Local SEO

Implemented in migration `000032` (1.3):

- `local_settings` — one row per project: `business_name`, the map point (`latitude`, `longitude`, `zoom`), `language`, `weekly`, the matched Google listing (`cid`, forgotten when the name changes), `status` (`idle`/`queued`/`failed`), `last_error`, `last_checked_on`
- `local_keywords` — up to 50 per project, unique by text
- `local_rankings` — per local keyword and check day: the Maps `position` (NULL = not in the top 20) and the `top` results (JSON: position, title, domain, rating, votes, category; the business itself is added when it ranks below the top 5)
- `local_profiles` — per project and check day: the Business Profile (title, category, rating, votes, claimed, address, phone, url, photos)

All cascade on project deletion; rankings also on keyword deletion.

### AI mention history and ranking grids

- `ai_mention_settings` (migration `000033`) — per project: `weekly` and `last_checked_on` of the AI mention check
- `ai_mention_history` (migration `000033`) — per project, check day, platform and domain: `mentions` and `ai_search_volume`; a re-check of the same day replaces the day
- `local_grids` (migration `000034`) — ranking grids per local keyword: `size` (3/5/7), `spacing` (metres), the centre and zoom, `positions` (JSON object point index → position or null; points still missing are being checked), `status` (`queued`/`done`/`failed`) and `error`. Cascades on keyword and project deletion.

### Backlinks

Implemented in migration `000035` (1.3):

- `research_results.kind` also takes `bl_profiles`, `bl_domains`, `bl_new`, `bl_lost`, `bl_anchors` and `bl_gap` (the backlink lookups, stored like the research lookups)
- `backlink_settings` — per project: `monthly` and `last_checked_on` of the link profile check
- `backlink_history` — per project, check day and domain (the project and its competitors): `domain_rank`, `backlinks`, `referring_domains`, `spam_score`; a re-check of the same day replaces the day. Cascades on project deletion.
- Migration `000042` adds `bl_links` / `bl_lost_links` to `research_results.kind`, and `monitor_lost` (default false) / `last_lost_checked_on` to `backlink_settings`. Link rows are normalized JSON with exact source/target URLs, anchor, rank, follow and first/lost dates.

### Pages read model

`Modules\Pages\Persistence\PdoPageRepository` unions the per-project URL dictionary, Bing pages, ranking URLs, AI citations, the latest completed crawl, and sampled backlink target URLs. URLs are compared exactly with binary collation, including path case and query strings. It creates no second page identity table. Search queries use actual joint data; the latest snapshot per keyword/device determines current rankings and citations. Pagination covers every catalog URL; analysis caps query/page pairs at 5,000 and reports that limit.

### AI assistant

Implemented in migration `000036` (1.3, ADR-028):

- `assistant_settings` — per workspace: the Claude `api_key` (encrypted, context `assistant.key.{workspace_id}`), `model`, `status` (`active`/`error`) and `last_error`
- `assistant_conversations` — per workspace and user (public UUID): `pending` (JSON: a proposed change waiting for confirmation, with the results of the other tool calls of the same answer) and `model_calls` since the last user message
- `assistant_messages` — the Claude message history of a conversation: `role` (`user`/`assistant`), `content` (JSON content blocks: text, tool calls, tool results) and the tokens of assistant messages

They cascade on workspace and user deletion. Migration `000037` adds `assistant_settings.provider` (`anthropic`/`openai`). Migration `2026_10_10_000200` adds the nullable `assistant_settings.provider_workspace` (ASCII, up to 64 characters). It holds a Claude `wrkspc_…` workspace ID for keys that can act in several Claude workspaces. It is not a secret, so it is stored in plain text and cleared whenever a key is saved without one. The assistant's spending is in `api_usage` (provider `ai_assistant`).

Migration `000039` adds `assistant_fix_plans`: fix plans saved by the assistant, per workspace, project and user (public UUID). It holds the `title` and the `rows` (JSON: url, issue, current, suggested, note), and cascades on workspace, project and user deletion.

### Alerts and digest

Implemented in migration `000040`:

- `alerts`: detected events per workspace (public UUID) and optionally a project, with `type` (`ranking_drop`, `traffic_drop`, `audit_errors`, `lost_links`, `budget`), a `fingerprint` that is unique per workspace and type (so each event is recorded once), `title`, `lines` (JSON) and the app-relative `url`. `sent_at` is set once the e-mail job ran.
- `notification_settings`: one row per user, holding `alerts`, `muted_types` (JSON), `muted_projects` (JSON, internal project ids), `digest` and `digest_ai`. Without a row, alerts and the digest are on and the AI summary is off.
- `digest_log`: `(user_id, workspace_id, week)` with the ISO week (`2026-W41`). It makes each weekly digest go out once.

All three tables cascade on workspace, project and user deletion.

### MCP sign-in

Implemented in migration `000038` (ADR-029):

- `mcp_oauth_clients` — registered AI apps: `client_id` (UUID), `name`, `redirect_uris` (JSON), `auth_method`, `secret_hash` (SHA-256 of the client secret, if any), `created_ip`, `last_used_at`
- `mcp_oauth_codes` — one-time authorization codes (`code_hash`) with client, user, workspace, `scopes`, `redirect_uri`, PKCE `code_challenge`, `expires_at` (10 minutes), `used_at`
- `mcp_oauth_refresh_tokens` — rotating refresh tokens (`token_hash`) with client, the `mcp_token_id` issued with it (SET NULL on token deletion), user, workspace, `scopes`, `expires_at`, `used_at`

Codes and refresh tokens cascade on client, user and workspace deletion; expired ones are deleted on the next token request.

### Intelligence

Implemented in migration `000017`:

- `opportunities` — per project, unique by `(project_id, type, subject_hash)` (SHA-256 of the rule's subject, e.g. `query:red shoes`): title, explanation, `evidence` (JSON list of label/value pairs; kept on the row instead of a separate `opportunity_evidence` table because it is always read together), `impact`, `confidence`, `effort`, `priority`, `status` (`open`/`done`/`dismissed`/`resolved`), first and last detection
- `opportunity_status_history` — every status change, `changed_by` NULL for the analysis itself
- `project_opportunity_runs` — last analysis per project and number of detected opportunities

### Infrastructure

- `jobs` (includes `reservation_token`, `reserved_until`, unique nullable `idempotency_key`, optional `workspace_id`; failed jobs stay here with `status = 'failed'`, ADR-022)
- `job_batches` (later)
- `system_state` — installation-level JSON key/values (installation ID, cron heartbeat). Never secrets.
- `locks` (lease-based named mutexes, ADR-015)
- `schedule_runs` (unique `task_name, slot_at`)
- `api_usage` — cost ledger (migration 000013): `status` reserved → charged/released, `estimated_cost`, provider-reported `cost` (DECIMAL(12,6) USD), `operation`, optional `project_id`, `provider_task_id`
- `provider_responses` — raw provider responses, gzip-compressed, deleted after `expires_at`
- `cache_entries`
- `migrations`

### Security/API/MCP

- `api_tokens` (later)
- `workspace_invitations` — migration 000021: workspace, `email`, `role_key` (admin/member/viewer), `token_hash` (SHA-256 of the one-time link token, unique), `invited_by`, `expires_at` (7 days), `accepted_at`, `revoked_at`. Pending = neither accepted nor revoked and not expired.
- `mcp_tokens` — migration 000018: workspace, creator, name, `token_hash` (SHA-256, unique), `token_prefix` for display, `scopes`, `project_ids` (JSON or NULL = all), `rate_limit`, `expires_at`, `revoked_at`, `last_used_at`. A separate `mcp_clients` table was not needed: a token is the client.
- `mcp_write_requests` — migration 2026_10_15_000100 (ADR-036): idempotent MCP writes. `token_id` (FK, cascade), `tool`, `request_id`, unique per token/tool/request; `arguments_hash` (SHA-256 of the canonical arguments), `result` (the first JSON result), `created_at`, `completed_at` (NULL while running). Rows older than 24 hours, and unfinished ones older than 10 minutes, are forgotten on the next claim of the same id. The same migration adds `seo_annotations.revision` and nullable `via` (token name of the last MCP change) to `seo_annotations`, `content_briefs` and `opportunity_tasks`.
- `audit_logs` — append-only, no foreign keys (records outlive actors/targets); `workspace_id` NULL for installation-level events such as logins; `actor_type`, `actor_user_id`, `actor_label`, `action` (`domain.action`), `target_type`/`target_id` (public ID), `outcome`, IP, user agent, `request_id`, redacted JSON `metadata`.
- `rate_limit_buckets` — fixed windows keyed by SHA-256 of the limiter key (no raw e-mails/IPs).

### System

- `update_history`
- `system_events`

## Important indexes

At minimum plan for indexes similar to:

```text
search_console_daily(project_id, date)
search_console_queries(project_id, query_hash, date)
search_console_pages(project_id, page_id, date)
ranking_snapshots(project_keyword_id, checked_at)
competitor_visibility(project_id, competitor_id, date)
jobs(status, queue, available_at, priority)
jobs(idempotency_key) UNIQUE
jobs(status, reserved_until)
schedule_runs(task_name, slot_at) UNIQUE
workspace_users(workspace_id, user_id) PRIMARY KEY
sessions(user_id), sessions(expires_at)
audit_logs(workspace_id, created_at), audit_logs(actor_user_id, created_at)
projects(workspace_id, deleted_at, name)
project_members(project_id, user_id) UNIQUE
audit_logs(workspace_id, created_at)
api_usage(workspace_id, provider, created_at)
```

## Long strings

Long URLs and search queries should have a normalized/hash companion where needed for indexing.

Do not create huge indexes on arbitrary URL/query text without considering database limits.

## Historical data

Do not overwrite time-series facts where historical comparison is valuable.

Prefer snapshots and retention policies.

### Bing Webmaster Tools

Implemented in migration `000024` (1.1). Dates are Bing's (Pacific Time).

- `bing_profiles`: one per workspace, holding the encrypted `api_key` (`VARBINARY`), the cached verified `sites` (JSON), `status`, `last_error` and `checked_at`.
- `bing_project_sites`: the Bing site linked to a project, with the import `status` (`pending`/`importing`/`ok`/`error`), `last_success_at` and `last_error`.
- `bing_daily`: clicks and impressions per day; PK `(project_id, date)`.
- `bing_weekly`: query and page rows per weekly bucket; PK `(project_id, dimension, week_end, key_hash)`. `key_hash` is the SHA-256 of `key_text` (up to 2,048 characters). `position` is impression-weighted when aggregated.

- `bing_crawl_stats` (migration `2026_10_11_200000`, 0.14): crawled pages, crawl errors, pages in the index, inbound links and status code counts per day; PK `(project_id, date)`. `code_3xx` combines Bing's 301 and 302 counts.
- `bing_link_counts` (`2026_10_11_200000`): inbound links per page of the site; PK `(project_id, url_hash)`, secondary `(project_id, links)`.

Every import replaces a project's `bing_daily` and `bing_weekly` rows, because Bing always returns its full history. `bing_crawl_stats` and `bing_link_counts` are replaced together by their own best-effort step. Linking another site deletes all five data tables' rows. All tables cascade on project or workspace deletion.

### IndexNow

Implemented in migration `000025` (1.1).

- `indexnow_settings`: one row per project, holding:
  - `api_key`, in plain text, because it is public by design;
  - the verified `host`, `key_path` (`/` for the site root, or a subdirectory such as `/blog/` whose key only covers URLs under it; added in migration `2026_10_09_000100`) and `key_verified_at`;
  - `sitemap_url` and `auto_submit`;
  - `sitemap_checked_at` and `last_error`.
- `indexnow_urls`: URLs seen in the sitemap; PK `(project_id, url_hash)`. Each row has `lastmod` as written in the sitemap, plus `first_seen_at` and `submitted_at`.
- `indexnow_submissions`: the log, with `source` (`manual`/`sitemap`), `url_count`, the HTTP `status_code` and `result`.

All tables cascade on project deletion.

### Site audit

Implemented in migration `000028` (1.2).

- `site_audit_settings`: one row per project, with `max_pages` (100 to 2,000) and `weekly`.
- `site_audits`: one row per audit, with:
  - `status` (`queued`/`running`/`completed`/`failed`/`cancelled`) and `trigger_type` (`manual`/`scheduled`);
  - `start_url` and `max_pages`;
  - the progress, `pages_crawled` and `pages_found`;
  - the result: `health_score` and the `errors`, `warnings` and `notices` counts.
- `site_audit_pages`: every URL the audit found, unique per audit by `url_hash` (SHA-1 of the URL). It is both the crawl frontier (`state` = `pending`) and the result: the status code, content type, redirect target, response time, size, title, meta description, H1, canonical, noindex, word count and link counts, plus the structured data types (`schema_types`, comma-separated), the Open Graph type (`og_type`) and whether an author is named (`has_author`), added by migration `2026_10_09_000200`; NULL means the page was crawled before these were recorded.
- `site_audit_links`: internal links between the pages of an audit; PK `(audit_id, from_page_id, to_page_id)`. They show where a broken page is linked from. Since migration `2026_10_17_000200` (ADR-040, F1) each row also has `anchor` (up to 160 characters, the link text or an image's alt) and `place` (`body`, `nav`, `header`, `footer`, `aside`; null for a redirect). The first occurrence of a link in the body text wins over a menu link to the same page.
- `site_audit_link_contexts` (F1): the paragraph around a body-text link (up to 400 characters, centred on the anchor); PK `(audit_id, from_page_id, to_page_id)`, index on the target. `site_audit_paragraphs` (F1): up to 25 body paragraphs and list items of at least 60 characters per crawled page, outside navigation, header, footer and asides; PK `(audit_id, page_id, position)`. `link_suggestions` (F1): source and target page, topic (`cluster_id` public UUID and label), anchor, paragraph and score per audit; unique per `(audit_id, source_page_id, target_page_id)`. Migration `2026_10_17_000300` (0.25.2) adds `site_audits.links_built_at` (UTC; set whenever suggestions are rebuilt, null until the first build). All three cascade with their audit and are deleted for every audit of the project except the newest completed one and any audit still running when an audit completes; `prune()` also clears them before deleting old audits.
- `site_audit_issues`: the issues found when the crawl ends: `type`, `severity` and `detail`.

Each project keeps its last 5 finished audits; older ones are deleted in chunks when an audit completes. Everything cascades on audit and project deletion.

### Reports

Implemented in migration `000026` (1.1).

- `report_schedules`: one row per project, with:
  - `frequency` (`weekly`/`monthly`) and the `recipients` (JSON, up to 10 addresses);
  - `enabled`;
  - `last_period`, the key of the last period sent (`YYYY-MM-DD..YYYY-MM-DD`), so each period goes out once;
  - `last_sent_at`.
- `report_deliveries`: the log, with the period, `kind` (`scheduled`/`test`), the number of recipients and failures, and the last error.

Both tables cascade on project deletion.

### Branding

Implemented in migration `000027` (1.1).

- `branding_settings`: a single row (`id` = 1), holding `product_name`, `accent_color` (`#rrggbb`), `report_footer`, `logo_file` (the random file name in `storage/branding/`), `logo_version` (busts caches) and `updated_by`.
- Empty values fall back to `APP_NAME` and the default logo and colours.

## Additional page integrations

Page improvement summaries need no new tables or migration. `PageRepository::importedPageDays` counts existing `search_console_import_days` markers in a project/date range, including successfully imported empty days. A Google click comparison is unavailable unless both 28-day windows have every marker and the URL has page metrics in both windows. Project-wide date spans alone cannot establish complete page imports.


The Pages catalog declares `CHARACTER SET utf8mb4` on its `JSON_TABLE` backlink URL column before applying `utf8mb4_bin`. JSON table columns must not inherit a host database's Latin-1 default: MariaDB otherwise rejects the collation and the Pages list fails. This is a query fix; existing tables and data do not need conversion or a migration.

### Page integrations and additional local locations (Unreleased)

- `000043`: `crux_settings` (workspace API key) and `web_vitals` (latest exact URL/hash field metrics and collection window per project). Since 0.26.0 the same encrypted key also runs PageSpeed Insights lab tests.
- `2026_10_18_000100` (ADR-040, F2):
  - `lab_profiles`: internal id and public UUID, workspace and project (cascading FKs), exact `url` with `url_hash` (SHA-256), `device` (`mobile`, `desktop`), `source` (`pagespeed`, `dataforseo`), `weekly`, `next_run_at`, `created_by`, `created_at`. Unique per project, URL hash, device and source; index `(weekly, next_run_at)` for scheduling.
  - `lab_runs`: internal id and public UUID, profile (cascade), project and workspace, `status` (`queued`, `running`, `done`, `failed`), `trigger_type` (`manual`, `weekly`), `requested_by`, DataForSEO `task_id`, `polls`, `score` (0–1), `lcp_ms`, `cls`, `tbt_ms`, `fcp_ms`, `si_ms`, `lighthouse_version`, `error`, `cost`, `created_at`, `finished_at`. The newest 50 finished runs per profile are kept. Lab data is stored apart from CrUX field data.
- `2026_10_18_000200` (ADR-041, G1):
  - `article_idea_runs`: internal id and public UUID, workspace and project (cascading FKs), `status` (`queued`, `running`, `done`, `failed`), `market` (locale key), `competitors` (JSON list of domain and found), `estimated_cost`, `requested_by`, `error`, `created_at`, `finished_at`.
  - `article_competitor_rows`: the bought rows of a run (cascade): domain, found, url, keyword, volume, difficulty, position, traffic, avg_rd (average referring domains of the results page). Only the newest two finished runs per project keep their rows.
  - `article_ideas`: the built ideas per project (unique `project_id`, `idea_key` = SHA-256 of the topic label), `kind`, `main_keyword`, `score`, `payload` (JSON with keywords, competitor examples, your page, prediction and reasons), `built_at`. Replaced as a whole on every build.
  - `article_idea_dismissals`: hidden ideas per project and idea key, with main keyword, user and time. Kept apart, so a rebuild never brings them back.
- `2026_10_18_000300` (ADR-041, G1 part 2): `article_idea_outlines`, one row per project and idea key (unique), workspace and project (cascading FKs).
  - `status`: `queued`, `done` or `failed`.
  - `pages`: JSON list of page structures (url, state, reason, title, H1, H2/H3 headings, question headings, word count).
  - `own_page`: the same for your page on an update.
  - `error`, `requested_by`, `requested_at`, `fetched_at`.
  - Competitor text is never stored.
- `2026_10_19_000100` (ADR-041, G3): `article_idea_tracking`, one per project and idea key (unique), with public UUID, workspace and project (cascading FKs).
- `2026_10_23_000100` (ADR-042, 0.32.0): conversion diagnostics. Four tables:
  - `business_events`: the GA4 events a project counts as success. Public UUID, workspace and project (cascade), kind (`purchase`, `lead`, `subscription`, `other`), exact GA4 event name (ASCII, unique per project) and label. At most ten per project.
  - `ga4_event_days`: per project, event name and date, the GA4 `eventCount` site-wide and in Organic Search sessions. Every imported date has a row, zero included, so a covered day is known and a missing day is never read as zero.
  - `ga4_event_imports`: the last event import per project (period, property time zone, row-limit and threshold flags, `fetched_at`), plus the last failure (`error`, `error_at`). A failure keeps the last successful period.
  - `business_event_checks`: the owner's test-conversion checks (status `queued`/`done`/`failed`, note, the realtime counts per event as JSON, requested and finished times in UTC).

  Removing an event deletes its counts. Relinking the GA4 property clears `ga4_event_days` and `ga4_event_imports` with the landing-page history.
- `2026_10_23_000200` (ADR-042, 0.33.0): `content_items`, the CMS records of the content inventory, entered by hand or imported from CSV (SEOcc has no CMS connection). Each record has:
  - a public UUID, workspace and project (cascade);
  - a title, an optional address with its matching-key hash, and a status (`draft`, `scheduled`, `published`, `retired`);
  - an optional publish date, CMS ID (a re-import updates by it) and note;
  - `linked_url`, set only when a person confirms which page a record without an address is;
  - `version`, for optimistic concurrency, and created/updated times.

  At most 5,000 per project. Crawls, sitemaps, Search Console, briefs, published articles, topic plans and topic owners stay in their own tables. The inventory joins them when read (bounded at 5,000 rows per source), and each keeps its own status and date.
- `2026_10_23_000300` (ADR-042, 0.34.0): claim verification on content briefs.
  - `brief_claims`: public UUID, workspace and project (cascade), the brief's public UUID, the exact claim and the content it appears in, `claim_scope` and `source_scope` (JSON: breeder, cultivar, version, market, sample), source URL, publisher, date checked, supporting passage or location, the person's verdict `status` (`unreviewed`, `supported`, `partly_supported`, `contradicted`, `outdated`, `unverifiable`), reviewer note, `reviewed_by`/`reviewed_at`, and `version` for optimistic concurrency. At most 100 per brief.
  - `brief_claim_revisions`: every earlier version of a claim as a JSON snapshot, with who replaced it and when (cascade with the claim).
  - Automated flags are computed when read and are never stored as a verdict.
- `2026_10_23_000400` (ADR-042, 0.35.0): internal-link classes.
  - `site_audit_links` gains `link_class` (`editorial`, `related`, `product`, `template`, `other`) and `link_confidence` (`high`, `medium`, `low`).
  - `site_audits` gains `links_left_out`: matches the last suggestion build dropped because the paragraph is about another purpose.
  - The columns are only added when missing. Audits crawled earlier keep NULL ("not recorded"), and an unclassified body link is treated as text, as before.
- `2026_10_25_000100` (0.42.0): `site_audit_pages.source_links` (SMALLINT, nullable): other sites linked from the page's text, once per site, for the "article without source links" notice. It is only added when missing; earlier pages stay NULL (unknown).
- `2026_10_24_000600` (ADR-042, 0.40.0): change bundles.
  - `change_bundles`: public_id, workspace and project (cascade), title, url, status, `data` (JSON: problem, evidence, edits, expected, validation, baseline, approval hash and time, guard, deployment, verification, rollback, linked SEO change, pending check), version, created_by (set null), and created and updated times.
  - `change_bundle_events`: the bundle's history (kind, detail, user, time), cascading with the bundle.
- `2026_10_24_000500` (ADR-042, 0.39.1): `measurement_plans.goal` (DOUBLE, nullable), an after-only target for the primary metric. It is only added when missing; earlier plans stay NULL.
- `2026_10_24_000400` (ADR-042, 0.39.0): `measurement_plans`, one per SEO change (unique `annotation_id`, cascading with the change, project and workspace). It holds:
  - `public_id`, objective and metric;
  - target pages, target queries and control pages (JSON lists);
  - days, review dates (JSON) and timezone;
  - `baseline` (JSON snapshots with source completeness, nullable) and `frozen_at`;
  - `version`, `updated_by` (set null), `created_at` and `updated_at`.
- `2026_10_24_000300` (ADR-042, 0.38.0): `lab_runs.diagnostics` (MEDIUMTEXT, nullable), bounded JSON explaining the run, read from the Lighthouse report it received. It holds the LCP element and phases, render-blocking resources, top diagnostics, requests by type, the largest requests, the test configuration, and the raw report's SHA-256 and size. The column is only added when missing; earlier runs stay NULL.
- `2026_10_24_000200` (ADR-042, 0.37.0): indexing history.
  - `google_inspection_history`: every successful URL Inspection result per project, binding and URL hash (verdict, coverage, indexing state, robots, last crawl, both canonicals, check time), at most 30 per URL, cascading with the project.
  - `indexing_requests`: requests a person made in Search Console and recorded, with `public_id`, workspace and project (cascade), URL and hash, `channel`, `requested_on` (project-local DATE), evidence, note, `created_by` (set null) and `created_at` (UTC).
- `2026_10_24_000100` (ADR-042, 0.36.0): `overlap_reviews`, a person's decision on one overlap candidate. Each row has workspace and project (cascade), the candidate key (sha256 of kind and sorted members, unique per project), kind, members (JSON), decision (`undecided`, `keep_separate`, `merge_planned`), the retained address for a planned merge, a note, `version` for optimistic concurrency, and who decided when. The candidates themselves are computed when read and never stored. SEOcc changes nothing on the site.
  - The snapshot when published: `main_keyword`, `keywords` (JSON), `kind`, `level`, `prediction` (JSON traffic and months).
  - `url`, `published_on` (DATE), `source` (`manual` or `brief`), `created_by`, `created_at`, `updated_at`.
  - The measurement: `result` (JSON weeks with clicks, impressions and weighted position; `top10_week`, `best_position`, `clicks_28d`) and `checked_at` (indexed, for the daily check).
  - Marking an idea again replaces address, date and source and clears the result; the snapshot stays.
- `2026_10_18_000500` (ADR-041, G2): `topic_plans`, one per project and pillar idea key (unique), with public UUID, workspace and project (cascading FKs).
  - `pillar_keyword`.
  - `members`: JSON list of type `idea`, `search` or `page`, with key, label, volume and kept.
  - `links`: JSON with internal links, links from your pages and the audit date; `links_status`, `links_built_at`.
  - `backlinks`: JSON per competitor page with referring domains, backlinks and rank; `backlinks_status`, `backlinks_error`, `backlinks_at`, `backlinks_cost`.
  - `created_by`, `created_at`, `updated_at`.
- `2026_10_18_000400` (0.28.1): marks outlines read before 0.28.1 as `failed` with a note, so they can be read again at once with the main-content reader. Nothing is deleted.
- `000044`: `ga4_settings` (workspace service-account credential), `ga4_project_properties` (property and import metadata), `ga4_landing_pages` (daily rows by project/date/exact URL hash). Originally a 28-day snapshot; `000048` adds retained history. Changing the property clears its old rows; an in-flight report can only write while its original property is still linked.
- `000045`: forward-only corrective migration changing the new CrUX key and GA4 credential columns to `BLOB`, preserving encrypted binary envelopes. Never edit an applied migration.
- `000046`: `local_locations` (project/public UUID, CID, coordinates, language, keyword list and weekly opt-in), `local_location_checks` (daily resumable keyword results and profile), `local_reviews` (latest normalized review sample, submission/poll state and task ID). Review keys are a location UUID or `primary`; every lookup is scoped by project. Removing a branch removes its checks and reviews. Primary location tables and history are unchanged. Hard project deletion cascades through these tables; background due lists exclude soft-deleted projects and workspaces.

CrUX keys use AAD `crux.key:{workspace_id}`; GA4 credentials use `ga4.credentials:{workspace_id}`. Both participate in existing secret rotation. Review samples contain at most 100 reviews with text/replies capped at 10,000 characters each; they are not the raw provider response archive.

### Remaining feature gaps (Unreleased, 2026-10-05)

- `000047`: `project_alert_thresholds`, one project-owned row; absent rows retain the previous fixed defaults.
- `000048`: `ga4_import_days`, project/date coverage with currency, timezone and quality flags. Existing snapshot dates are backfilled, including empty imported days. Imports atomically replace only their requested dates and retain older dates. Reads select the current currency/timezone; gaps cannot be inferred from MIN/MAX dates. Relinking clears both rows and coverage.
- `000049`: `site_audit_resources` deduplicates up to 500 target hashes per audit; `site_audit_resource_refs` binds each sampled image/script/stylesheet/external target to its referring page. Audit deletion cascades through both tables.
- `000050`: `local_location_grids`, branch/public UUID, keyword, date, dimensions, JSON point results and status. Creating a run locks the branch and rejects an existing queued grid. Null means checked/not found; an absent JSON key is unfinished. Branch deletion cascades through grids.
- `000051`: `workspace_report_branding` holds optional name/accent/footer and bounded image bytes/MIME; `project_report_sections` stores a validated nonempty section list. Workspace/project ownership and cascade deletion follow existing tables.
- `000052`: `project_alert_webhooks` holds one versioned project destination and configuring user, with encrypted binary URL and optional bearer token. AAD is `project_alert_webhooks.{column}:{public_id}` for `destination` and `bearer_token`; both are registered for rotation. `alert_webhook_deliveries` claims an alert once before transfer and stores only status, HTTP code, sanitized message and UTC timestamp.

All six migrations are additive and forward-only. Existing primary local tables, alert fingerprints, reporting data and installation branding remain compatible. Back up before installing the release. Rolling application downgrades after using new history are not a supported rollback; restore the matching database/code backup instead.

## SEO change annotations (0.9.41, 2026-10-05)

Migration `2026_10_05_000053_create_seo_annotations.php` adds `seo_annotations`: internal ID, public UUID, workspace/project foreign keys, `changed_on` DATE, kind, 120-character title, bounded plain-text note, nullable exact URL and SHA-256 hash, nullable creating user, UTC created/updated/deleted timestamps. Workspace/project deletion cascades; deleting the author sets the reference to null. InnoDB/utf8mb4 conventions apply.

Every lookup includes workspace/project and excludes `deleted_at`. Date indexes support bounded timelines; page reads compare URL hash and binary URL equality to preserve path/query case. A null URL means site-wide context. Removal soft-deletes the note and records an audit event. This additive forward-only migration changes no existing rows or applied migration. Restore a matching code/database backup for rollback.

## AI answer checks (Unreleased)

Opportunity intelligence (migration `2026_10_10_000300`, ADR-034):

- `project_business_profiles` — one row per project: `business_model`, `goal`, `description`, `audience`, `offerings` and `exclusions` (JSON lists), `max_difficulty`, `max_strength` (NULL = default), `updated_by` (SET NULL), `updated_at`. Cascades with the project.
- `opportunity_keyword_ratings` — the team's business potential per keyword: `project_id`, `keyword_hash` (SHA-256 of the normalized keyword), `keyword`, `score` (0–3), `updated_by`, `updated_at`. Primary key (project, hash).
- `keyword_serps` — the latest results page per project keyword and device: `checked_on`, `results` (JSON top 10: position, domain, URL, title up to 200 characters), `features`, `ai_overview`. One row per keyword and device, never older than the stored check; cascades with the keyword and the project.
- `research_results.kind` also takes `suggestions` (keyword suggestions per seed; `params` holds seeds and modifiers), `serp_strength` (rows `{d: domain, r: 0–100}`) and `seed_ideas` (one row with AI-suggested seeds, modifiers and a note). Research keyword rows may carry `s` (SERP feature types) and `r` (average referring domains of the top 10).

Research kind `llm_answers` (migration `2026_10_09_000300` extends the `research_results.kind` ENUM). `params` stores the prompts (up to 10), the brand name and the platforms; `items` stores per answer the prompt, platform, model, web-search flag, brand/own-site/competitor analysis, up to 30 cited sources and the answer text (up to 4,000 characters).

## Page library exports (Unreleased)

`page_exports` records queued CSV exports of the Page library above the 10,000-URL direct-download limit: internal `id`, `public_id`, `project_id`/`workspace_id` (cascade on delete), nullable `requested_by` (set null when the user is deleted), the normalized search, sort and 28-day range, `status` (`queued`, `running`, `ready`, `failed`), `total_urls` at request time, `row_count`, `file_bytes` and the keyset cursor (`cursor_key`, binary-collated `cursor_url`) recorded after each chunk, a fixed `error` message, and UTC `created_at`/`updated_at`/`completed_at`/`expires_at`. Lookups are always scoped by project. The CSV itself is written to `storage/tmp/page-exports/{public_id}.csv`, outside the web root, never to the database. Cleanup deletes records and files 24 hours after completion or failure.

## Encrypted columns

Encrypted secrets are stored in `VARBINARY`/`BLOB` columns (never in `TEXT` with implicit charset conversion) using this envelope:

```text
[1 byte format version][2 bytes key id][24 bytes nonce][ciphertext + tag]
```

The additional authenticated data (AAD) binds the value to its location, e.g. `connection_tokens.refresh_token:{public_id}`, so an encrypted value copied into another row fails to decrypt. See `SECURITY.md` → *Secret encryption*.

## Brand-name mention storage (Unreleased)

Additive migration `2026_10_05_000054_create_brand_mention_tracking.php` adds `brand_mention_settings` (one per project, workspace, bounded public name, Google market/language, explicit weekly opt-in, configuring user, last attempted time) and `brand_mention_checks` (public UUID, workspace/project, configuration SHA-256, status/step, compact metrics/answers JSON, bounded error, requesting user, scheduled flag and UTC timestamps). InnoDB/utf8mb4 conventions apply; workspace/project/user references cascade on hard deletion. No applied migration or existing table is changed.

Reads, conditional claims, advances and failures include workspace and project IDs. Check creation locks the project settings row and rejects another pending check for the same configuration updated in the last 24 hours. The persisted claim prevents duplicate deliveries from repeating an ambiguous paid request. Due scheduling excludes soft-deleted projects/workspaces. A name/market/domain change leaves earlier history intact; retention is project-owned with no new purge policy. Rollback requires a matching code/database backup.

A new check after the pending guard expires supersedes older queued/processing checks for the same configuration. Their late deliveries cannot make further paid requests; already sent requests may still be billed.

## Queue and cleanup indexes (0.10.2)

Migration `2026_10_10_000100` adds three indexes and no columns:

- `jobs_claim_order (status, queue, priority, available_at)`: a worker's claim walks this index and locks only the job it takes (QUEUE_AND_CRON.md -> Atomic job claiming). The older `jobs_claim` index stays in place.
- `sessions_last_activity`: the idle-session cleanup is a range delete.
- `password_resets_expires`: expired reset tokens are deleted one day after expiry.

Connections set the character set, UTC time zone and strict SQL mode in one statement and give up connecting after 5 seconds. CLI runs (cron) raise the session `wait_timeout` to at least 600 seconds, so a host's short idle timeout cannot drop the connection during a slow provider call.

## Quest Log progress (0.13.0)

Migration `2026_10_11_124355_create_quest_tables.php` adds three tables (ADR-035). Quest definitions are code; only progress is stored, per user per workspace. User and workspace references cascade on deletion. No row is exposed by id, so there is no `public_id`.

- `quest_profiles`: one row per user and workspace (unique), with `hidden` (the Quest Log card is hidden), `seen_quests` (JSON list of quest keys visible at the last view, for "New quests available") and `checked_at` (indexed; the hourly re-check takes the oldest first).
- `quest_progress`: unique `(user_id, workspace_id, quest_key, step_key)`. `step_key` `''` is the quest itself; Weekly Check-ins use `week-YYYY-Www`. `completed_at` is written once and never cleared (sticky); `skipped_at` can be cleared.
- `quest_ranks`: unique `(user_id, workspace_id, chapter)`, `earned_at` (never removed) and `announced_at` (the rank toast was shown).

Completion checks read other modules' tables only through each module's own evidence reader: indexed existence queries bound to `workspace_id`, the user's visible project ids and, where the table records it, the acting user (`created_by`, `changed_by`, `updated_by`, `started_by`, `user_id`).

## AI visibility readiness (0.43.0)

Migration `2026_10_25_000200`:

- `ai_crawler_checks`: the latest AI crawler check per project (PK `project_id`, cascades on project deletion). It stores the final `robots_url` after redirects, `robots_state` (`ok`, `missing` for a 4xx answer, `unreachable` for 5xx, 429 or a network error) and `robots_status`. `agents` is JSON: one entry per AI crawler token with `state` (`allowed`, `partial`, `blocked`), `named` (a robots.txt group names it) and `blocked` (top Search Console paths it may not fetch). It also stores `llms_state` (`found`, `missing`, `unreachable`), `llms_status`, `llms_bytes` and `checked_at`. Each check replaces the row.
- `site_audit_pages.citation_signals`: JSON with `questions`, `tables`, `lists`, `figures`, `lead_words` and `updated`. Pages crawled before stay `NULL`, which means unknown.
- `ga4_ai_referrals`: GA4 sessions, key events and purchase revenue per project, day and AI assistant session source; PK `(project_id, date, source)`. An import replaces the rows inside its window and keeps older days.
- `ga4_ai_referral_imports`: the latest AI referral import window per project (`period_from`, `period_to`, `currency`, `limited`, `fetched_at`), or its last `error`.

All tables cascade on project deletion.

## Local business details (0.46.0)

Migration `2026_10_26_000100`:

- `site_audit_pages.business_signals` (VARCHAR(1000) NULL): JSON with `types`, `name`, `phones` and `address`. It holds the first structured-data node with an address or telephone (Person, PostalAddress and ContactPoint nodes are skipped). `phones` are digits with a leading `+` kept, from that markup and from `tel:` links, at most 5. A parsed HTML page always gets a value, with empty lists when it states nothing. Pages without parsed HTML, and pages crawled before 0.46.0, stay `NULL`, which means unknown.
