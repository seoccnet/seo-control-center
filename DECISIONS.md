# Architectural Decisions

These decisions are intentional. Change them only with a documented reason.

## WordPress IndexNow companion — 2026-10-08

The companion submits opted-in public URL changes directly to IndexNow using the project's existing public key. It has no SCC write API or shared login credential; result history remains local to WordPress. A virtual key endpoint avoids modifying hosting-root files. Root HTTPS Home addresses and single-site installations bound the first slice; multisite and subdirectory Home routing require a separate lifecycle design. WP-Cron handles one URL per deduplicated event with a 10-second transport timeout and three delayed retries. SCC packages contain only the explicit companion ZIP under resources; WordPress core and tests never enter application releases. Package sources live outside the app runtime and are rebuilt with the dedicated builder.

## Pages full-library CSV bound — 2026-10-08

All-matching Pages downloads use one repository query capped at 10,001 rows and reject selections over 10,000 rows. This keeps synchronous shared-host exports bounded and prevents silently incomplete downloads. The service checks the count first and checks returned rows again to cover additions between count and selection. Existing page exports remain limited by UI pagination. A durable queued export lifecycle for larger libraries is deferred until required; no queue tables or temporary customer-data artifacts are introduced here.

## ADR-001 — Modular monolith

**Decision:** Build as a modular monolith.

**Reason:** Shared-hosting compatibility, operational simplicity, and lower deployment/support cost.

## ADR-002 — Database-backed queue

**Decision:** MariaDB/MySQL is the required queue backend.

**Reason:** Shared hosting cannot be assumed to provide Redis, RabbitMQ, or daemon workers.

## ADR-003 — Server-rendered frontend

**Decision:** Server-rendered HTML plus Vanilla JavaScript ES modules.

**Reason:** Smaller runtime, simpler deployment, better shared-hosting fit, and lower frontend complexity.

## ADR-004 — Provider abstraction

**Decision:** Google/DataForSEO-specific models do not become the internal domain model.

**Reason:** Provider replacement, testing, normalization, and future extensibility.

## ADR-005 — Historical snapshots

**Decision:** SEO measurements are generally snapshot/time-series data instead of destructive overwrites.

**Reason:** Trend analysis is a core product capability.

## ADR-006 — Rule-based opportunities first

**Decision:** Opportunity detection is deterministic/rule-based before AI augmentation.

**Reason:** Explainability, reliability, cost control, and testability.

## ADR-007 — MCP read-only initially

**Decision:** First commercial MCP release is read-only.

**Reason:** Reduce risk of AI-triggered destructive or billable actions until authorization and consent workflows mature.

**Amended by ADR-036 (2026-10-15):** scoped, audited, non-billable writes on existing records are allowed. Billable and destructive actions stay outside MCP.

## ADR-008 — Signed licensing and updates

**Decision:** License entitlements and release metadata/packages are cryptographically verified.

**Reason:** Stronger commercial controls and supply-chain safety without relying on obscurity.

## ADR-009 — No destructive license enforcement

**Decision:** License expiry/revocation never deletes customer data.

**Reason:** Reliability, trust, and supportability.

## ADR-010 — Customer data stays local by default

**Decision:** SEO/project data remains on the customer's server unless a feature explicitly requires an external service.

**Reason:** Privacy, differentiation, and self-hosted product expectations.

## ADR-011 — Minimum database versions

**Decision:** Supported databases are MariaDB 10.6+ and MySQL 8.0+, using InnoDB, `utf8mb4` / `utf8mb4_unicode_ci`, and strict SQL mode.

**Reason:** Concurrency-safe job claiming relies on `SELECT … FOR UPDATE SKIP LOCKED`, which requires MariaDB 10.6+ or MySQL 8.0+. These versions also provide reliable JSON functions, window functions, and CTEs for analytics queries. Older versions are end-of-life and increasingly rare on maintained shared hosts.

**Implications:** The installer and System Health check the server version and refuse to install on unsupported versions. There is no fallback claiming strategy for older databases.

## ADR-012 — PHP 8.5 minimum

**Decision:** PHP 8.5 is the minimum supported runtime, as set by the product owner.

**Reason:** A single modern runtime keeps the code simple, typed, and free of compatibility shims, and gives the longest security-support window for a product launching in 2026/2027.

**Implications:**

- Some shared hosts lag behind new PHP releases. The installer's compatibility check must fail clearly and explain how to select PHP 8.5 (most cPanel/CloudLinux/Plesk hosts offer a per-site PHP version selector).
- The CLI PHP used by cron may differ from the web PHP version. The installer must show the exact cron command including a versioned binary path where the host requires it, and System Health must report the CLI version seen by the last cron heartbeat.
- Re-evaluate before 1.0.0 using real customer hosting data. Lowering the floor requires a new ADR.

## ADR-013 — Workspace-scoped roles with project restriction

**Decision:**

- A user's permissions come from **one role per workspace** (`workspace_users.role_id`).
- Workspace roles `owner` and `admin` have access to all projects in the workspace.
- Other roles (`member`, `viewer`, and later `client`) access only projects they are assigned to through `project_members`.
- A `project_members.role_id` may **narrow** permissions for that project (e.g. a workspace `member` who is `viewer` on one project). It can never grant permissions the workspace role does not have.
- Installation-level administration (updates, license, system health, global settings) uses a separate **system role** on the user (`users.system_role`), checked through the same permission service, not ad-hoc `isAdmin()` calls.
- System roles are seeded and immutable in 0.x. Custom workspace roles are a later feature (roles table already supports `workspace_id`).

**Reason:** Agencies need to restrict staff and clients to specific projects, while solo users should never have to think about project membership. "Narrow only" keeps effective permissions easy to reason about and test.

**Effective permission rule:**

```text
effective(user, project) =
    workspace_role_permissions(user, project.workspace)
    ∩ (project_role_permissions(user, project) if a project role is set, else all)
  — and empty if the workspace role is not owner/admin and the user is not a project member
```

**Amendment (0.44.2, finding 40): links to a project in another of your workspaces.** A new session starts in the user's first workspace, so links from e-mails, reports and bookmarks to a project in another of the user's workspaces answered 404. `WorkspaceContextMiddleware` now catches a 404 on a `GET` or `HEAD` request for `/projects/{id}…`. When the project is in another workspace of the user and `canSeeProject` allows it, the session switches there and the browser is redirected to the same address with a notice. Invisible projects still answer 404, and write requests are never redirected.

## ADR-014 — Master key ring stored outside the database

**Decision:** Secrets are encrypted with libsodium XChaCha20-Poly1305 (IETF AEAD) using a versioned **key ring** loaded from a file or environment, never from the database.

**Reason:** Key versioning is required for rotation, and shared hosts often cannot set environment variables, so a file-based option is necessary.

**Details:** See `SECURITY.md` → *Secret encryption* and `ENVIRONMENT.md` → *Encryption keys*.

## ADR-015 — Lease-based locks in the database

**Decision:** The scheduler lock and other named mutexes use a `locks` table with an owner token and expiry (a lease), not `GET_LOCK()` or file locks.

**Reason:**

- `GET_LOCK()` is bound to one database connection, and some hosts/proxies recycle or pool connections unpredictably.
- File locks (`flock`) are unreliable on some network filesystems used by shared hosts.
- A lease row survives across connections, expires automatically if a cron process is killed, and is visible in System Health.

**Details:** See `QUEUE_AND_CRON.md` → *Locks*.

## ADR-016 — In-house kernel with small PSR components

**Decision:**

- **Container:** in-house PSR-11 container with explicit factory registration per module. No reflection autowiring in 0.x.
- **HTTP messages:** PSR-7 / PSR-17 via `nyholm/psr7` and `nyholm/psr7-server`.
- **Middleware:** in-house PSR-15 pipeline.
- **Routing:** `nikic/fast-route` behind an application-owned `Router` interface.
- **Logging:** in-house PSR-3 JSON-lines logger with a central redaction processor.
- **No full-stack framework** (Laravel, Symfony full-stack, Slim app skeleton).

**Reason:** These are small, widely used, low-dependency components. Wrapping them behind application interfaces keeps SEO Control Center in control of its architecture and allows replacement. Explicit container wiring avoids "magic" and keeps boot fast on shared hosting.

**Implications:** Each dependency must be confirmed to install and pass the test suite on PHP 8.5 before it is added to `composer.json`. If a package is not PHP 8.5-clean, replace it with an in-house implementation behind the same interface rather than pinning an unmaintained version. New runtime dependencies beyond this list follow the dependency rules in `CODING_STANDARDS.md`.

## ADR-017 — Development tooling

**Decision:** PHPUnit, PHPStan, and PHP CS Fixer are the required development tools. They are `require-dev` only and never needed in production.

**Reason:** Tests, static analysis, and consistent formatting are needed to keep a security-sensitive commercial codebase maintainable. Details are in `CODING_STANDARDS.md` and `TESTING.md`.

## ADR-018 — Forward-only migrations

**Decision:** Migrations only have an `up()` step. There are no `down()` migrations. Applied migrations are never edited; the migrator stores a SHA-256 checksum of each file and `migrate:status` flags files changed after they were applied.

**Reason:** MariaDB/MySQL DDL causes implicit commits, so a failed multi-step migration cannot be rolled back reliably, and hand-written `down()` steps are rarely tested and can destroy customer data. Recovery uses the updater's restore point (UPDATES.md) and a new corrective migration.

**Implications:** Prefer backward-compatible sequences (add column → backfill → switch code → remove old column in a later release). A migration run takes the `migrations` lease lock, stops at the first failure, and records only migrations that completed.

**Recovery repair (0.9.49, 2026-10-06):** The owner requested remediation of interrupted migration/update recovery and authorized deploying the reviewed repair. Existing migration files receive a one-time replay-safety correction without changing their resulting schema. `LegacyChecksums` recognizes only exact old-to-repaired SHA-256 pairs verified against 95 local release archives; already-applied migrations are never replayed. All other checksum changes or removed applied files stop the run. Future released migration files remain immutable and every `up()` must tolerate retry after any statement. A connection-owned advisory lock now covers long DDL alongside the existing lease. Recovery retains the original database backup and signed package identity; automatic `down()` or whole-release transactional rollback is not introduced.

## ADR-019 — Database-backed sessions with hashed tokens

**Decision:** Sessions are stored in the `sessions` table through an application `SessionStore`, not PHP's native `$_SESSION` / `session_start()`. The cookie carries a random 256-bit token; only its SHA-256 hash is stored.

**Reason:**

- Shared hosts often share or aggressively garbage-collect the PHP session directory.
- Users and administrators need to see and revoke sessions (sign out everywhere, disabling a user).
- Native sessions rely on global state (`$_SESSION`), which the architecture avoids and which makes tests awkward.
- Hashing tokens means a database leak does not expose usable session cookies.

**Implications:** Expired rows must be purged by a scheduled cleanup job (foundation slice 3, with the cron runner). Guest sessions are only written when data is stored.

## ADR-020 — MFA follows the encryption key ring

**Decision:** TOTP MFA and recovery codes are implemented in foundation slice 3, together with the encryption key ring, not in slice 2 with password login.

**Reason:** TOTP secrets must be encrypted at rest (SECURITY.md). Storing them before the key ring exists would mean either plaintext secrets or a throwaway encryption scheme. The session model already supports an additional verification step before `Session::login()` completes.

**Implications:** Until slice 3, accounts are protected by password, throttling and session controls only. MFA must be complete before 0.1.0 is tagged.

## ADR-021 — Development license mode

**Superseded by ADR-052 (2026-11-06).** There is no licence check any more.

**Decision:** When no license is activated and `APP_ENV` is `local` or `testing`, the license status is `development`: all features are enabled and limits are unlimited. In `production` the same situation is `missing` and nothing licensed is available until a license is activated.

**Reason:** Developers and CI must be able to run the full product without a vendor control plane, and the vendor control plane does not exist yet. Production can never fall into development mode because `APP_ENV=production` disables it in code (`bootstrap/app.php`), and debug output is also forced off in production.

**Amendment (0.2):** `APP_ENV=staging` also allows development mode, so test servers (for example a subdomain used before go-live) run all features without a license. Staging otherwise behaves like production: debug output is forced off and HSTS is on by default.

**Implications:** Anyone can set `APP_ENV=local` on their own server — which is consistent with ADR-052. The value of a real license is updates, support and future services, not the local check.

## ADR-022 — Failed jobs stay in the `jobs` table

**Decision:** Failed jobs keep `status = 'failed'` in `jobs` (with `last_error`, `attempts`, `finished_at`) instead of being moved to a separate `failed_jobs` table.

**Reason:** One table keeps claiming, retries, inspection and cleanup simple; the claim index only covers `status = 'queued'`, so failed rows do not slow claiming. Cleanup deletes completed jobs after 7 days and failed jobs after 30 days.

## ADR-023 — Keyword metrics per workspace, latest value, fetched on request

**Decision:**

- Keyword metrics (`keyword_metrics`) are stored per workspace, not shared across the installation, and shared by the workspace's projects.
- One row per keyword and market holds the latest metrics; the provider's monthly search volumes (up to 24 months) carry the history. Rows older than 30 days are refreshed when a metrics update runs.
- Metrics are fetched only when a user adds keywords or presses *Update metrics* (`analysis.execute`), never by a scheduled task.

**Reason:**

- Metrics are bought with a workspace's own provider account (INSTRUCTIONS.md §5: tenant-owned data is scoped by `workspace_id`); sharing them would give other tenants data they did not pay for and reveal which keywords a tenant researches.
- Google Ads volumes change monthly and already include monthly history, so separate snapshots would mostly duplicate data. Rank tracking (0.4) keeps real snapshots, because positions change daily.
- Paid work that runs by itself surprises customers; budgets limit the damage but are not a substitute for consent.

**Implications:** Two workspaces tracking the same keyword pay for it separately. A scheduled monthly refresh can be added later as an explicit, per-project opt-in.

## ADR-024 — Rank tracking: opt-in scheduled checks, daily snapshots, top 10 kept

**Decision:**

- Rank tracking is off per project until a user with `analysis.execute` switches it on and chooses device and frequency (daily or weekly). Only then does the hourly `rankings.schedule` task queue paid checks. This is the explicit opt-in that ADR-023 requires for scheduled paid work.
- One snapshot per project keyword, device and UTC day. A check requests only keywords without a snapshot for that day, so re-runs, retries and "Check now" never pay twice for a day.
- Each snapshot keeps the first 10 organic results (position, domain, URL) as JSON, not the full top 100 as rows.
- Visibility is the volume-weighted share of the clicks position 1 would get, using a fixed organic CTR curve (`Domain\Rankings\Visibility`).

**Reason:**

- Rank tracking only has value when it runs by itself, but it costs money per keyword and check, so it needs consent and a visible cost estimate.
- 1,000 keywords checked daily with 100 result rows each would add 36 million rows a year per project, which is too much for shared hosting. Competitor discovery (0.5) needs the top 10 domains, which the JSON column provides.
- A fixed, documented CTR curve keeps visibility explainable and comparable over time (INSTRUCTIONS.md §13).

**Implications:** Check days are UTC dates, not the project's timezone. SERP requests use DataForSEO's live endpoint, one request per keyword. The cheaper asynchronous standard queue (task_post / tasks_ready) is a later optimization for large keyword sets.

## ADR-025 — Competitors are measured on the rank-check SERPs

**Decision:**

- A project has at most 10 competitors (domains; subdomains count, `www.` is ignored). Their positions are taken from the SERPs that rank checks already fetch (`Domain\Rankings\SerpObserver`), so tracking competitors adds no provider requests and no cost.
- A newly added competitor gets positions for the latest check day and the comparison day about 7 days earlier, from the top 10 results stored with each ranking snapshot (ADR-024). Positions beyond 10 are unknown there and count as not ranking until the next check records the full top 100. A later real check result always replaces the estimate.
- Suggested competitors are the domains that appear most often in the top 10 of the project's keywords on the latest check day.
- Gaps and overlap cover only the project's tracked keywords. A competitor must be on page one and at least 10 places ahead for a "gap".

**Reason:**

- Competitor tracking is a comparison on the same keywords and markets. Reusing the SERP makes it free, and both sides are measured at the same moment.
- Backfilling full history on add would scan every stored snapshot inside a web request, which is too slow on shared hosting. Two days are enough for the dashboard's current value and change.
- The keywords a competitor ranks for that the project does not track can only come from a paid provider (DataForSEO Labs). That is a separate, opt-in feature.

**Implications:** Competitor history starts when the competitor is added. The limit of 10 keeps each rank check at one extra small insert per keyword.

## ADR-026 — Commercial model: yearly licence, three editions, Paddle, own license server

**Superseded by ADR-052 (2026-11-06).** Kept as history.

**Decision (owner, 2026-09-29):**

- **Yearly licence.** `valid_until` is the paid-up date. After it passes, and after the 14-day grace period, the installation keeps working read-only (ADR-009). Licensed work stops: imports, rank checks, metrics, analyses, MCP and updates. Renewal extends `valid_until` by one year.
- **Three editions**, differing by limits, never by code paths (FeatureManager):

  | Edition | `projects` | `workspaces` | Features |
  |---|---|---|---|
  | Starter | 3 | 1 | all (until 2026-09-30: all except `mcp`) |
  | Pro | 15 | 3 | all |
  | Agency | unlimited | unlimited | all |

  "All" is `search_console`, `rank_tracking`, `keyword_metrics`, `competitors`, `opportunities`, `mcp` and `updates`.
- **Amendment (owner, 2026-09-30):** Starter includes `mcp` as well, so the editions now differ by limits only. Starter licences receive the feature at their next entitlement refresh (about weekly, or *Refresh* under *System → License*). Prices: Starter $99, Pro $249, Agency $599 per year, with 50% off the first year for new customers at launch.
- **Paddle** is the payment provider and merchant of record. Paddle sells, collects VAT and sales tax, invoices and refunds. The license server creates and extends licences from Paddle webhooks. Prices live in Paddle, not in code.
- **Own license server:** a separate PHP application, not part of this repository, running on a subdomain of the vendor's hosting . It implements ADR-052). Its entitlement signing key is separate from the release signing key.

**Reason:** A yearly licence matches the existing grace and expiry behaviour and gives recurring income for updates and support. Limit-based tiers are easy to explain and already enforced. A merchant of record removes the tax and invoicing work that would otherwise dominate for a solo vendor. Self-hosting the license server keeps customer and licence data under the vendor's own control, with no additional hosting cost.

**Implications:** The `workspaces` limit must be enforced when workspaces are created. Installations need an activation screen (System → License), not only `php bin/console license:activate`. Losing the entitlement signing key means re-keying every installation, so it needs an offline backup like the release key.

## ADR-027 — E-mail: own SMTP client, settings in the database, links sent inline

**Decision (2026-10-02):**

- **Transports:**
  - **SMTP:** an in-house minimal client, no new dependency (ADR-016). It speaks plain, STARTTLS or implicit TLS, with AUTH PLAIN or LOGIN, and certificates are verified.
  - **PHP `mail()`:** for hosts that only offer that.
  - **None:** the default. No e-mail is sent, and the features that need it say so.
- **Settings:** stored in a single-row table `mail_settings` and edited under *System → E-mail* (`system.settings.manage`). The SMTP password is encrypted with the key ring (context `mail.smtp`), like other provider secrets (ADR-014). The page can send a test e-mail.
- **Inline sending:** e-mails that carry a one-time link (password reset, invitation) are sent inline in the request, with a 10-second connection timeout. Queue payloads must never contain secrets, so such mails are not queued. If sending fails, the user sees a neutral message and the error is logged (redacted).
- **Password reset:**
  - a 32-byte random token, of which only the SHA-256 hash is stored (`password_resets`);
  - valid 60 minutes and usable once;
  - requesting a new link invalidates older ones;
  - a successful reset signs out all of the user's sessions;
  - the request form always answers the same way, whether the address exists or not;
  - requests are rate-limited per IP and per address.

**Reason:** Customers run SCC on shared hosting without a shell. Without e-mail, a forgotten password locks a user out, and invitation links have to be passed on by hand. A small SMTP client covers the common hosting setups without adding a runtime dependency.

**Implications:** Later notification e-mails (alerts, reports) carry no secrets, so they may go through the queue. HTML e-mails are out of scope; messages are plain-text UTF-8.

**Amendment (2026-10-02, approved by the owner):** report e-mails may carry an HTML version next to the plain text.

- **Format:** `multipart/alternative`, plain-text part first, both quoted-printable UTF-8. `Message::$html` is the HTML version.
- **Content:** inline styles and tables only, with no images, scripts or remote resources. Every value is escaped.
- **Scope:** e-mails with one-time links (password reset, invitations) stay plain text.

**Reason:** clients read agency reports in their mail program, and tables are clearer there. A PDF attachment would need a PDF library, which is a new runtime dependency (ADR-016).

## ADR-028 — AI assistant: Claude per workspace, one model call per browser request

**Decision (2026-10-03, approved by the owner):**

- **Provider:** the floating chat (*Ask AI*) uses the Claude API (Anthropic) with the workspace's own API key. The key is stored encrypted with the key ring (context `assistant.key.{workspace_id}`, ADR-014) and managed under *Integrations → AI assistant* (`integrations.manage`). Costs go through `UsageLedger` as provider `anthropic`, with the same daily, monthly and per-request budgets as DataForSEO.
- **Exception to "no provider calls in browser requests":** a chat cannot wait for the next cron run, so the assistant calls Claude inside the browser request, under these limits:
  - **One model call per request.** When Claude asks for tools, the server runs them (local database reads only, never a paid provider call), stores the result, and answers `working`. The browser then sends the next request, so no request runs longer than one model call (HTTP timeout 60 s).
  - **At most 8 model calls per user message**, then the assistant stops and says so.
  - Requests are rate-limited per user, and each call reserves its estimated cost in the budget first.
- **Tools:** the read-only MCP tools (the same classes as `/mcp`), run with the signed-in user's own project visibility, plus one write tool, `opportunity_set_status`. Claude can only propose that write: it runs when the user clicks *Confirm* in the chat, through `OpportunityService` (permission check and audit log).
- **Storage:** conversations are stored per user and workspace (`assistant_conversations`, `assistant_messages`), so the chat survives page changes. They are deleted with the workspace or the user, and the user can start a new chat at any time.
- **Licensing:** the assistant needs the licence feature `mcp` ("AI access"), like the MCP endpoint.

**Reason:** a chat that answers after the next cron run is unusable, and shared hosting has no long-running processes for streaming workers. Limiting each request to one model call keeps it within typical PHP time limits (30–60 s). Reusing the MCP tools means the chat sees exactly what an MCP client sees, with no second data layer. Confirmation for writes keeps the user in control.

**Implications:** the assistant is unavailable when Anthropic is unreachable; the rest of the app is unaffected. No other provider calls may move into browser requests on the strength of this exception.

**Amendment (2026-10-03, approved by the owner):** a workspace chooses Claude (Anthropic) or GPT (OpenAI) for the assistant.

- **GPT adapter:** `Modules\Assistant\OpenAi\OpenAiModel` uses the OpenAI Responses API with `store: false` and function tools with `strict: false`, because the MCP schemas are not strict-mode schemas. The history stays in the Claude message shape and is translated per call.
- **Choice:** the model chosen in the settings determines the provider (`assistant_settings.provider`, migration `000037`). The key is encrypted in the same way.
- **Budget:** one assistant budget covers both providers (provider `ai_assistant` in the usage ledger, operation `assistant_chat.{provider}`).
- **Unchanged:** all other rules of this ADR (one model call per request, confirmed writes, tools) apply to GPT as well.

## ADR-029 — OAuth sign-in for MCP clients (ChatGPT)

**Decision (2026-10-03, approved by the owner):** besides bearer tokens, `/mcp` accepts a sign-in, so that ChatGPT can connect. ChatGPT's connectors support OAuth or no authentication, but not bearer tokens. SCC acts as its own small OAuth 2.1 authorization server:

- **Discovery:**
  - `/.well-known/oauth-protected-resource` (RFC 9728);
  - `/.well-known/oauth-authorization-server` (RFC 8414), with issuer identification (RFC 9207: `iss` on every authorization response);
  - the `WWW-Authenticate` header of a 401 from `/mcp` names the resource metadata.
- **Registration:** dynamic client registration (`/oauth/register`, RFC 7591). Redirect URIs must use https on a configured AI app host (`mcp.oauth.redirect_hosts`: chatgpt.com, claude.ai, claude.com), so codes can only go back to those apps. Registrations are rate-limited per IP. Public clients (`none`) and client secrets are both supported.
- **Authorization:**
  - **Grant:** the authorization code flow with PKCE S256 only. A code is valid for 10 minutes and works once; it is stored as a hash only.
  - **Approval page:** the signed-in user sees the app's name and where they will return, chooses one of the workspaces where they have `tokens.manage`, and chooses the scopes.
  - **Login:** a login on the way returns to the request (`Session::RETURN_TO`, local paths only).
  - **Error handling:** unknown apps and unregistered redirect addresses are shown to the user, never redirected.
- **Tokens:**
  - **Access token:** the token endpoint issues an ordinary MCP token (`{app} (sign-in)`, 30 days), listed and revocable under *Integrations → AI access*.
  - **Refresh token:** a rotating refresh token (180 days, single use, hash only). A refresh revokes the previous MCP token. Revoking the token in the app ends the connection, because the refresh is refused.
- **CSP:** the AI app hosts are added to `form-action`, because the approval form redirects there. The token and registration endpoints are exempt from the CSRF check; they use no cookies and authenticate the client themselves.

**Reason:** ChatGPT is the most used AI app. A sign-in is also safer for users than copying long-lived tokens, and the same flow serves Claude.ai custom connectors. Issuing ordinary MCP tokens keeps one access model, one tool layer and one revocation place.

**Implications:**
- No new dependency.
- More AI apps can be allowed by adding their redirect host to `config/mcp.php`.
- Client metadata documents (CIMD) are not implemented; ChatGPT falls back to dynamic registration.

**Amendment to ADR-028 (2026-10-03, approved by the owner):** two more assistant tools.

- **`site_audit_start`** is a second write the model can only propose. It runs after the user confirms, through `SiteAuditService::start()` (permission check, rate limit, audit log). It is free and makes no provider call; the crawl runs in the cron jobs as usual.
- **`fix_plan_save`** stores a per-page list of changes (`assistant_fix_plans`, migration `000039`) that the user downloads as CSV from a link in the chat (`GET /assistant/plans/{id}`, private to the user, only while they can still see the project).
  - **No confirmation:** it changes no SEO data and contacts nothing, so it runs without one.
  - **Limits:** at most 500 rows, each value at most 2,000 characters.

## ADR-030 — Alerts and the weekly digest from stored data

**Decision (2026-10-04):** SCC e-mails users on its own, through a new Alerts module.

- **Detection:** the hourly `alerts.scan` job only reads data that is already stored: rankings, Search Console totals, site audits, the latest `bl_lost` lookup and the DataForSEO ledger. It never starts a paid lookup or a provider call. Each event has a fingerprint (for example the date of the rank check, the audit's public ID or the ISO week of a traffic drop), and `alerts` is unique per workspace, type and fingerprint. An event is therefore recorded and e-mailed once, even when cron runs overlap.
- **Recipients:** an alert goes to the active workspace members who can see its project (`Authorizer::canSeeProject`). Budget alerts go only to members with `integrations.manage`. Each user's `notification_settings` can turn alerts off, mute alert types or mute projects. An alert is marked sent even when mail is not configured, so a later mail setup does not send a backlog. Alerts stay visible on the Notifications page for 30 days.
- **Digest:** one e-mail per user and workspace on Mondays (UTC), built with `ReportBuilder` for the latest complete week, covering at most 10 visible projects. `digest_log` makes it go out once per ISO week.
- **AI summary:** opt-in per user (`digest_ai`, off by default). The Assistant module provides the `DigestSummarizer` with one tool-less model call, which only receives the digest's figures and titles, not URLs. The call is reserved and settled against the workspace's `ai_assistant` budget (`digest_summary.{provider}`). Without an assistant, with the budget used up or after a failed call, the digest goes out without a summary.

**Reason:** users should not need to open the app to learn that something broke. Reusing stored data keeps alerts free and fast. Fingerprints, rather than time windows, make the hourly scan idempotent.

**Implications:**
- Migration `000040`.
- Thresholds (top 10, −30% from at least 100 clicks, domain rank 30+, less than 7 days of balance) are constants in `AlertDetector`. Making them per-project settings is left for later.

## ADR-031 — Opportunity action plans and growth checks from SCC's own SEO knowledge

**Decision (2026-10-09):** Every opportunity carries an action plan from SCC's own playbook library (`Domain\Opportunities\PlaybookLibrary`), written from established SEO practice and from what published SEO tests have shown to work and to fail. The product never names, scores or links an external study.

- **Plans.** One playbook per kind of finding (low CTR, quick win, ranking decline, traffic decline, content, keyword and competitor gaps, cannibalization, internal links, link building with separate plans for lost and spammy links, AI Overview citations and AI assistant mentions, local SEO, indexing, Core Web Vitals) and one per site audit issue type. Each has the same shape: when it applies, before you start, what to change, what to keep, what to measure, when to revert, plus things to avoid and ideas to consider. Steps are filled in with the finding's query and page. Plans appear on the finding, page action plans and the task page, and in their JSON and the MCP detail call.
- **Guardrails.** `SeoGuardrails` lists changes that tend to cost traffic (swapping intent words for high-volume variants, stripping synonyms from listing titles, fixed prices in titles, keyword stuffing, emoji digits, removing breadcrumbs or location maps, moving search boxes above the main content, changing URLs for keywords, mass sitewide AI text, preventive disavows, primary-category changes without a more specific option). They appear in playbook cautions, content brief notes and the AI assistant's instructions.
- **Growth checks.** The site audit records structured data types, the Open Graph type and author signals per page (migration `2026_10_09_000200`). Three new notices: no structured data (with a product/article hint), article without an author, and pages more than three clicks from the start page. They do not lower the health score; `SiteIssueRule` turns them into opportunities with a lower impact floor (20). Pages crawled before the signals existed are unknown, never "missing". An llms.txt check and an image-caption check were left out: the evidence for them is weak and the checks would be noisy.
- **Never a score.** Playbooks and guardrails never enter `ValueModel`, `OpportunityPrioritizer`, confidence, impact, effort or estimates.

**Reason:** users need concrete next steps they can test and roll back, not only a diagnosis. Writing the steps in SCC's own words keeps the guidance consistent, tailored to the finding and free of third-party content.

**Implications:**
- `StoredOpportunity` carries the stored rule `subject` so plans follow the finding's sub-kind.
- New issue types `deep_page`, `missing_structured_data`, `missing_author` (notices; issue types are VARCHAR, no enum change).
- Playbook text is product copy: changing it needs no migration, but tests pin the structure and the absence of third-party references.

**Amendment (0.41.0, 2026-10-25): how well tested, aging content, bundles.**
- **How well tested.** Each playbook carries a level from `Playbook::TESTED`:
  - `tested`: several independent published tests measured an effect;
  - `varies`: tests disagree or the effect is small;
  - `early`: one or two published tests;
  - `guidance`: technical fixes from search engine documentation.

  `PlaybookLibrary::TESTED` sets the level per plan from what published tests measured, with `guidance` as the default. It is still SEOcc's own wording, with no study named, scored or linked, and it never enters scoring. *Also consider* ideas are shown as "few published tests".
- **Refresh aging content.** `OpportunityType::ContentRefresh` via `ContentRefreshRule` finds pages that:
  - sit in an article folder, but are not an archive or the folder itself;
  - have at least 300 impressions and a position of 20 or better in the current window;
  - have a sitemap last-modified date at least 365 days old.

  The dates come from the IndexNow sitemap check (`IndexNowRepository::knownUrls`) as `ProjectSignals::$pageUpdates`, with the coverage source `page_dates` (fresh for 14 days). Pages without a date are never assumed old. The impact comes from the clicks the page earns now; no gain is estimated.
- **Bundles from opportunities.** `BundleDrafts` fills a new change bundle from an opportunity:
  - the finding gives the problem and evidence;
  - the playbook gives the expected effect, the revert rule and the edit fields (`BundleRules::FROM_PLAYBOOK`);
  - the bundle keeps the source (opportunity, playbook, metric, 28 days, query).

  On the deployment record, `ChangeBundleService` drafts a measurement plan on the created SEO change. A failure is logged in the bundle's history and never blocks the record.

**Amendment (0.42.0, 2026-10-25): source and pillar checks.**
- **Source links.** `HtmlPage::$sourceLinks` counts the distinct other hosts (www ignored) linked from body text (place `body`, class `editorial`). It is stored in `site_audit_pages.source_links`; NULL means unknown.
  - The `missing_sources` notice fires for article pages (`AuditPage::isArticle`) of at least 600 words with 0 source links. It is a growth notice: lower impact floor, no health-score cost.
  - Its plan is *tested*.
- **Pillar links.** `PillarLinksRule` reads Topics & owners (`ProjectSignals::$topics`: active topics with an owner and their candidate pages) and the panel's `bodyOutgoing`. `bodyOutgoing` holds links whose class is not template, product or other, so audits from before 0.35.0 count as text.
  - A crawled candidate page with at least 50 impressions and no body link to the crawled, indexable owner produces an `internal_links` finding with subject `pillar:<owner>`.
  - Its plan key is `internal_links:pillar` (*tested*).
  - **0.42.1 (finding 35).** The owner must be trusted: a manual owner (`manualOwnerUrl` equal to the owner), or an inferred owner with a share of at least 0.5 whose address is an article (`ContentRefreshRule::article`). The evidence names the source of the main page.
- **Bundle deletion.** Only `draft` and `approved` bundles can be deleted, version-checked; events cascade. Deployed bundles are records.

**Amendment (0.44.0, 2026-10-25): product, category and duplicate-page checks.**
- **Page kinds.** `AuditPage::isProduct()` is true for an Open Graph product type or Product markup. `AuditPage::isCategory()` is true for CollectionPage, ItemList or OfferCatalog markup, except on products, articles and the home page.
- **Parsing.** Citation signals count a body `<dl>` with three or more `<dt>` as a table, and H4 and `<summary>` titles phrased as questions as question headings.
- **Notices.** Three new growth notices, each with a *tested* plan:
  - `missing_content_markup`: structured data exists, but a product (Open Graph type) has no Product type, or an article (Open Graph type or `ContentRefreshRule::article` address, not an archive) has no Article type.
  - `missing_questions`: a product or category page with known citation signals has no question heading and no FAQPage or QAPage markup. The advice states that Google no longer shows FAQ rich results.
  - `missing_spec_table`: a product page with known citation signals has no table.
- **Merging near-duplicates.** `OverlapFinder::nearDuplicates` reuses the overlap review's text check.
  - `SignalCollector` drops pairs marked `keep_separate` and pairs with a distinct-purpose note, and keeps the rest in `ProjectSignals::$nearDuplicates`. The coverage source is `near_duplicates`: the latest audit's text, fresh for 30 days.
  - `DuplicatePagesRule` reports pairs in which at least one page has Search Console impressions. Each is a `cannibalization` finding with subject `duplicate:<pair key>`, which requires `near_duplicates` and `search_console`. It keeps the page with most clicks, then impressions, and reports at most 20 pairs.
  - The plan key is `cannibalization:duplicate` (*varies*), with redirect and canonical bundle edits.
  - **0.44.1 (finding 39).** Pairs with a product page are never proposed. `OverlapReviewRepository::productUrls` lists the latest audit's pages for which `AuditPage::isProduct()` holds; different products often share a description. The overlap review itself is unchanged.

## ADR-033 — PDF reports with an in-house PDF writer

**Decision (2026-10-09, approved by the owner):** project reports are also produced as PDF files: a **Download PDF** button on the report page, and a PDF copy attached to every report e-mail (scheduled and test sends).

- **No new dependency.** `Core\Pdf\PdfDocument` writes PDF 1.7 itself: A4 pages, text, filled rectangles, lines, web links and images. The owner chose this over Dompdf (a new runtime dependency with four transitive packages and a new `ext-dom` requirement).
- **Fonts.** IBM Plex Sans Regular and SemiBold (the product's UI typeface, SIL Open Font License 1.1, licence in `resources/fonts/OFL.txt`) ship in `resources/fonts` as TrueType files. `Core\Pdf\TrueTypeFont` reads them, and each PDF embeds a subset (unused glyph outlines emptied, glyph IDs kept) as a Type0/Identity-H font with a ToUnicode map. Text such as "Hodžić" or "Čćšđž" renders correctly and can be searched and copied. The PDF core fonts cannot show these characters, so they are not used.
- **Content.** `Modules\Reports\ReportPdf` lays out the same data as the printable page and honours the project's report sections and the workspace or installation branding: name, accent colour, footer, and logo when it is JPEG or a PNG without transparency. Other logos are left out and the name is shown. It includes a daily clicks bar chart, tables shortened to fit the column, page numbers and a link to the online report.
- **Runtime.** Built from stored data only, with no provider call. Generated in the browser request for downloads and in the report job for e-mails. Typical size is 20–60 KB. Streams are compressed when zlib is available and written uncompressed otherwise.
- **E-mail.** `Core\Mail\Attachment` (checked file name and content type, at most 10 MB) and `multipart/mixed` around the existing text and HTML versions (ADR-027). If the PDF cannot be built, the report e-mail still goes out without it and a warning is logged.

**Reason:** clients and agencies expect a PDF they can file and forward. "Print / Save as PDF" in the browser depends on the reader's browser and cannot be attached to scheduled e-mails.

**Implications:** route `GET /projects/{project}/reports/pdf` (same access as the report page; foreign projects return 404). Fonts add about 440 KB to the release package. Charts are drawn as bars, not as the web page's line chart.

## ADR-032 — AI answer checks for Gemini and Perplexity: sampled answers, not mention tracking

**Decision (2026-10-09):** *AI visibility → AI assistants* can send up to 10 of the user's own prompts to Gemini and Perplexity through DataForSEO's LLM Responses API (Live) and show, per answer, whether it names the brand, cites the project's site and names or cites competitors. This supplements, and does not replace, the ChatGPT/Google mention database (LLM Mentions), which still has no Gemini or Perplexity coverage.

- **Provider support verified against the documentation (2026-10-09).** `POST /v3/ai_optimization/{gemini|perplexity}/llm_responses/live`, one task per call, `user_prompt` up to 500 characters. Sources come back in message-section `annotations` (`title`, `url`, Gemini also `direct_url`), only with web search. The model is taken from the free `/llm_responses/models` list and must report `web_search_supported`; preferred are `gemini-2.5-flash` and `sonar`. Gemini requests set `web_search: true`; Perplexity Sonar models search by default and have no such field. If no listed model supports web search, nothing is requested or charged.
- **Cost.** DataForSEO charges $0.0006 per task plus the model's token cost (`money_spent`). The usage ledger reserves a cautious $0.06 per answer and settles to the reported cost. Checks run on request only, through the research queue; each answer is a resumable step, so a retry never pays again for answers already received.
- **Labelling.** The UI says each prompt is answered once per platform and that answers vary between runs: a sample of today's answers, not a measure of how often the brand is mentioned. Results are never fed into mention history, opportunities or scores.
- **Analysis.** Brand: whole-word, case-insensitive match of the given brand name or the domain's main label in the answer text. Own site: a cited source host equal to the project domain or a subdomain. Competitors: their domain or main label named in the text, or cited.

**Reason:** users want to see what Gemini and Perplexity answer for the questions their customers ask, and the provider supports it directly. A sampled answer is useful evidence when labelled honestly; presenting it as mention frequency would not be.

**Implications:**
- Research kind `llm_answers` (migration `2026_10_09_000300`); `Domain\Research\AiAnswerSampler` implemented by `Modules\DataForSEO\Adapters\DataForSeoAiAnswers`; `DataForSeoClient::llmModels()` reads only the two documented free model lists.
- Permission `analysis.execute` and the existing keyword-metrics entitlement, as for other paid research.
- Scheduled checks, more platforms (ChatGPT/Claude responses) and trend history are possible later and need their own decision.

**Amendment (2026-10-10, approved by the owner):** sampled answers may now produce one kind of opportunity, the *prompt gap* (`PromptGapRule`, subject `prompt:<normalized prompt>`, type `ai_visibility`). It needs answers from at least two platforms for the same prompt, the project's brand neither named nor cited in any of them, and the same competitor named or cited by at least two platforms. Agreement between independent platforms keeps single odd answers out. Confidence is capped at 30, there is no click estimate, and the evidence says that answers vary between runs. Source key `ai_answers` (fresh for 30 days). Sampled answers still never enter mention history or other scores.

## ADR-034 — Opportunity intelligence: business potential, BID, zero-click risk and results-page formats

**Decision (2026-10-10, approved by the owner):** the opportunity engine judges keyword findings against the business and the results page, with explicit, explainable policies on stored data. It is available in every edition: it uses the existing `opportunities`, `keyword_metrics`, `backlinks` and `mcp` features, which every edition includes, and adds no new licence feature.

- **Business profile** (`project_business_profiles`): business model, main goal, description, audience, offerings, never-relevant terms, and the project's limits for keyword difficulty (default 30) and weakest-competitor strength (default 20). Saved under *Opportunities → Business profile* (`projects.edit`, audited).
- **Business potential (B, 0–3)** comes from fixed rules (`BusinessPotential`), not from an AI model, as INSTRUCTIONS §13 requires. The inputs are a goal × intent matrix (transactional, commercial, local, tool, informational in depth, quick answer, navigational, unknown), +1 when the search mentions an offering and −1 when offerings are set but none is mentioned. Quick-answer searches get no bonus for naming an offering. Excluded terms and job/salary searches score 0, the own brand scores 2, and a bare offering name counts as a commercial search. A team rating per keyword (`opportunity_keyword_ratings`) always wins over the rules.
- **B replaces the generic intent weight in impact** when a profile exists: rules 0.40 / 0.75 / 1.00 / 1.20 and team ratings 0.25 / 0.60 / 1.00 / 1.30 for scores 0–3. This is the only BID part that changes a score. Neither the profile nor the ratings are source dependencies: they are configuration, not observations, and removing them must not freeze findings.
- **I (format fit) and D (rankability), each 0–3, are evidence.** I compares the project's page format with the dominant format of the stored results page (`SerpFormat`: URL, domain and title patterns; dominant = at least half of the classified results and at least three). D adds one point each for difficulty within the limit, a weak result in the top 10 (domain strength at or below the limit, or a forum/Q&A thread) and an existing page-one ranking. The verdict is *Reconsider* for B = 0, or when the search is harder than the limit with no weak result and no page-one ranking. It is *Target* for B ≥ 2 and D ≥ 2 with a fitting or unknown format, and *Consider* otherwise. Unknown parts stay unknown and never count as good.
- **Zero-click risk** (`ZeroClickModel`) is a labelled estimate. It scores +2 for an AI Overview, +1 for a direct-answer feature, +2 for definition or quick-fact wording, +1 for a plain how-to or yes/no question, −2 for wording that asks for comparison, purchase, a local business or a tool, and +1 when observed CTR on positions 1–5 is far below the expected CTR (Wilson bound). A score of 3 or more is high and lowers confidence by 10; a score of 1–2 is medium and lowers it by 5. It never hides a finding and never changes the click estimate. The AI Overview and results-page data it uses become source dependencies, like other optional confidence inputs.
- **Latest results page per tracked keyword** (`keyword_serps`): a SERP observer stores the top 10 with titles (200 characters), the feature types and the AI Overview flag from the SERPs rank checks already fetch. It keeps one row per keyword and device, an older check never overwrites a newer one, and there is no history (ADR-024 storage limits). Freshness follows the tracker cadence plus 2 days (`serp_landscape`).
- **New rules:** *Clicks falling, ranking stable* (`click_erosion`) uses Google queries only. It requires the position to stay within ±1 of the preceding 28 days, impressions at ≥ 80 % of before, clicks at ≤ 75 % of before with at least 20 earlier clicks and 200 earlier impressions, and a two-proportion CTR z above 1.96. An AI Overview that appeared between the rank checks is shown as context, never as the cause. *Format mismatch* (`format_mismatch`) covers tracked keywords at positions 4–30 whose page format differs from an actionable dominant format. *Free tool idea* (`tool_idea`) groups tool searches (strong tool nouns, or weak nouns with a digital cue) from research and Search Console into concepts, skips searches already in the top 3 and business potential 0, and shows a fixed feasibility judgement by kind of tool. *Prompt gap* is described in the ADR-032 amendment.
- **Topic clusters** (`KeywordClusterer`): three or more shared top-10 URLs join two keywords and one or none splits them. Otherwise core words decide (at least two core words each), with the overlap (Jaccard ≥ 0.5) measured on subject words only: question frames such as how long … last, types of or how much are not subjects (0.24.3). A keyword with a single subject word joins only one with the same subject and the same frame. Single words never bridge clusters, and groups whose wording asks for different things (tool, purchase or comparison, explanation, local) are never joined, not even through a neutral head term. Clusters are evidence on findings and a column in research tables. They do not change scores or identities.
- **Paid and AI work stays on request:** keyword suggestions per seed (`keyword_suggestions`, one request per seed, up to 10 seeds and 100 rows each) are filtered by the user's modifiers; seeds and modifiers are never multiplied into API queries. The competitor-strength check (`backlinks/bulk_ranks`, rank 0–100, up to 1,000 result domains, research kind `serp_strength`, 30 days fresh) runs only when a user starts it, with the cost shown. AI seed suggestions (`seed_ideas`) are one model call in the research job through the workspace's assistant model and AI budget. They are never a browser-request call (ADR-028), and they are words to look up, never data: volumes come only from the keyword database.

**Reason:** ranking and volume alone do not say what a search is worth to a business, whether the results page leaves clicks to win, or which kind of page can rank. Each of these is decidable from stored data with rules that show their inputs, which keeps the engine explainable and testable (ADR-006).

**Implications:** migration `2026_10_10_000300` (three tables; research kinds `suggestions`, `serp_strength`, `seed_ideas`). Opportunity types `click_erosion`, `format_mismatch` and `tool_idea` need no migration (`type` is VARCHAR). The format, intent and tool patterns are English heuristics; other languages mostly fall back to "unknown", which never counts in favour. `ResearchKeyword` gains optional SERP feature types and the top-10 average referring domains (`include_serp_info`; no surcharge documented).

## ADR-035 — Quest Log onboarding

**Decision (2026-10-11, approved by the owner):** the Quest Log replaces the Get started guide. It is a six-chapter onboarding campaign (Foundations → Navigator, Reading the Signals → Analyst, Finding Wins → Strategist, Taking Action → Operator, Proving Impact → Specialist, Mastery → SEO Lead) in which customers learn SEOcc by finishing quests on their own project.

- **Every plan.** The Quest Log has no `FeatureManager` key and no plan checks of its own.
- **Only enabled features, never locked.** A quest that teaches a feature this installation doesn't have, or work the user's role can't do (`Authorizer`), is left out silently. There are no locked cards, upgrade teasers or "Not included" labels; this replaces Get started's "Not included / Review license". Availability is decided only through `FeatureManager::enabled()`, never by plan name (ADR-021). An inactive license is a setup problem: Foundations keeps "Activate your license" and shows the licensed steps with "paused until your license is active".
- **Every plan can reach 100 %.** Chapter and campaign progress count only visible quests; a skipped quest counts as resolved. When features are added, "New quests available" lists them; earned ranks are never removed.
- **Foundations is live, learning quests are sticky.** Foundations (the former Get started steps) is read from current settings for the selected project on every view and can go back to "Needs attention"; the earned Navigator rank is kept and a marker shows instead. Setup steps owned by the installation administrator or workspace owner stay visible to members as "Handled by your administrator" with their live status, outside the member's own progress. Learning quests are stored the first time their steps are seen done and stay done.
- **State-based completion, no event bus.** Each step names evidence signals. Each module answers its own signals with indexed existence queries on its own tables (`Pdo*QuestEvidence`, `SearchConsoleQuestEvidence`), bound to the user's workspace and visible projects, and to the acting user where the table records one. Checks run when the Quest Log or its card is viewed and in an hourly bounded cron job (`quests.recheck`, 50 profiles per run). Viewing never queues jobs, calls providers or starts paid work.
- **Definitions in code, progress in the database.** Quests are a PHP catalogue (`Domain\Quests\QuestCatalogue`); only progress, ranks and preferences are stored (`quest_profiles`, `quest_progress`, `quest_ranks`). Progress is per user per workspace; learning work on any visible project counts.
- **Never blocking.** Learning quests can be skipped and the Quest Log card hidden (server-side, per user and workspace). Foundations steps can't be skipped. No forced tour, no modal on sign-in, no leaderboards, points or daily streaks; the only recurring quest is the Weekly Check-in, which is never counted in progress.

**Reason:** the checklist only covered setup. Quests that complete from real work teach the product on the customer's own data, end with a fully set-up project and first wins, and can't be gamed by clicking "done". Reading state instead of events keeps the modules independent and needs no event infrastructure on shared hosting.

**Implications:** migration `2026_10_11_124355` (three tables). Get started's setup logic moved from `Modules\Dashboard\GettingStarted` to `Modules\Quests\Setup\FoundationsChecks` (one check per step) and `CronHeartbeat`; Dashboard no longer has onboarding code, and the Quest Log card is plugged into new `LayoutWidgets::DASHBOARD` and `PROJECT_OVERVIEW` slots. `/get-started` (route `getting_started`) redirects to Foundations for the same project. Steps whose completion SEOcc can't see in stored data (for example "used a filter", "opened the Impact view", "rated a change") are not steps: they are tips or "Show me" links. Tables without an acting user (tasks, page watches, Bing, GA4, CrUX, backlinks, local) count workspace or project state. A quest's guide link opens the packaged user guide inside SEOcc (`/quests/guide/{chapter}`).

## ADR-036 — Writable MCP tools with scoped permissions

**Decision (2026-10-15, approved by the owner):** MCP clients can write to a small set of existing records. This amends ADR-007: writes are allowed when they are scoped, audited, non-billable and non-destructive.

- **Separate write scopes:** `annotations:write`, `tasks:write`, `briefs:write`, `opportunities:write` and `watches:write`. Existing tokens never gain them; a token gets them only when its creator ticks them, and the OAuth consent page lists them.
- **Two checks per write:** the token's scope, and the creator's current permission through `Authorizer`. A token never does more than its creator could do in the browser. Project visibility goes through `ToolContext` as for reads (foreign project: "Project not found.").
- **Through the existing services only** (`AnnotationService`, `TaskService`, `BriefService`, `OpportunityService`, `PageMonitoringService`), with the token's creator as the actor. Tools write no SQL of their own.
- **No paid or provider work.** No write tool starts a DataForSEO, Google, Bing or AI call. Enabling a page watch may queue the free own-site check, within the existing watch limits.
- **No hard deletes.** Tools change status, archive or disable. Deleting stays in the browser.
- **Optimistic concurrency.** Editable records return a `version`; update tools require `expected_version` and answer a mismatch with a conflict error and the current version instead of overwriting.
- **Idempotent creates.** Create tools take an optional `client_request_id`. A retry with the same token, tool and id within 24 hours returns the first result (`mcp_write_requests`).
- **Audit.** The service writes its own audit event; the MCP endpoint adds `mcp.tool_called` with `write: true`. Neither stores written text.
- **Origin.** Records created or changed over MCP carry `via` (the token name), which the UI shows.
- **Rate limit:** at most 20 writes per minute per token, inside the existing 60 calls per minute.
- **Ask AI** keeps its rule that the user confirms every write in the chat.
- **Licensing:** writes use the existing `mcp` feature (every edition). No new `FeatureManager` key.

**Reason:** after using SEOcc through AI clients, the owner found read-only MCP forces every conclusion back into the browser by hand: notes, tasks, briefs and watches. These records are cheap, reversible and already permission-checked in their services, so exposing them adds little risk. Billable and destructive actions keep ADR-007's protection.

**Implications:** one additive migration (`2026_10_15_000100`: `mcp_write_requests`, `seo_annotations.revision` and nullable `via` on notes, briefs and tasks). Opportunity status changes and page watches have no record of their own to carry `via`, so for them it is kept in the audit event only; opportunities have no revision either, so `opportunity_set_status` guards with `expected_status`. A browser edit clears `via` and keeps overwriting as before (the form sends no revision for notes). The write tool base lives in `Modules\Mcp\Server` (`McpWriteTool`, `WriteCall`); plan and later phases are in `the research workflows plan`.

## ADR-037 — Relevance before paid research, clusters with page ownership

**Decision (2026-10-16, approved by the owner):** the business profile is applied before money is spent, and keyword groups become saved, owned records. Shipped in two releases.

**C1 — relevance and markets:**
- **Markets** are part of the business profile: keyword locale keys (country + language), at most 20. Empty means unchecked.
- **Before a paid lookup** (`ResearchService::request`): a market outside the profile's markets is refused with the reason unless the user ticks *Run anyway* (`confirmed`). For seed lookups (ideas, suggestions), seeds with an excluded term or a job search are dropped before queueing and listed in the response; *Run anyway* keeps them. If every seed clashes, nothing is queued. The competitor-strength check has no market and is not checked. Audit `research.requested` records the dropped count and the confirmation.
- **After a lookup**, every research keyword gets a verdict from `Domain\Opportunities\Relevance`, the same rules as business potential: a team rating wins (0 = not relevant, 1–3 = relevant); then an excluded term or job search = not relevant; a mentioned offering = relevant; offerings set but none named = unsure (never "not relevant" from wording alone); no profile or no offerings = unchecked. Tables hide not relevant keywords by default and show how many were hidden.
- **Manual override** is the existing business-potential rating (`opportunity_keyword_ratings`): relevant = 2 (a finer positive rating is kept), not relevant = 0, auto = removed. One source of truth for research tables, opportunities and MCP.
- **MCP:** new opt-in scope `keywords:write` for `keyword_set_relevance`, under the ADR-036 rules.

**C2 — saved clusters, page ownership, query-to-page overlap:**
- **Saved after each analysis.** The topics `KeywordClusterer` already computes (two or more keywords) are stored in `keyword_clusters` by the opportunity analysis (`ClusterRefresher`), the top 300 by demand, up to 100 searches each. A failure here is logged and never undoes the analysis.
- **Stable IDs.** A new cluster takes the ID of the previous cluster with the largest keyword overlap when the Jaccard index is at least 0.5; each previous ID is used once. Clusters that disappear are deleted, unless the team set an owner: then they are kept as retired and come back with their owner if the topic reappears.
- **Ownership** (`Domain\Clusters\Ownership`): click share per landing page for the cluster's searches in the stored 28-day query-to-page data, or impression share under 10 clicks; without Search Console rows, the URL tracked rank checks found most often. The runner-up makes it contested when its share is at least 20 % and at least 60 % of the leader's. Nothing found: unowned. A manual owner (exact URL on the project domain, optimistic revision) wins and survives re-analysis; the inferred page stays visible next to it.
- **Overlap** (`OverlapClassifier`) uses a new weekly query-to-page read over 8 weeks for searches with two or more pages and at least 50 impressions (up to 500 searches). A page counts with at least 10 % of the impressions; at least 3 weeks are needed. Kinds: *brand* (brand search with the home page; harmless), *switching* (the leading page changes at least twice; likely harmful), *stacked* (same leader every week, second page in most of the same weeks, both in the top 10; harmless), *split* (otherwise; possibly harmful, check intent). It is evidence, not proof of cannibalization; the existing cannibalization rule is unchanged.
- **Where:** *Keywords → Topics & owners*, and MCP `clusters_list`, `cluster_get` (keywords:read) and `cluster_set_owner` under the new opt-in scope `clusters:write`.
- **Amended after the 0.18.0 live check (0.18.1):** ownership and overlap use `PageKey`, which drops pagination parameters (`page`, `p`, `pg`, `paged`, `pagenum`, `page_no`, `pageno`) and a trailing `/page/N/`, so pages of one listing do not compete; other parameters stay. Under 5 clicks and 50 impressions for a topic no Search Console owner is named (unowned, leading page listed). Searches `Relevance` rates not relevant are left out before grouping; a topic still needs two remaining searches.
- **Amended after the 0.18.1 recheck (0.18.2):** a topic is named after its top remaining search. The list shows by default only topics in focus: at least one *relevant* search, own Search Console impressions or clicks for it, a rank-check owner, or a manual owner; others are counted as hidden and shown on request (`show=all`, MCP `include_unrelated`). Nothing is deleted by this view. The business profile accepts up to 100 excluded terms (offerings stay at 30).

**Reason:** the owner found paid research returning searches the business could never use, and no way to see which page should own a topic. Rules that show their reason keep this explainable (ADR-006); confirmation instead of a hard block keeps the user in control.

**Implications:** migrations `2026_10_16_000100` (`project_business_profiles.markets`) and `2026_10_16_000200` (`keyword_clusters`). `PageRepository::weeklyQueryPages()` matches searches by their stored hash. No new provider call; everything runs on stored data.

## ADR-038 — "Why they outrank you": page-level competitor comparison

**Decision (2026-10-16, approved by the owner):** build the analysis specified in `storage/prompts/why-they-outrank-you.md` in three releases. Where that spec and an ADR differ, the ADR wins.

- **D1 — deterministic core.** A topic (saved cluster) can be analyzed from *Topics & owners*. The job resolves the results page, fetches up to five competing pages and the project's own page, extracts structure only (title, meta description, H1–H3 outline, schema types, word count, media/table/list counts, internal and outbound link counts, visible dates, outline hash; never full text), classifies each result's page type with fixed rules, decides the results-page format (dominant at ≥ 60 % position-weighted, mixed, fragmented), compares coverage only against competitors of the same type as the project's page, and names the main bottleneck (format, content, links, authority, freshness, technical or mixed). Its actions become opportunities through a regular opportunity rule over stored analyses, so they consolidate, rank with the existing models and resolve like every other finding. No brief, task or separate to-do list is created.
- **D2 — explanation, links and report.** One AI call per analysis through the workspace's assistant key and AI budget (structured facts only, validated output, retried once); without a key the deterministic result stands. Internal-link candidates from the site-audit link graph and Search Console queries; a branded client report.
- **D3 — evidence workspace and score breakdown.** Dated sources, limitations and completed checks per analysis; a warning before a duplicate paid run; one consistent score breakdown in UI, reports and MCP; MCP read and write tools for investigations. Starting a paid analysis stays outside MCP unless a later ADR adds `analysis:execute`.
- **Results page source:** the stored top 10 from rank tracking (`keyword_serps`) when a tracked keyword of the topic has a fresh one (free). Otherwise one DataForSEO organic lookup through `SerpProvider` (reserved and settled in `UsageLedger`), only after the user confirms the shown cost.
- **Fetching:** directly from the installation through `Core\Http\Client\HttpClient` (SSRF guards), respecting robots.txt, with a clear user agent, timeouts and a size cap; at most one request per host at a time. Blocked, challenged or empty pages are recorded as blocked and the analysis continues; fewer than two readable competitors lowers confidence. No paid page-parse fallback.
- **Availability:** every edition, no `FeatureManager` key or plan quota; the existing `keyword_metrics` licence feature gates the paid results lookup as for other research. Analyses run in the queue, never in a browser request (ADR-028's exception is only for Ask AI). One queued or running analysis per topic; a result younger than 14 days is shown instead of re-running unless the user asks again.
- **Security:** fetched HTML is untrusted; it is parsed, never rendered, and every extracted string is escaped. Text from competitor pages enters the D2 prompt as quoted data.

**Reason:** the owner's list asks to compare the actual ranking pages (intent, coverage, sources, links) and to connect relevance, intent, results-page analysis, page ownership and competitor comparison in one workflow. Stored results and free fetching keep the cost at zero for tracked topics.

**Implications:** D1 adds one table for analyses (structure and verdict as JSON) and an opportunity rule; D2 and D3 add their own records when built.

**D2 as built (2026-10-16):**
- **No new table.** The link plan (`links`), the validated AI answer (`explanation`) and the AI outcome (`ai`: status, note, cost) are stored in the analysis's `verdict` JSON.
- **AI call:** `Domain\Comparison\AnalysisExplainer`, implemented by `Modules\Assistant\AssistantAnalysisExplainer` with the workspace's assistant model and key, operation `competitor_analysis.{provider}` in the AI budget. It needs the AI assistant, which needs the `mcp` licence feature like every other assistant use; without it the rule-based result stands and the page says so. The model may only explain and order what the rules found: unknown action or link indexes, URLs that were not in the input, searches outside the topic and any page plan that changes an existing page's type are dropped (`Domain\Comparison\Explanation::validate`). Two invalid answers end with status `invalid`.
- **Internal links:** `Domain\Comparison\LinkPlanner` over the latest complete site audit, Search Console queries and the related pages *Pages* already suggests (`PageInsights::internalLinks`). At most 5 links; no links between pages that switch for the same searches. They become one `InternalLinks` opportunity per target page.
- **Client report:** a PDF built with the in-house `PdfDocument` and the project's report branding (`ReportCustomization`), for everyone who can see the project; each export writes `comparison.report_exported`. An HTML version and the before/after measurement are left to D3.

**0.19.1 corrections (2026-10-16, after the live check):**
- Links target only the topic's owner (cluster owner or the project's ranking URL) or its money page; without an owner there is no link plan. Link placement and reason are always rule-based; the AI output contract no longer has `links`. A link finding's gain is the target page's topic impressions × (CTR at 3 − CTR at 8) × 0.3.
- The validator also rejects bare addresses (`example.com/path`) that are not input pages or their sites.
- Page types: collection schema or a product grid → category before price/cart signals; a root address → home page whatever the content.
- Topics (amends ADR-037): searches whose stored top-10 results share at least 3 URLs join regardless of shared words, and a tracked keyword is kept as a topic of one.
**D3 as built, part 1 (0.20.0, owner decisions 2026-10-16):** D3 ships in two releases. 0.20.0: a repeated paid lookup with the same inputs within its freshness window (30 days for research and backlinks, 14 days for a results page) is refused with `Modules\Research\RepeatedLookup` (HTTP 409) until the user confirms; the generic `RepeatConfirmation` notice re-posts the same form with `run_again=1`. `Domain\Opportunities\ScoreBreakdown` reads only stored values and evidence, never recomputes a score, and is the one breakdown in the opportunity page, reports and MCP. *Research on file* and MCP `research_on_file` list stored lookups and analyses. 0.21.0: investigations as saved research notebooks (question, dated sources, checks, limitations, conclusion) that people and MCP clients create and update, under the new write scope `investigations:write`.
**D3 as built, part 2 (0.21.0):** investigations are rows in `investigations` (title, question, status open/concluded/archived, conclusion, `revision`, `via`) with `investigation_items` (kind source/check/limitation/note, optional reference type and ID or URL, label, detail, `observed_on`, `via`), at most 300 per investigation and 30 per call. Reading needs project visibility (MCP: keywords:read); changing needs `projects.edit` (MCP: `investigations:write`). Adding an analysis or a lookup only records stored evidence (`Domain\Comparison\AnalysisEvidence`); nothing is bought. Starting a paid analysis stays outside MCP. Analysis estimates (amends the D1 rule): main-search demand plus at most as much again from 10 % of the rest, halved for a page to create; older analyses without the stored main demand count 15 % of the topic.
- 0.19.2: tracked keywords always enter the grouping (beyond the 2,000-search demand cut) and are always stored with their topic (beyond `MAX_MEMBERS`; the topic's size still counts every search). Site roots are home pages before any title or content rule; article schema without product schema beats a price and cart. Tradeoff: on sites where most commercial searches show the same shops, more searches may join one topic; the 3-of-10 rule is the same one the word-based path already used.

## ADR-039 — History and outcomes: results-page changes, before/after watches, verified outcomes

**Decision (2026-10-16, owner's choices):** Phase E of the research workflows plan ships in two releases.

- **E1 (0.22.0) — results-page and competitor change history, derived from stored checks.** ADR-024 storage is unchanged: rank checks already keep, per project keyword, device and day, the top 10 organic results (position, domain, URL) and the result features. E1 compares each check with the previous check of the same project keyword and device (the keyword fixes the market) when it is at most 14 days older, and records change events in a bounded `serp_events` table: a result feature (including the AI Overview) appearing or disappearing; a tracked competitor entering or leaving the top 10; a URL of a tracked competitor or of the project newly in the top 10; the project's own page entering or leaving the top 10, or moving at least 5 places; and the dominant page type of the top 10 (classified from the URL) changing. Events are detected by a queued job after each check day and by a daily catch-up, idempotently (one event per keyword, device, day, kind and subject), and kept 400 days. They are shown on a *Rankings → Changes* page, summarized per competitor (new URLs, keywords gained and lost), offered as an alert type in the existing alert settings and read over MCP (`serp_changes`, rankings:read). Nothing is bought.
- **E2 (0.23.0) — before/after page watches and verified outcomes.** Watches can be added in bulk (pasted or chosen from Pages). Adding a watch reads a *before* snapshot right away instead of waiting a week, and recording a change note for an exact URL takes a *before* snapshot (when none from the last day exists) and an *after* check a few days later. The watch limit becomes a workspace setting (default 50, at most 200). An opportunity marked done gets an implementation state: *verified* when a page watch detected a change on its page around the completion date or a user confirms it, otherwise *unverified*. Its outcome is flagged when other recorded changes, completed opportunities or detected page changes hit the same page or topic in the measurement window. Outcome calibration prefers verified outcomes without overlap when enough exist. MCP gets the matching reads and writes under existing scopes.

**Reason:** the owner's list asks for competitor movement over time, results-page feature history, change-linked monitoring with before and after evidence, and outcomes that are checked against what actually changed. The daily top 10 already stored is enough for useful history without storing full results pages on shared hosting.

**Implications:** E1 adds one table and a job; E2 adds columns or tables for watch limits, snapshot reasons and implementation state, recorded when built.

**E2 as built (0.23.0):** migration `2026_10_16_000600` adds `workspaces.page_watch_limit` (default 50, clamped 1–200, set by `workspace.manage`), `page_watches.reason` (`watch` or `change_note`) and `after_due_at`, and on `opportunity_outcomes` `page_url`, `implementation` (unverified, verified, confirmed, not_implemented; shared by both checkpoints of a completion), `implementation_source` (`page_change:<id>`, `user` or `mcp`), `implementation_at` and `overlaps` (JSON, at most 10). A new watch queues one immediate check (`QueuedPageChecks`, idempotent per watch generation and hour). Change notes reach page monitoring through an `AnnotationObservers` registry, so Annotations does not depend on PageMonitoring; the after check runs 3 days later through `due()`. A completion through a team task uses the task's page when set, else the finding's; 0.23.1 backfills the page of earlier outcomes the same way. Pages are matched by lower-case host plus path without a trailing slash (`OpportunityConsolidator::pageKey`). Verification looks for a detected change within ±14 days of completion and only for completions of the last 21 days, so rows that can no longer qualify drop out. Overlaps are computed when a checkpoint is measured over completed −7 days to completed + checkpoint and exclude the outcome's own note and page changes inside the verification window. Calibration excludes not-implemented outcomes and uses clean outcomes (verified or confirmed, no overlaps) alone when a type has at least five, otherwise all. A user's confirmation is never overwritten by verification. Events describe what the stored checks show; a missing check is a gap, not a change. Comparing checks of different devices or markets is never done.

## ADR-040 — Link graph with paragraph context, and repeatable lab tests

**Decision (2026-10-17, owner's choices):** Phase F of the research workflows plan ships in two releases.

- **F1 — internal link graph and link suggestions.** The free site-audit crawler keeps, per internal link, the anchor text, up to about 300 characters of the surrounding paragraph and the link's place on the page (body, navigation, footer), bounded per crawl. Suggestions are rule-based and free: a paragraph that mentions a target page's topic but does not link to it gives a source page, the sentence and a proposed anchor. An optional Ask AI pass can reword a suggestion on request; it never runs on its own. The graph is searchable per page (inbound and outbound links with context) and read over MCP.
- **F2 — saved lab test profiles.** A profile fixes the page, the device (mobile or desktop) and the source. Two sources: Google's PageSpeed Insights API (free with an API key, Google's fixed location and throttling) for routine and scheduled repeats, and the existing paid DataForSEO Lighthouse for the browser audit, through the workspace budget as today. Repeat runs under one profile are compared with each other; lab results are shown beside CrUX field data and never merged with it.

**Reason:** the owner's list asks for paragraph-level link suggestions from a searchable graph and for performance comparisons that can be repeated under the same conditions. Free sources cover routine use on shared hosting; paid runs stay explicit.

**F1 as built (0.25.0):** migration `2026_10_17_000200`. Anchor and place are stored on every `site_audit_links` row (place from the closest `nav`, `header`, `footer`, `aside` element or matching ARIA role, otherwise `body`). Only body links keep a paragraph (`site_audit_link_contexts`). Pages keep up to 25 text paragraphs (`site_audit_paragraphs`). Text and suggestions are kept for the newest completed audit only. Suggestion targets are topics with status owned or contested whose owner page was crawled as an indexable HTML page. Phrases are the topic name and its 8 strongest searches of at least two words, matched as consecutive whole words with plurals folded (`QueryPatterns::stem`). A source page must not link to the target from its body text yet; menu, header, footer and sidebar links don't count (0.25.2), because a navigation link does not replace a contextual one. Score = log(1 + topic demand) × (1 + log(1 + the source's inbound internal links)), slightly lower for later phrases. Limits: 5 per topic, 3 per source page, 300 in all. Since 0.25.3: the words of a match are separated by spaces or hyphens only; the topic's candidate pages (`KeywordCluster::$candidates`, other than the owner) are never a source for it; and a search whose folded words equal a crawled page's last address segment (another page than the owner) is not matched, because a link on those words belongs to that page. Since 0.25.4 this also covers searches that contain every word (at least two) of such a segment; paginated copies (`PageKey`) are no source; and paragraphs need at least 8 words and no SKU-style code. Built by the queued `site_audit.link_suggestions` job after completion and daily (`site_audit.link_suggestions_daily`, 04:17). "Reword with AI" only pre-fills the Ask AI input.

**F2 as built (0.26.0):** a separate LabTests module (`lab_profiles`, `lab_runs`, migration `2026_10_18_000100`).
- **PageSpeed source:** reuses the workspace's encrypted CrUX Google API key, sent in the `X-Goog-Api-Key` header. It runs in one request through a 60-second HTTP client, inside the job's 120-second reservation.
- **DataForSEO source:** posts `/v3/on_page/lighthouse/task_post` (performance only, `for_mobile` by device) through `DataForSeoClient::post` at $0.005. It is then collected once a minute, at most 20 times. Each manual paid run needs an explicit confirmation; a paid profile never runs on its own unless weekly is switched on.
- **Parsing:** one parser (`LabResult::fromLighthouse`) checks page, device and runtime errors and requires score, LCP and CLS.
- **Limits:** 10 profiles per project, 20 manual runs per project per hour, one active run per profile, runs older than two hours failed as lost, 50 finished runs kept per profile.
- **Field data:** each profile shows the CrUX field record of the same page and device beside its runs. They are never combined.
- **Link suggestions (0.26.0):** addresses inside tag, topic, category, author or archive folders no longer count as the dedicated page for a search.

**Implications:** F1 adds columns or a table for link context next to `site_audit_links` and a retention bound per crawl; F2 adds profile and run tables, a PageSpeed API key stored with `Encrypter`, and a scheduled job within the existing queue limits. Recorded in detail when built.

## ADR-041 — Article ideas: recommendations from keywords and competitor articles

**Decision (2026-10-18, owner's choices):** phase G (content outscaling) starts with article recommendations (G1); topic plans (G2) and prediction checks (G3) follow.

- **Scope.** "Articles" are informational pages: blog posts, guides and how-tos, recognised by address and page type (`SerpFormat`/`PageTypeClassifier` article). Category and product texts stay with the existing findings.
- **Competitors.** Tracked competitors, plus domains found repeatedly in the stored top 10 of the project's tracked and researched searches, marked as "found". At most 5 per run.
- **Paid data, bounded and confirmed.** A run buys ranked keywords per competitor (DataForSEO Labs `ranked_keywords`, bounded rows) through `DataForSeoClient::post` and the workspace budget. The estimated cost is shown and confirmed before every run, and repeat runs get the 30-day duplicate warning (`RepeatedLookup`). Backlink checks of the top results, for the best ideas only, belong to G2.
- **Placement.** Its own page, *Research → Article ideas*, plus a "top 3 article ideas" block in Opportunities. MCP read access.
- **What a recommendation is.**
  - Competitor rows are grouped by competitor article address (articles only), then across competitors into ideas by topic (`KeywordClusterer`).
  - Each idea has a main search and its related searches with volumes, competitor examples (address, position, estimated traffic), and your coverage. It is a **New article** when no page of yours ranks or gets impressions, or an **Update** when one of your articles ranks below the top 3. Ideas where you already rank top 3 are left out.
  - The business profile's relevance rules and team ratings (ADR-037) filter the searches. Marking an idea *Not relevant* rates its main search.
- **Predictions, as ranges with their basis.**
  - *Traffic:* volume × the site's calibrated click-through at positions 1–3, 4–6 and 8–10.
  - *Difficulty:* keyword difficulty and the results page's average referring domains against the project's own domain, with reasons.
  - *Backlinks to speed it up:* the gap between those referring domains and the project's.
  - *Time to rank:* stated assumptions by difficulty and domain strength, labeled as assumptions until the site's own history (G3) replaces them.
  - Nothing is promised; every estimate shows how it was made.
- **Releases.** G1 ships in two parts. First, article ideas (inventory, grouping, coverage, predictions, page, Opportunities block, MCP). Second, competitor outlines (their article headings fetched with the free crawler, subtopics covered by most top articles) and *Create brief* prefilled from the idea.

**Reason:** the owner wants to outscale competitors' content: compare articles, see which articles to write or update next, and know the likely traffic, difficulty, backlinks and time. Most data and building blocks already exist (research, clusters, page types, the crawler, CTR calibration, briefs). The new part is grouping by article and predicting honestly.

**Implications:** new tables for runs and ideas, recorded when built; one bounded paid call per competitor per run; no competitor text is stored beyond addresses, keywords, positions and (in the second part) headings.

**G1 part 1 as built (0.27.0):**
- **Competitors.** "Found" competitors are the domains seen most often in the stored desktop and mobile page-one results, not a fixed minimum count. Generic platforms are left out.
- **Paid lookup.** One `ResearchProvider::rankedKeywords` call per competitor (500 rows), through the existing research client and budget. The 30-day repeat check is the run table's own: the last finished run, plus a "Run again and pay" tick. There is no `RepeatedLookup` entry.
- **Coverage.** Your best page per search comes from Search Console query pages and your own ranked keywords. *New* means nothing of yours ranks within 30; beyond 30 counts as not targeted.
- **Traffic.** Traffic uses the click-through at single positions 9, 5 and 2 (low, expected, high).
- **Backlinks.** The backlinks range is 30–100 % of the page-one average referring domains. Your domain's referring domains (under 20) raise difficulty instead of being subtracted.
- **Time to rank.** Months are fixed by difficulty: new 1–3, 3–6 or 6–12; update 1–2, 2–4 or 4–9.
- **Scoring and rebuilds.** Ideas are scored by expected traffic × difficulty factor, raised for updates and for topics two or more sites win. Ideas are rebuilt daily for free.

**Changed in 0.27.1 (live check of 0.27.0):**
- Ideas are built per competitor article instead of per word-based topic. Each search belongs to the article ranking best for it. Articles merge when one ranks for the other's main search, or their main searches cluster together; an article outranked on every search is listed with the one that wins its main search.
- Only competitor rankings up to position 20 count.
- Searches with the same volume and the same words count once in demand and traffic.
- *Update* needs your ranking page to be an article; a ranking shop page leads to *New article*.

**Changed in 0.27.3:** addresses that differ only by a leading language or country folder (/us/, /en-gb/) or `www.` are one competitor article.

**Added in 0.27.2:** editors can queue a free rebuild of one project's ideas from the stored lookup (*Rebuild ideas now*; rate-limited, queued, never paid).

**G1 part 2 design (0.28.0):**
- **Competitor outlines, on request and free.** For one idea, an editor queues *Read competitor outlines*. A job (`article_ideas.outline`) reads the idea's top 3 competitor articles (one per article, copies skipped), plus your page for an *Update*. It uses the page-comparison reader (ADR-038: `SiteCrawler::document`, HTTPS only, robots.txt respected, two redirects, 2 MB) and `OutlineExtractor`. Only the structure is stored: H2/H3 headings, question headings, word count. Text is never stored. Lease renewed between pages.
- **Limits.** One read per idea per 7 days unless the last one failed. At most 20 requests per project per hour.
- **Subtopics.** Headings are grouped with the page comparison's `Coverage` (shared content words, Jaccard ≥ 0.5). A subtopic is listed when at least two articles cover it; with only one readable article, its headings are listed. For an *Update*, each subtopic says whether your page already covers it.
- **Create brief.** Each idea links to *New content brief*, prefilled from the idea:
  - target search and related searches;
  - an outline (H1 = main search, H2 = subtopics, most covered first);
  - topics from question headings;
  - for an update, your page as the URL;
  - evidence lines: volume, estimates, competitor articles with positions, outline date.

  The briefs module gets the seed through an optional closure, so it doesn't depend on the article-ideas module.
- **MCP.** `article_ideas` adds each idea's outline status, subtopics and the competitor headings.

**Changed in 0.28.1 (live check):**
- `OutlineExtractor` (shared with ADR-038 page comparisons) reads the main content's headings only. It skips site chrome and boilerplate containers; a boilerplate class only counts on containers that don't hold the main content.
- Article-idea subtopics count sites, and drop headings a site repeats on its articles as template.
- Outlines read one article per site first.

**Changed in 0.28.2:**
- A topic merge also needs a shared non-generic word in the main searches. A word is generic when it appears in at least 10 % of the lookup's main searches (minimum 3).
- Questions are those naming a word of the idea's main search, or asked by two sites.

**Changed in 0.28.3:** the ranking merge needs each article to rank in the top 10 for the other's main search (it was one-way, top 20).

**Changed in 0.28.4:** the topic merge (clustered main searches with a shared distinctive word, 0.27.1–0.28.3) is removed. Shared words can't separate site words from article words on a niche site. Articles join only as language copies, by mutual top-10 rankings, or when one wins no search.

**G2 topic plans design (0.29.0, owner's choices 2026-10-18):**
- **You pick the pillar.** *Plan a topic* on an article idea creates a plan for it (one per pillar, free). SEOcc proposes members, and you keep or drop each:
  - *supporting articles*: other article ideas whose searches contain a distinctive word of the pillar's main search. Words in a quarter or more of the ideas' main searches (and at least 5) don't count, unless the pillar has no other words; then all its words must appear.
  - *searches to consider*: Search Console queries with that word that no idea covers. They are proposed but not kept by default.
  - *your pages*: pages of yours that get impressions for such queries.
  *Changed in 0.29.1 (live check):* the subject is the pillar's rarest words over all idea searches and Search Console queries: the rarest word plus any pillar word at most twice as common. This replaces the share threshold.
- **Link map (free, queued).**
  - Pillar ↔ supporting links, with the main searches as anchors.
  - Links from your crawled pages to the pillar and the kept articles: the F1 link suggester run on the newest audit's paragraphs, with the planned articles as targets. Each gets a paragraph and an anchor, to place once the article is published.
  - Built in a job (`topic_plans.links`) when the plan is created and on request.
- **Backlinks (paid, per plan, confirmed).** One DataForSEO backlinks summary per page for the pillar's top 2 competitor articles (`BacklinksProvider::pageProfiles`, about $0.024 per page). Their referring domains, backlinks and rank, and how many referring domains would match the strongest page, are kept on the plan. The cost is shown and confirmed each time; a repeat within 30 days needs a second tick.
- **Briefs.** Each kept idea uses the G1 *Create brief*. A search member opens a plain brief for that search. The plan's links appear in each brief's internal-links section.
- **MCP.** `topic_plans` (keywords:read) lists plans with members, link map and backlinks.

**G3 prediction check design (0.30.0, owner's choices 2026-10-19):**
- **Published articles are marked, not guessed.** *Mark as published* on an idea takes the page URL (on the project's site) and the date. A content brief whose target search is an idea's main search does the same once it is saved as *Published* with a URL (a hook from the briefs module, so neither module reads the other's tables). Each tracking keeps a snapshot of the idea at that moment: its searches, kind, difficulty and the predicted traffic and months. The idea itself can later disappear, for example once the article ranks top 3.
- **Measured weekly from stored Search Console data (free).** A daily job (`article_ideas.track`) reads the weekly clicks, impressions and impression-weighted position of the article's URL for the snapshot's searches, from the publish week on (`PageRepository::weeklyQueryPages`). The result is kept on the tracking:
  - the weeks;
  - the best position;
  - clicks in the last 4 weeks;
  - the first week the main search reached the top 10 (average position ≤ 10 with at least 10 impressions).
- **Compared on the page.** *Research → Article ideas → Published articles* shows predicted against measured, for clicks a month and for months to the top 10 ("not yet, N months since publishing" until reached). MCP `article_ideas` adds `published`.
- **Calibration after 3 per level.** Once at least 3 tracked articles of the same kind (new or update) and difficulty reached the top 10, new estimates for that pair use the site's own range: the 25th–75th percentile of their months, at least 1 month. The basis then names the measured articles. Only articles that reached the top 10 count; the basis says so. Until then the stated assumption stays.

## ADR-042 — Grounded recommendations, reviewable execution, measurable results

**Decision (2026-10-23, owner's choices):** ten capabilities ship as separate releases, in the order of the grounded recommendations plan:
- MCP pagination (0.31.2)
- conversion diagnostics
- content inventory
- claim verification
- internal-link classes
- overlap review
- indexing history
- speed diagnostics
- measurement plans
- change bundles

- **CMS drafts.** Entered as CSV or manually. SEOcc has no CMS connection and stores no CMS credentials. A draft is never a public page, and a canonical is never a redirect. Each source keeps its own status and observation date.
- **Change bundles.** Record, verify and guard only. SEOcc never edits the live site. An approval binds to a hash of the bundle's content, and any material edit voids it. Before a deployment is recorded, the version guard refetches the live page and blocks when it differs from the approved baseline. Deployment, verification and rollback are recorded by people.
- **Conversion test.** A guided manual check. The owner triggers a test conversion. SEOcc reads GA4 read-only and reports observed or not observed. It sends no events, purchases or leads.
- **Honest states everywhere.**
  - Business events: not configured, not observed, observed, failed, unknown. Missing data is never zero.
  - Indexing: a recorded request is not indexing.
  - Impact: stays pending until equivalent completed periods exist.
  - Claims: automated flags stay apart from human verification.
- **Authorization.** MCP tools are labelled read-only, reversible write, paid, or live-site. There are no paid calls, indexing submissions or live edits without explicit authorization, and no recurring spend is switched on silently.
- **Migrations.** Stay forward-only (ADR-018). "Reversible" means additive: new tables and nullable columns, no data dropped.

**MCP pagination (0.31.2):**
- Every list that a response can cut is reachable through documented `offset`/`limit`/`next_offset`. That covers article ideas, topic plans, outcomes, speed-test profiles, competitors, projects and search segments, and the earlier paged tools.
- A cut list that the tool cannot page (`page`-based lists, nested lists) is named in `result_limits.message` with "narrow the filters". The message never asks to advance an offset the tool does not accept.

**Conversion diagnostics as built (0.32.0):**
- Business events live on the Analytics page and are edited with `projects.edit`. Test checks need `integrations.manage`.
- The event report runs after each GA4 landing-page import, for the same range, as two read-only `runReport` calls (all channels; Organic Search). `isKeyEvent` is not requested, so a GA4 key-event setting cannot break the layout check.
- The test check uses `runRealtimeReport` over GA4's default last 30 minutes, filtered to the project's event names. It is not filtered by host, which the page says. It runs as one queued attempt, rate-limited to three per project per ten minutes.
- `BusinessMeasurement::assess` gives the precedence for the project summary: failed, then unknown, then not observed, then observed.

**Content inventory as built (0.33.0):**
- **Module.** A new module, `ContentInventory`, with one table: `content_items`, the CMS records. Like the Pages list, it joins other modules' stored records read-only and bounded.
- **Matching key.** Scheme and host lower-cased; http and https treated as one; default port, fragment, `utm_*` and click IDs, and the trailing slash dropped; other parameters kept and sorted. www and non-www stay apart.
- **Headline state.** It follows the strongest evidence: crawl, then CMS retired, then Search Console, then sitemap, then CMS published, scheduled or draft, then planned. Every source's own status and date stays visible.
- **Uncertain matches.** A record without an address gets candidates (shared title or address words). It is never joined until a person confirms the match (`linked_url`).
- **Before new content.** A new-article idea and a new brief are "covered" when every meaningful word of the main search appears in an inventory row's title, address words or brief keyword. Redirected and retired rows don't count.
- **0.33.1 refinements (live check, findings 20–21).** Only written content counts as covering a topic: an article or review page (the `SerpFormat` rule article ideas use for your own pages), a CMS record, a brief or a published article. Product, category and other pages on the topic are listed apart without the duplicate warning. Keyword words come only from sources describing the page itself (CMS, brief, published), never from a topic plan or topic owner. The inventory is joined once per page view for all ideas.

**Claim verification as built (0.34.0):**
- Claims belong to content briefs; investigations are left for later.
- The verdict is the person's; flags are computed when read.
- Scope values match when their words are the same, or one adds only numbers ("Brandywine #33" against "Brandywine"); any other added word is another product.
- Changing the evidence while keeping a non-unreviewed verdict resets it to unreviewed.
- Every earlier version is kept in `brief_claim_revisions`.
- Competitor domains come from the project's tracked competitors.

**Internal-link classes as built (0.35.0):**
- **How a link is classed.**
  - Page regions (nav, header, footer, aside) make it a template link, with high confidence.
  - Class or id names: product hints (product, price, cart, upsell, cross-sell) win over related hints (related, recommended, read-next, card, teaser), which win over template hints (breadcrumb, pagination, menu, sidebar, widget). The page's own article or main element ends the search.
  - A link inside a paragraph or list item whose text is clearly longer than the link is editorial.
  - A heading or image wrapped in a link is a card: a product card when a price is near, a related-article card otherwise.
- **Which occurrence counts.** Per linked page the best occurrence is kept: text, then cards or other body links, then templates.
- **What blocks a suggestion.** Only editorial and other body links (and unclassified body links of older audits) do.
- **Intent check.** It compares three purposes that share everyday words: prize draws, rewards or loyalty, and affiliate programmes. A match is dropped only when the target names one purpose and the paragraph names another but not the target's. Dropped matches are counted on the audit.

**Overlap review as built (0.36.0):**
- **Where it lives.** In the ContentInventory module.
- **Candidates are computed when read; only decisions are stored.**
  - Near-duplicates: 5-word shingles over the latest crawl's paragraphs. A shingle on more than 10 pages is template text and ignored. At least 20 shingles per page, and Jaccard 0.5 or more.
  - Shared searches: the Topics & owners overlap classification (switching, stacked, split; brand searches left out).
  - Synonym ideas: meaningful-word Jaccard 0.75 or more, or one set containing the other with at least two shared words.
  - Archives: tag, topic and category archives whose address words share two words (or one, when an archive has a single word).
- **Distinct-purpose note.** It comes from the page kinds (`SerpFormat`) and the purpose groups of `LinkIntent`.
- **Retained-address proposal.** The redirect or canonical target first, then most clicks, then most impressions, then the shorter address.
- **Decisions.** A decision binds to the candidate key and is versioned. A planned merge must name one of the members.
- **0.36.1 refinements (live check, findings 23–24).**
  - The near-duplicate check skips listing pages: paginated copies (`PageKey`), and tag, topic and category archives, which the archive check covers.
  - Candidates are capped at 100 per kind, not 200 in all, so one kind cannot crowd out the others; counts are taken before the cap.
- **0.36.2 refinements (live check, findings 25–26).**
  - Two archives split a subject only when one's slug words are a subset of the other's, or the Jaccard over distinctive words is at least 0.67. Either way they must share a distinctive word. A word in at least 10 % of the site's archive slugs (and at least 3) is common, not distinctive.
  - The evidence quotes the slugs, not stems.
  - `author`/`authors` pages are listing pages for the near-duplicate check; they are not archives for the split check.
- **0.36.3 refinements (live check, findings 27–28).**
  - Archive slugs have their own word list. Only connectors are dropped, so short words ("ph", "f1") and question words ("when") stay subject words.
  - A pair whose distinctive words are the same scores 30 plus the shared distinctive words. A pair where either archive has a distinctive word the other lacks is a possible subtopic: it scores 20 plus the shared words and says which words differ.

**Indexing history as built (0.37.0):**
- **Where it lives.** The states and timeline come from `Domain\Indexing\IndexingHistory` (pure). The Indexing module supplies the inputs, and `IndexingEvidence` reads the site audit and IndexNow tables read-only, like the content inventory's sources.
- **Six states, never merged into one.** *Public*, *technically indexable*, *known to Google*, *indexed*, *request submitted* and *recrawl observed*. Each is yes, no, unknown or not applicable, with its evidence and date.
  - *Indexed* comes only from a Google check's PASS verdict.
  - *Recrawl observed* is yes when Google's reported last crawl is after the request's recording time, or on a later local day than the request day. A crawl on the request day before the recording stays unknown.
- **History.** Every successful check is also written to `google_inspection_history`, up to 30 per address and binding. Results saved before 0.37.0 show as one check.
- **Requests.** People record them with a channel (Search Console or other), the local day, evidence and a note. Recording one is audited and saves the address for later checks. SEOcc submits nothing and does not use the Indexing API.
- **MCP.** `indexing_status` returns the states and timeline instead of a new tool.
- **0.37.1 refinement (live check, finding 29).** The sitemap event is *SEOcc first read it in your sitemap*: the IndexNow check's first sighting, not a publication date.

**Speed diagnostics as built (0.38.0):**
- **Source.** `Domain\LabTests\LabDiagnostics` reads the Lighthouse report the run already received from PageSpeed Insights or DataForSEO. There is no extra call or cost. Parsing never fails a run; an unexpected shape only leaves parts empty.
- **What is kept**, as bounded JSON in `lab_runs.diagnostics`:
  - the LCP element (label, snippet, image address resolved against the tested page) and its phases;
  - render-blocking resources (up to 10, from `render-blocking-resources` or `render-blocking-insight`);
  - performance audits below 0.9 that are not metrics (up to 8, by estimated LCP+FCP+TBT savings);
  - requests by type (`resource-summary`, or counted from `network-requests` when it is missing) and the 5 largest requests;
  - the configuration (form factor, screen, throttling method, RTT, throughput, CPU slowdown, benchmark index, user agent, fetch time, final URL, channel);
  - the SHA-256 and byte size of the raw report.
- **Raw report.** It is referenced, not stored: reports run to megabytes, and shared hosting keeps up to 50 runs per profile. The hash identifies the report; paid runs also keep their DataForSEO task ID.
- **Compatibility.** Two runs are compared only with the same Lighthouse major version and, when both are known, the same throttling method, RTT, throughput, CPU slowdown and screen. Otherwise the page and MCP give the reason instead of a score change. Runs from before 0.38.0 compare by version only.
- **0.38.1 refinements (live check, findings 30–32).**
  - On Lighthouse 13 the LCP element and its phases (label and duration) come from `lcp-breakdown-insight`, with the request discovery checklist from `lcp-discovery-insight`. Both are left out of the ranked diagnostics.
  - Audits below 0.9 without a metric saving or a display value go to `also_flagged` (titles, up to 8).
  - PageSpeed Insights trims `configSettings` (no throttling or screen), so its runs compare by Lighthouse version only, and the page says so.
- **0.38.2 refinement (finding 33).** LCP phases are shown as shares of the observed load, with their total. The page says they don't add up to the simulated LCP.

**Measurement plans as built (0.39.0):**
- **Where.** One plan per SEO change (annotation), stored in `measurement_plans` and edited on the change-impact page. Bundles (0.40.0) will reuse it. The evaluation is pure: `Domain\Measurement\MeasurementResult`.
- **Snapshot.** For one period it stores:
  - Search Console days imported (page days for target pages, property days otherwise, the smaller of the two when both are used);
  - GA4 days, complete only when not limited or thresholded;
  - targets without data, which are absent rather than zero;
  - summed clicks and impressions, CTR, and impression-weighted position;
  - GA4 sessions and key events for the target landing pages, or property totals.
- **Gates.** A result needs all four gates: change made, baseline frozen and complete, period after over, and that period fully imported. Complete means all days imported and no target without data. While imports have not reached the end of the period, the plan stays *pending*, never *insufficient*.
- **Classification.**
  - *Improved* or *declined* at ±5 % relative change, or ±0.5 positions; less is *no clear change*.
  - *Insufficient evidence* when the baseline is missing or incomplete, days are missing after imports passed the end, the baseline is below 100 impressions or 30 sessions, or the base is zero.
- **Attribution.** *Controlled* only with control pages complete in both periods, with the effect taken as the targets' change minus the controls' change. Otherwise *association*. SEOcc never calls a result causal without a control.
- **Concurrency.** Writes carry a version. A changed metric, target, control or period clears the frozen baseline.
- **0.39.1 refinement (live check, finding 34).**
  - An optional `goal` makes the plan after-only: at least the goal, or at most it for position, over the period after the change.
  - The result is `reached` or `not_reached`, without the baseline gate but behind the window and import gates.
  - It is never read as improved or declined.
  - A before/after plan whose targets had no data before the change points to a goal.

**Change bundles as built (0.40.0):**
- **Where.** A ChangeBundles module, under Manage → Change bundles. The rules are pure, in `Domain\ChangeBundles\BundleRules`. Each bundle covers one page.
- **Storage.**
  - `change_bundles` keeps title, url and status as columns, and the reviewed content and state as bounded JSON (`data`), versioned for concurrent edits.
  - `change_bundle_events` is the bundle's history.
- **Live reads.** These are queued jobs (`change_bundles.check`, needs the Site audit feature) through the site audit crawler: robots.txt, SSRF protections and size limits apply. Each read records status, title, description, headings, canonical, noindex, word count and a body-text hash.
- **Approval.** It binds to SHA-256 over title, problem, evidence, url, edits, expected, validation and baseline. Any edit or new baseline clears it.
- **Version guard.** A deployment can be recorded only when all of these hold:
  - the bundle is approved and its hash is unchanged;
  - a live check was made after the approval and within 24 hours;
  - that check matches the baseline on every guarded fact.

  Otherwise the reason is returned (HTTP 409).
- **Deployment record.**
  - People record the deployment after making the edits in their CMS, ticking which edits were applied: all gives `deployed`, some gives `partially_deployed`, none is refused.
  - It creates an SEO change on the timeline (type from the applied fields), so change impact and measurement plans follow.
  - A deployed bundle is no longer editable.
- **Verification.** It compares title, description, H1 (any of the page's H1s), canonical (relative resolved, trailing slash ignored) and noindex with the new values. Body text, redirects and other edits are `manual`. `verified` requires a full deployment without a mismatch.
- **Rollback.** It is recorded with a reason and also creates an SEO change.
- **Never.** SEOcc never writes to the site, stores CMS credentials or deploys automatically (owner decision 2).

**Reason:** after a few days of daily use, the owner found recommendations without enough grounding (no inventory check, no claim evidence), execution that was hard to review, results that were hard to measure, and an MCP truncation hint pointing at a parameter some tools lack.

## Opportunity coverage policy (2026-10-08)

Stored snapshots are fresh for 7 days (Google), 10 days (Bing/local checks), tracker cadence plus 2 days, 30 days (research/backlinks/audits/profile/AI mentions), and 35 days (CrUX collection end). Future dates are stale. These are conservative analysis policies, not provider uptime claims. Per-finding dependencies distinguish branches sharing a type. Existing merged search findings lack a source footprint and conservatively require both Google and Bing until their next fully covered run. Tradeoff: this can retain a resolved legacy finding on a Google-only project, but avoids treating disconnected Bing as zero. Coverage is a last-run snapshot, not live connectivity.

## Opportunity CTR calibration policy (2026-10-08)

Per-position CTR is (observed clicks + 1,000 × global CTR) / (non-brand impressions + 1,000), trained once from the bounded Google query snapshot. Exclude domain-label brand tokens and position 1–3 queries with ≥100 impressions and ≥45% CTR. This heuristic can exclude a high-CTR non-brand query; it is explainable and local. Domain subdomain tokens www/shop/store/blog are ignored. Brand-term input is supported by the pure model, but project-name/user-setting integration remains open. The stored query schema has no joint query/device breakdown, so do not claim device calibration from independent device totals. Sampling by the existing top-query reader can bias the curve: evidence explicitly names the bounded aggregate sample. LowCtr uses the 95% Wilson upper bound below half expected CTR, with the existing 200-impression floor. These are heuristic detection/calibration policies, not guaranteed uplift or causal claims. Existing forward-validated forecasting already uses empirical site CTR and does not consume Visibility::CTR; retain that independent model.

### Opportunity confidence evidence policy (2026-10-08)

Confidence is an explicit heuristic score, not a probability. Start at 35; add up to 25 for log-scaled observed impressions, daily-position stability from at least three observations (15 minus three times the largest available sample SD, capped at a 10-point penalty), up to 10 for freshness relative to source cadence, and +10/-10 when available position estimates agree within three positions or disagree. Unknown sample/stability cost five points each; unknown keyword volume/difficulty cost ten/five. Page/technical findings do not require keyword completeness. Clip to 0–100. Compare recorded source averages as corroboration only, not identical-cohort measurements or causal proof. Google/Bing device/query joint data is unavailable; do not invent it. Bulk history reads cap Google queries/pages at 500 each and tracker queries at 1,000 over 28 days. Optional sources used by confidence become lifecycle dependencies so a disconnect cannot rewrite a formerly corroborated score.

### Opportunity value and intent policy (2026-10-08)

Use complete, unthresholded/unlimited stored GA4 windows only, with linked project and workspace credentials. Join own-host landing URLs by page path, pooling query strings/trailing slashes. Shrink observed page key-event/revenue-per-session rates toward the project-wide rate with a 200-session prior. Prefer revenue when the project has observed revenue; otherwise use key-event rate. Express impact as monthly clicks times page/project relative rate (bounded 0.25–4) times conservative English-pattern intent weights (transactional 1.25, commercial 1.1, informational 0.9, navigational 0.75, unknown 1), then the existing logarithmic 0–100 curve. Predicted events/revenue assume one incremental click produces one landing session at the observed Organic Search rate; never claim attribution or causation. GA4 engagement/channel splits are not stored. For queries without a ranking URL, use the highest-impression stored query/page association and label this approximation. Unknown page/GA4 rates fall back to clicks. Added GA4/query-page dependencies protect previously valued scores on disconnect. Read at most 2,000 GA4 pages and 5,000 joint query/page rows, plus project totals in bulk.

### Opportunity trends, seasonality and sample scope (2026-10-08)

Select at most 500 Google queries/pages by current impressions, carrying three 28-day page windows in bounded bulk reads. A truncated Google sample or uncertain 500-row Bing sample cannot prove that an unseen search finding disappeared; preserve it until the relevant sample is complete. Retain visible top-N-of-total notices in run coverage, UI and MCP. Declining equal-window click counts must exceed an approximate Poisson rate z threshold of 1.96, in addition to the existing 30%/20-click thresholds. Rank movements with at least three daily observations must exceed twice observed tracker SD; missing volatility is explicitly unconfirmed. Use prior-year completed-month ratios only with 13 consecutive marked monthly rollups and both relevant page-month rows. Monthly context is approximate, not exact 28-day seasonal cohorts; suppress a decline consistent with the seasonal dip within 10%, and lower confidence for remaining declines with seasonal context. Never turn missing monthly/page rows into zero. Scope ranking comparisons to the intended seven-day cadence rather than arbitrary ancient snapshots. Project-name brand detection uses the full project name, excluding generic labels such as Shop/Website/Project; there is no existing project brand-term setting. Device/query joint samples remain unavailable.

Opportunity audit value exposure pools landing query strings and trailing slashes by path, like the existing value model. It is exposure rather than a predicted benefit. Page-watch support reads the optional stored table when installed and otherwise reports missing; it does not depend on an unfinished module or call its provider.

Opportunity consolidation is a read projection, not a subject-identity rewrite. Stored detections retain each original hash/UUID and source-aware lifecycle, while the UI/API/MCP select one primary and preserve closed canonical-subject decisions. Consequently no corrective identity migration is needed. The additive estimated_clicks column stores structured estimates; legacy unknowns are not reconstructed from prose.

Opportunity effort thresholds and time-to-value ranges are explicit planning heuristics, not empirically measured implementation costs. The weekly recommendation cap is three per primary type, with no filler that violates the cap; dependency ordering precedes numeric priority. All these choices are disclosed in evidence and the weekly view.

Opportunity realization calibration is correlational: last-year 56-day observations only, five samples minimum, at least 200 impressions in both windows, ratios clipped 0–2 and ten neutral prior observations, final factor 0.25–1.5. Raw predictions are retained as the calibration denominator to prevent recursively compounding factors. Completion episodes are deduplicated per finding/Pacific date; the first baseline wins. Checkpoints are nullable when coverage is insufficient.

Opportunity snooze expiration occurs during the existing local analysis schedule, using the project-local date and bulk system status-history writes. This preserves source-aware resolution instead of treating timer expiration as proof of a fix. MCP remains read-only under the existing ADR policy; snooze writes are authenticated UI actions with CSRF, project edit permission and audit events.

## ADR-043 — AI visibility readiness: crawler access, citation readiness, AI referrals (0.43.0)

**Context.** The AI visibility page showed where AI answers mention or cite the site. It did not show the preconditions for being cited (can the crawlers fetch the pages, do the pages offer something quotable), or what AI answers bring in visits.

**Decision.**

- **Crawler access.** A free queued check reads the project's own robots.txt and llms.txt, weekly and on request. It only fetches over HTTPS, from public addresses and the project's domain with and without www, with at most 3 redirects. It evaluates a fixed list of AI crawler tokens in two kinds: search and answer crawlers, and training crawlers. Each token is matched exactly against robots.txt groups, falling back to `*`. The check covers the home page and the 10 most-clicked Search Console pages.
  - Only a blocked or partly blocked search crawler becomes an opportunity (`ai_visibility`, subject `crawler:ai_search`, *Search engine guidance*). Training crawlers are shown as the owner's choice.
  - An unreadable robots.txt is shown but not evaluated, and the opportunity's coverage is missing.
  - llms.txt carries the *Few published tests* label and never produces a finding.
- **Citation readiness.** The site audit stores what a page offers to quote: question headings, tables, written lists, paragraphs with figures, the opening paragraph's length and a stated modified date. A pure checklist combines these with the author, structured data and source-link signals. Each check carries a Playbook::TESTED level. Checks that are not known count neither way. Since 0.43.1 the author and source checks apply to articles only (Article markup, og:type article, or an article folder in the address); on other pages they count neither way. The page shows the 10 most-clicked pages; no new finding type is created.
- **AI referrals.** Each GA4 import adds one read-only report of sessions, key events and purchase revenue by `sessionSource` for AI assistant hosts, covering at least 56 days so both 28-day windows exist. Its failure never fails the import. The page and `analytics_summary` show these numbers as a lower bound, because assistant apps often send no referrer.

**Consequences.** Readiness is shown, not scored or forecast. The checks and labels are SEOcc's own words, and no external study is named (ADR-031). Firewall and CDN bot rules stay invisible to the check, and the page says so.

## ADR-044 — The full market catalog (0.44.3)

**Context.** Markets were a hand-picked list of 15 countries and 10 languages. One of these languages, Bosnian, is not a Google Ads language, so keyword-volume requests for it failed after being sent (finding 41). Customers need every market Google serves.

**Decision.**
- `Domain\Keywords\Markets` holds generated constants from the official sources:
  - Google Ads geotargets: countries and top-level territories, without Russia and Belarus, which DataForSEO no longer serves;
  - DataForSEO's Google SERP languages, without the duplicate `iw`;
  - the Google Ads languages whose code matches;
  - DataForSEO Labs country and language pairs.

  `KeywordLocale::LOCATIONS` and `LANGUAGES` alias them. Locale keys accept codes such as `zh-TW` and `sr-Latn`, which fit the existing columns (VARCHAR(8) or wider), so no migration is needed.
- **What each service accepts is decided in one place.**
  - Search volume filters by language only when Google Ads has it, and otherwise asks for the country.
  - Labs lookups are refused before anything is queued or reserved when the pair is not covered (`Markets::labsProblem`).
  - SERP checks take any catalog language.
- **Forms.** The market selects list a country's Labs languages first and choose the main one when the country changes. This is progressive enhancement (`market-select.js`); without JavaScript, the server-side checks still apply.
- **Keyword text.** Keyword text is normalized to NFC, with Unicode spaces folded and zero-width characters removed. Invalid UTF-8 becomes "?", which validation refuses.

**Consequences.** The catalog is refreshed by regenerating its constants when the providers' lists change; the source files and dates are named in the class. A keyword stored before 0.44.3 with a non-breaking space or a decomposed accent can be added again once as its normalized form.

## ADR-045 — What worked: the site's own results ledger (0.45.0)

**Context.** Action plans say how well published tests back a kind of change (ADR-031). A site also collects its own evidence: measurement plans read behind readiness gates (ADR-042). Each result lived only on its own change page, so nobody could see which kinds of change pay off on this site.

**Decision.**
- `ResultsLedgerService` reads the result of each change with a plan, newest change first, at most 60. Each result is read the same way as on the change's own page (`MeasurementPlanService::plan`); nothing is stored or recomputed differently.
- The pure `Domain\Measurement\ResultsLedger` groups the results by change kind, and by the playbook key of the change bundle the change was deployed from.
  - *Measured* covers improved, declined and no clear change, plus goals reached (improved) and missed (declined).
  - Insufficient evidence and open plans are counted apart.
  - The median effect keeps percent and positions separate.
  - Fewer than 3 measured changes are flagged as too few to show a pattern.
- On a finding, the action plan shows the site's record for its playbook key, as one sentence. It never changes the "how well tested" level, the score, the priority or any estimate.
- Reminders list plans with no frozen baseline whose change has happened (goal plans need none), and changes with a URL from the last 180 days that have no plan.
- `PlaybookLibrary::forKey` builds a playbook from its key alone, to name the action plan of a bundle.

**Consequences.** The ledger is as good as the plans people set up; the page says how to make results readable. Uncontrolled results remain associations. Reading 60 plans reads their imports on each page view; if that becomes slow, the read results can be cached per day without changing what they mean.

## ADR-046 — Local consistency, review handling and category gap (0.46.0)

**Context.** The Local page showed Maps positions and the Business Profile. It did not show whether the website states the same business, how reviews are handled, or which category the businesses in the local pack use. The data for all three was already stored or could be read during the crawl, with no new paid request.

**Decision.**
- The crawler reads business details per page into `site_audit_pages.business_signals`. NULL means unknown, never missing.
- Three pure classes in `Domain\Local` do the comparison:
  - `NapCheck` compares the profile with the latest completed audit. The site's details come from the page that describes the business best: LocalBusiness markup first, then address, then name. Phones are compared by their last 8 digits, so a number matches with or without the country code. Addresses are compared by their numbers (house number, postal code), or by their words when there are no numbers. A field is ok, mismatch, missing or unknown.
  - `ReviewInsights` works on the imported sample of up to 100 newest reviews. A blank reply counts as none. Critical means 2 ★ or lower. The pace counts the 180 days before the import.
  - `CategoryGap` looks at the other businesses in the top 3. It reports a gap only when there are at least 3 such places, the leading category holds at least half of them, and at most a quarter share the profile's own category.
- `LocalSeoRule` turns them into the findings `gbp:nap`, `gbp:replies` and `gbp:category`. Each has its own data requirements and playbook (`local_seo:nap`, `local_seo:replies`, `local_seo:category`). Imported reviews are a new coverage source (`local_reviews`, fresh for 60 days).
- The NAP and category playbooks are marked tested. The reply playbook follows search engine guidance.

**Consequences.** A comparison needs one site audit after the update. A category gap is a prompt to check, not a verdict: the category must describe the business, and the finding says so. The review figures describe a sample of recent reviews, not the full history.

## ADR-047 — What a page could gain (0.47.0)

**Context.** The page details view already joined a URL's queries, rankings, audit issues, findings, changes and links into *What to improve*, ordered by a fixed priority per kind. It did not say what a fix was worth, how the page compared with the pages above it, or whether its earlier changes worked, although all three were already stored.

**Decision.**
- `Modules\Pages\PageOutlook` is pure.
  - `clickPotential` estimates the clicks at position 3 for searches at positions 4–20 with at least 50 impressions. It uses `CtrModel` trained on the project's query–page rows for the window, excluding brand terms from the domain.
  - `compare` takes the newest finished analysis from the last 180 days whose own URL is this page (scheme, `www`, host case and trailing slash ignored). It reads the readable pages ranked above it, at most five, and compares eight counts against their median. *Behind* means below 60 % of a median of at least 1.
- `ResultsLedgerService::forUrl` reads up to 10 changes on the exact URL from the last 365 days, each through `MeasurementPlanService::plan`. Changes without a plan are listed as such, never as "no change".
- *What to improve*: *Improve rankings within reach* moves to priority 95 with about 200 or more estimated clicks and to 85 with 50 or more, otherwise it stays at 80. A comparison with measures behind adds *Close the gap with the pages above it* at 75. Audit errors (100) stay first.
- `page_details` (MCP) returns the same evidence. Changes are presented through `Annotation::present()`, so no internal ID leaves the server.

**Consequences.** The click figure is an estimate on the site's own curve and is labelled as one. The comparison exists only for pages that an analysis has included. Reading up to ten plans adds their imports to each page view.

## ADR-048 — Long-title triage (0.48.0)

**Context.** A site audit lists every title over 60 characters. On Web Shop that was 949 pages, with no hint which ones matter.

**Decision.**
- `Modules\SiteAudit\TitleTriage` reads up to 2,000 long-title issues of the shown audit and the Google page and query rows of the 28 days ending on the last imported day. Only Search Console data is used; it never calls a provider.
- It ranks pages by click shortfall: expected clicks at the rounded average position (on `CtrModel` trained on the window's query rows, brand excluded) minus actual clicks.
  - Only pages at positions 1–10 with at least 100 impressions count. Below page one, the title is rarely what loses the click.
  - Pages are grouped by their first path segment, as a stand-in for the template.
- The card is computed only on the *Title too long* filter's first page, so the audit page itself reads nothing extra.
- The shortfall is labelled as one likely reason, not proof, because the search's own features also change click rates.

**Consequences.** The ranking says where to start, not how much shortening will gain. A brand search on a long-title page can show a shortfall that has nothing to do with the title.

## ADR-049 — Measure page changes by default (0.49.0)

**Context.** Measurement plans (ADR-042, 0.39.0) were opt-in. The person recording a change had to open its Impact page, fill in the plan and freeze the baseline. Web Shop recorded 79 page changes in 180 days and none got a plan, so *What worked* and the page details could not show a single result.

**Decision.**
- `MeasurementPlanService::draft` runs after a new change with a URL is saved. The change timeline form shows a *Measure this change* box, ticked by default; on MCP, `annotation_create` drafts unless `measure: false`.
- The draft has the objective "More clicks (or CTR) after “title”". It measures Google clicks, or CTR for a *Title / snippet* change, on the change's page, with no queries or control pages, 28 days on each side and the default review dates.
- The baseline is frozen in the same request. Changes cannot be dated in the future, so the period before the change has always ended.
- Drafting goes through the existing `save` and `freeze`, with the same permission check, audit events and version guards, as the person who recorded the change.
- It never fails the recorded change: a refusal is reported in the confirmation message.
- Not drafted for: site-wide changes, edits of an existing change, changes that already have a plan, and change bundles (they draft their own plan from the finding, ADR-031 amendment 0.41.0) and page-monitoring notes, whose callers do not ask for one.

**Consequences.**
- Every recorded page change becomes readable after 28 days of imported data. The result stays uncontrolled until someone adds control pages, and the result label says so.
- A frozen baseline with thin data reads as "insufficient evidence", never as "no change".
- Freezing reads the page's stored Search Console data once per recorded change.

## ADR-050 — Business details in the audit, brand searches as quick wins (0.50.0)

**Context.** The Local checks (ADR-046) need a Google Business Profile, which an online shop often does not have. A brand search where the site is not first (Web Shop: "northwind good" at #7) showed up only as an ordinary quick win aiming for the top 3, or not at all.

**Decision.**
- **Business details.** `IssueDetector::missingBusinessDetails` runs once per audit. It reads the start page (depth 0) and the pages whose path matches `CONTACT_PATH`, using the business details recorded since 0.46.0 (ADR-046) and the page's schema types.
  - It reports `missing_business_details` (a notice) on the start page. Missing items: no Organization family or LocalBusiness type, no phone (a `tel:` link or a telephone in markup), no address in structured data.
  - A start page without recorded business details is unknown, and nothing is reported.
  - The notice is one of the growth notices that become findings, with the playbook *State who runs the site*.
- **Brand searches.** `BrandSearchRule` reports Google queries with at least 20 impressions and an average position above 1.5 whose words name a brand word, as `QuickWin` with the subject `brand:<query>`.
  - A query names a brand word when one to three adjacent words of it, joined, equal the brand word, with or without a final "s".
  - Its data requirement is `search_console`, and its playbook key is `quick_win:brand`.
  - The click estimate is the gap to the site's expected CTR at position 1. The CTR curve excludes brand queries, so it understates brand CTR.
  - `QuickWinRule` skips these queries, so the same search is never reported twice.

**Consequences.**
- A shop that deliberately shows no address is told so. The finding is a notice and says what is missing, not that the site is wrong.
- Brand words come only from the project name and domain. A brand spelled differently, such as an abbreviation, is not recognized until it is added to the project name.

**Amendment (0.50.1, live-check findings 43 and 44).**
- The search is carried under the evidence label "Query", which the scoring models read.
- `brand:` subjects are treated like `query:` for search sources and outcome measurement.
- A project name made only of generic words (`BrandSearchRule::isGenericName`, such as "Web Shop") is no brand word, including for the click curve.

## ADR-051 — Measuring past changes (0.51.0)

**Context.** Plans became the default for new page changes in 0.49.0 (ADR-049), but earlier changes had none, so *What worked* would stay empty for at least 28 days. Their search data is already stored.

**Decision.**
- `ResultsLedgerService::measurePast` gives page changes from the last 365 days without a plan the default plan through `MeasurementPlanService::draft`, with its permission check, audit events and version guards. It handles up to 40 per request, newest first. It is started by a person, on `POST annotations/results/measure-past`, with `projects.edit`. Nothing is fetched or paid for.
- A result is *measured afterwards* when its baseline was frozen more than 7 days after the change. This is derived from `frozen_at` and the change date, so it needs no migration and also covers plans frozen late by hand. Its gates and calculation are unchanged.
- Each result shows how many other changes fall inside its comparison period, using the same list as the change's own page.

**Consequences.**
- The summaries by kind and plan count overlapping and afterwards results like the rest. The same effect can therefore be counted for several changes to one page. The page says so, rather than dropping them, because on a site that changes often little would remain.
- Measuring many changes makes *What worked* read more plans per view, up to its existing limit of 60.

## ADR-052 — Open source under AGPL-3.0 (2026-11-06)

**Context.** The owner decided to release SEOcc as free, open source software on GitHub, starting with the application only. A hosted version may follow later. This replaces the commercial model of ADR-026, ADR-021 and the licence-specific parts of ADR-009. Existing customers' licences are a separate decision for the owner.

**Decision.**
- **License:** AGPL-3.0-only, copyright Trio Group LLC. A sidebar *Source code* link (`SOURCE_CODE_URL`) offers the source, as section 13 expects, also on white-label installations. The names and logo stay trademarks (`TRADEMARKS.md`).
- **Contributions:** accepted under the same license with a DCO sign-off, not a contributor agreement. Relicensing later would therefore need contributors' consent.
- **No licensing code:** licence keys, editions, limits, entitlements, the license server client and System → License are removed. A migration drops `license_state`, including any stored key.
- **No phone-home:** the daily update check and its top-bar badge are removed. Updates are signed packages an administrator downloads from GitHub Releases and uploads. Forks use their own key through `UPDATE_PUBLIC_KEY`.
- **`FeatureManager` stays as the single switch:** every feature is on unless listed in `DISABLED_FEATURES`. A feature that is off still stops new work only (ADR-009).
- **Installation:** the one-click launcher belonged to the license server, so a new install extracts the install package and uses the built-in wizard. The wizard proves control of the server with `storage/setup-key.txt`.
- **Repository:** private release notes, live-check audits, marketing and client names are not published, and publishing starts from a fresh history.

**Consequences.**
- Installations no longer need or contact a vendor server. They also stop learning about new versions by themselves until a check against GitHub Releases is built.
- Anyone can run a competing hosted service, but must publish their changes and may not use the names.
- A plain install relies on the root `.htaccess` for private files, so the guide has people confirm that `version.json` answers 403.
