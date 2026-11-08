# Security

## Reporting a vulnerability

Please do not open a public issue. Use [private vulnerability reporting](https://github.com/seoccnet/seo-control-center/security/advisories/new) and include the version, the steps to reproduce and the impact. Only the latest release gets security fixes while SEOcc is in early alpha.

Paid data and client websites are out of scope for testing: use your own installation, and no one else's.

# Security Requirements

## Page monitoring (Unreleased)

Watch and annotation POSTs require CSRF and ProjectsEdit after project visibility resolution. Watches accept bounded exact HTTPS URLs on the current primary domain/www, without credentials, port, fragment or whitespace. The existing crawler enforces public-address/TLS/request limits and robots.txt; no redirect target or discovered link is fetched. Jobs bind workspace/project/public UUID plus watch generation and revalidate the current domain and active watch. Leases serialize checks and scheduling; row locks serialize capacity, snapshots and annotation linking. Failed/truncated checks retain evidence. Views escape all page text and use no-store. Mutation audits contain project/annotation identifiers, not fetched content. Detection summaries and annotations are untrusted plain text; no website writes or new AI permissions are added.

Watch lookup, due dispatch and successful persistence join active projects/workspaces, including a final locked persistence check for deletion during fetch. Direct alert-history selection binds project/workspace; foreign or expired change UUIDs return 404. Stopping a watch remains possible after its domain changes.

## AI/MCP replay and availability hardening (Unreleased)

All new read tools reuse the existing seven scopes and ToolContext tenant/project/current-user checks. Project overview gates each data family separately; alert monitoring requires opportunities:read and budget monitoring current integrations.manage permission. Public-guide retrieval serves only the generated numbered-chapter catalog, never arbitrary files/URLs or private developer docs. Credentials, recipient addresses and provider configuration are excluded.

Before model replay, current authorization is rechecked for historical reads. Revoked project/data access discards affected history; successful project-list results are refreshed from current access. Conversation mutation uses the existing PDO lease mechanism per workspace/user (180-second lease, released in finally). This prevents overlapping sends, confirmations and resets across workers without a new migration. Provider cooldown and bounded identical-step retries reduce repeated failures; conservative uncertain-charge accounting prevents a failed connection from silently restoring spending allowance. Existing confirmation and role checks remain required for writes.

## SEO team tasks (Unreleased)

Project authorization precedes task reads/writes; project editors save, viewers read. POST requires CSRF. Opportunity references must belong to the project. Assignment requires active workspace membership, active user and current ProjectsEdit access, never grants access. Optional brief/annotation links resolve within the project and cannot target removed records. Public UUIDs only, bounded UTF-8 plain text, valid due dates and existing exact-project URL validation; URLs are not fetched. Atomic revision checks preserve concurrent work and audit metadata excludes notes. Escaped views are no-store. MCP seo_tasks_list requires opportunities:read and existing project/token constraints, reads only, marks notes untrusted and hides unavailable-assignee identifiers. No secrets or provider calls.


## Google indexing monitoring (Unreleased)

Project authorization precedes reads and writes. Inspection/watch POSTs require ProjectsEdit and CSRF. Exact bounded HTTP(S) URLs must belong to the project primary domain/www and linked property, without credentials, port, fragment, whitespace or backslash. They are JSON data sent to the fixed HTTPS host searchconsole.googleapis.com; neither URLs nor canonicals are fetched directly. Existing encrypted Google OAuth tokens and webmasters.readonly are reused.

Jobs bind project/workspace/public UUID and connection/property digest, and revalidate live ownership, active Google connection, entitlement and property before calling and saving. Responses are bounded to 256 KiB; only bounded plain-text index fields are stored, escaped and marked untrusted to AI. Generic provider errors are stored/logged instead of private provider messages. Failed checks retain last-success evidence. MCP indexing_status/page_details use pages:read with selected-project/current-user restrictions; no inspection/watch write tool. HTML reads are no-store.

## SEO change impact (Unreleased)

Read-only reports resolve an authorized project then its scoped annotation UUID; foreign/unassigned/missing/removed targets return404. Only14/28/90-day windows are accepted. Notes/URLs are escaped plain text and responses no-store. MCP seo_change_impact uses existing pages:read; site-wide totals additionally require search:read, retaining selected-project/current-user restrictions. No provider/secret access or write; AI treats notes as untrusted and must not claim causation.

## Content briefs (Unreleased)

All browser reads, print/text downloads and MCP calls require current project visibility. Create/edit/remove and edit forms require ProjectsEdit; mutations require CSRF. Foreign/unassigned project and foreign brief/source UUIDs return 404; visible viewers receive 403 for edits. Repository queries bind workspace/project, including the create INSERT SELECT. Optimistic revision predicates protect concurrent updates/removal. Conflict rendering retains escaped user text and prevents resubmitting the stale form.

Plain UTF-8 lengths, workflow values, control characters and exact same-project HTTP(S) URL rules are validated. URLs are never fetched; evidence and form values are escaped, no HTML/Markdown rendering or inline style is introduced. Browser responses and private UTF-8 text downloads use no-store; the attachment name contains only a generated UUID. Audits record identifiers, not content. Briefs are project-shared data: keep secrets out. Read-only AI tools retain pages:read/project restrictions and explicitly identify brief/provider text as untrusted context. No AI mutation or ADR change is introduced.

## SEO change annotations (0.9.41)

Reads require project visibility; create/edit/remove require `ProjectsEdit` and CSRF. Foreign or unassigned project/annotation UUIDs resolve to 404. Queries bind workspace and project; views escape titles, notes and URLs. Dates, kinds, length and same-project HTTP(S) URLs are validated; no URL is fetched. Mutation audit metadata contains identifiers rather than note text.

Notes are plain-text project data, visible to authorized project readers and read-capable AI clients. Existing `projects:read` covers `annotations_list`; `page_details` retains `pages:read`. Notes must not contain secrets. Assistant guidance treats them as untrusted evidence rather than instructions or proof of causation. No annotation write tool or scope-policy change is introduced.

Security requirements are mandatory for release.

## Optional fresh-install PHP launcher


## Authentication

- Use PHP password hashing APIs.
- Prefer Argon2id where supported.
- Regenerate session identifiers after login, privilege changes, MFA completion, and password reset.
- Support TOTP MFA and recovery codes.
- Implement login throttling: at most 5 failed attempts per account+IP and 50 per IP within 15 minutes; the next attempt is refused with 429 even if the password is right. The account+IP bucket is cleared on success. Per-IP scoping prevents an attacker from locking out a victim's account from elsewhere.
- Unknown e-mail and wrong password produce the same message and comparable timing (dummy hash verification).
- Do not reveal whether an account exists in password-reset responses.
- **Password reset (ADR-027):**
  - Only the SHA-256 of the 32-byte token is stored; the link is valid for 60 minutes and works once.
  - A new request invalidates older links.
  - Requests are limited to 10 per IP and 3 per address per hour.
  - A reset signs out all of the user's sessions.
  - The reset page sends `Referrer-Policy: same-origin`, so the link never reaches another site. It is not `no-referrer`: under that policy browsers post the page's own form with `Origin: null`, which the CSRF check refuses.
  - Known limitation: the response time differs slightly when a message is actually sent.
- **Own password, e-mail and name (Your account):**
  - Changing the e-mail address or the password requires the current password.
  - Wrong current passwords are limited to 5 per user per 15 minutes.
  - A password change signs out the user's other sessions.

## Session cookies

Use:

- `Secure`;
- `HttpOnly`;
- `SameSite=Lax` or stricter when compatible.

### Implementation (ADR-019)

- Server-side sessions in the `sessions` table; the cookie holds 32 random bytes, the database only their SHA-256 hash.
- Cookie `__Host-scc_session` on HTTPS (`scc_session` on plain-HTTP local development), `Path=/`, `HttpOnly`, `SameSite=Lax`, `Secure` on HTTPS.
- Idle timeout 2 hours, absolute lifetime 7 days (`SESSION_IDLE_MINUTES`, `SESSION_LIFETIME_HOURS`).
- New session ID and new CSRF token on login; workspace switches also rotate the ID. Logout deletes the server-side session.
- Guest visits create no session row until something is stored (e.g. the login form's CSRF token).
- Disabled users are signed out on their next request.

## Authorization

Use permission-based authorization.

Do not scatter `isAdmin()` checks throughout business logic.

Every tenant-owned resource must be authorized against workspace/project scope.

### Model (ADR-013)

- One role per user per workspace. Seeded roles: `owner`, `admin`, `member`, `viewer`.
- `owner`/`admin` see all projects in the workspace; `member`/`viewer` see only projects where they are in `project_members`.
- A project role may narrow, never widen, the workspace role's permissions.
- Installation administration uses `users.system_role` and `system.*` permissions.
- All checks go through one service, e.g. `$authorizer->check($user, 'projects.edit', $project)`. Templates and controllers never inspect role names.

### Starter permission catalog (0.1)

| Permission | owner | admin | member | viewer |
|---|---|---|---|---|
| `workspace.view` | ✓ | ✓ | ✓ | ✓ |
| `workspace.manage` (name, settings) | ✓ | ✓ | | |
| `workspace.members.manage` | ✓ | ✓ | | |
| `workspace.delete`, `workspace.transfer` | ✓ | | | |
| `projects.view` | ✓ | ✓ | ✓ | ✓ |
| `projects.create` | ✓ | ✓ | | |
| `projects.edit` | ✓ | ✓ | ✓ | |
| `projects.delete` | ✓ | ✓ | | |
| `integrations.manage` | ✓ | ✓ | | |
| `analysis.execute` (spends provider budget) | ✓ | ✓ | ✓ | |
| `audit.view` | ✓ | ✓ | | |
| `tokens.manage` (API/MCP) | ✓ | ✓ | | |

System permissions (`system.health.view`, `system.update`, `system.settings.manage`, `system.users.manage`) are granted only by `users.system_role = admin`.

The last `owner` of a workspace cannot be removed or demoted.

### Not-found versus forbidden

Resources a user must not know about — another workspace's project, or a project in their workspace they are not assigned to — answer **404**, exactly like a non-existent ID. **403** is used only when the user can see the resource but lacks the permission for the action (e.g. a viewer opening the edit form). Every project lookup goes through `ProjectRepository::findInWorkspace($workspaceId, $publicId)`; there is no way to load a project by ID alone.

Since 0.44.2, a `GET` or `HEAD` request for `/projects/{id}…` that ends in 404 in the current workspace is retried in the user's other workspaces, one `findInWorkspace` call per membership. If the project is in one of them and `Authorizer::canSeeProject` allows it, the session switches to that workspace. The session ID is regenerated, as with the workspace switcher, and the browser is redirected to the same path with a notice. Otherwise the 404 stands, so a project the user cannot see is still indistinguishable from one that does not exist. Writes are never redirected.

## CSRF

All state-changing browser requests require CSRF protection.

Implementation: a per-session synchronizer token (`_csrf` form field or `X-CSRF-Token` header, compared with `hash_equals`) for POST/PUT/PATCH/DELETE, plus rejection of a foreign `Origin` header. Failures answer 419 (bad/missing token) or 403 (foreign origin). Bearer-token APIs under `/api/` and `/mcp` are exempt because they do not use cookies.

Do not mutate state using GET requests.

## SQL

Use prepared statements exclusively for untrusted values.

Never concatenate user-controlled values into SQL.

## Output encoding

Escape output by context.

Never assume stored database content is safe HTML.

## Secret encryption

Provider/API/OAuth secrets must be encrypted using libsodium authenticated encryption.

The application master encryption key must not be stored in the database or public web root.

Support key versioning so secrets can be rotated.

### Implementation (ADR-014)

- Algorithm: `sodium_crypto_aead_xchacha20poly1305_ietf_encrypt` with a random 24-byte nonce per value.
- Keys come from the key ring (`ENVIRONMENT.md` → *Encryption keys*); new values always use the active key.
- Ciphertext envelope includes a format version and key id (`DATABASE.md` → *Encrypted columns*).
- AAD binds each value to its table, column, and row public ID.
- Decrypted values live in a `SecretString`-style value object that is never serialized, logged, or rendered, and whose `__toString`/`__debugInfo` return a redacted placeholder.
- Zero plaintext buffers with `sodium_memzero()` where practical.

### Key ring commands

- `php bin/console crypto:key:generate` — creates the key ring or adds a new active key.
- `php bin/console crypto:rotate` (`--status` to only report) — re-encrypts every registered encrypted column; the `crypto.reencrypt` job does the same in the background.
- Encrypted columns are registered in `bootstrap/app.php` (`SecretRotator`); a new encrypted column is not complete until it is registered there.

## E-mail (ADR-027)

- MCP sign-in (ADR-029):
  - **Codes and refresh tokens:** stored as SHA-256 hashes only and consumed atomically, so each works once. A refresh revokes the previous token.
  - **Redirects:** redirect URIs are limited to https on the configured AI app hosts, and an unknown client or redirect address is shown as an error, never redirected to.
  - **PKCE:** S256 is required.
  - **Approval:** only users with `tokens.manage` can approve, for a workspace they choose.
  - **Abuse limits:** registration and token requests are rate-limited per IP.
  - **Return after login:** only local paths are accepted.
- The AI assistant's API key (Claude or OpenAI) is encrypted with the key ring (context `assistant.key.{workspace_id}`), registered for `crypto:rotate`, and never rendered (ADR-028). The assistant's tools see only the projects the signed-in user can see. Its only write, changing an opportunity's status, runs after the user confirms it in the chat, through `OpportunityService` (permission check, audit log). The page path the chat sends is used as context only for a project the user can see.
- The SMTP password is encrypted with the key ring (context `mail.smtp`) and re-encrypted by `crypto:rotate`. It is never rendered; an empty form field keeps it.
- TLS certificates of the mail server are verified (STARTTLS or implicit TLS).
- Header values are checked for line breaks (no header injection).
- Error messages quote the server reply but never the password.
- Mails with one-time links are sent inline and never queued, because queue payloads must not contain secrets.

## MFA (ADR-020)

- TOTP per RFC 6238 (SHA-1, 6 digits, 30 s, ±1 step), secret encrypted with the key ring; each time step can be used once (`last_used_step`, enforced with a conditional UPDATE).
- 10 single-use recovery codes, stored as SHA-256 hashes, shown once.
- Sign-in with MFA: password step stores only a 5-minute pending marker in the session (ID rotated); the user is signed in after the code step. Code step: 5 attempts per 15 minutes per user.
- Disabling MFA requires the current password and a valid code; regenerating recovery codes requires the password. The pending enrollment secret is encrypted inside the session.
- Users can sign out all other sessions from Account security.

### Rotation

1. `bin/console crypto:key:generate` adds a new key and marks it active.
2. A queued `ReencryptSecrets` job re-encrypts rows in batches to the active key.
3. System Health shows how many values still use old key ids.
4. An old key may be removed from the ring only when no values reference it; the command refuses otherwise.

## Logging

Never log plaintext:

- passwords;
- access tokens;
- refresh tokens;
- API keys;
- authorization headers;
- session cookies;
- database credentials;

Implement central log redaction.

## Audit log

Audit sensitive operations, including:

- login and failed login;
- role changes;
- user invitations/removal;
- integration connection/disconnection;
- API/MCP token creation/revocation;
- project deletion;
- system configuration changes;
- export creation;
- sensitive administrative operations.

## Content Security Policy

Start with a strict self-hosted policy.

Avoid unnecessary third-party script/style CDNs.

## SSRF

Any server-side URL fetcher, crawler, webhook caller, or importer must prevent access to private/internal network ranges and metadata services.

Block private, loopback, link-local, and equivalent IPv6 ranges.

Resolve DNS and validate the resolved destination before connecting.

Re-check redirects.

Provider adapters use `Core\Http\Client\HttpClient`, which only allows HTTPS to hosts explicitly listed by the adapter, rejects user-info/port tricks and header injection, and never follows redirects. Hosts that users enter (IndexNow key files and sitemaps, 1.1) are resolved first through `Core\Http\Client\HostResolver`, and every address must be public (no private, loopback, link-local or reserved range). The site audit crawler (1.2) uses the same check for every host it fetches, stays on the project's domain (with and without `www.`), reads at most 2 MB per page and only requests one page at a time.

## Uploads

- Store uploads outside the public web root where practical.
- Generate server-side filenames.
- Validate actual MIME type.
- Apply size limits.
- Use extension allowlists.
- Never execute uploaded files.

**Implemented uploads:**
- *Release packages* (System → Updates): verified by signature, see UPDATES.md.
- *Branding logo* (System → Branding, `system.settings.manage`):
  - **Type and size:** only PNG, JPEG and WebP, recognised by `getimagesize()` rather than the file name. SVG is refused because it can carry scripts. Size is limited to 512 KB and to between 16 and 2,000 pixels per side.
  - **Storage:** stored in `storage/branding/` under a random name, outside the web root.
  - **Serving:** `/branding/logo` is public, because the sign-in page needs it. It is served with the detected content type, `nosniff` and `Content-Security-Policy: default-src 'none'`.
## Rate limiting

At minimum rate-limit:

- login;
- password reset;
- API;
- MCP;
- OAuth callbacks/flows where appropriate;
- expensive analysis operations;
- HTTP cron fallback.

## MCP

MCP writes are limited to scoped, non-billable, non-destructive changes on existing records (ADR-036): separate write scopes never granted by default, the creator's current permission checked in each service, optimistic concurrency, idempotent creates, 20 writes per minute per token and audit events that never contain written text. Billable and destructive actions stay outside MCP.

MCP tools must never return secrets or provide unrestricted SQL/database access.

Every call is scope checked and audited.

## Updates

Only install releases that pass signature/hash verification.

Never overwrite live files while a package is still downloading.

## Security review log

### Migration/update recovery (0.9.49, 2026-10-06)

Reviewed updater trust boundaries, concurrent requests, interrupted file/schema changes, package identity, backup retention and restore validation. Signatures and file hashes remain mandatory; equal-version recovery accepts only the exact persisted signed manifest. Publication/install/discard share a filesystem mutex, and the front controller blocks bootstrap while the active update holds it. Symlink destinations, path aliases, duplicate ZIP entries and oversized unsigned metadata are rejected. Failed recovery preserves maintenance and the original backup; malformed uploads do not replace the pending package. Permission and CSRF checks are preserved.

Migration history permits only the audited exact legacy repair pairs; unexpected edits/removals fail before pending migrations execute. A database session lock protects long DDL. Backups use a consistent InnoDB snapshot and unique filenames; protected restore points survive retention. Restore validates the gzip CRC/length and completion footer before destructive SQL. Regression receipts and deployment limits are recorded in an internal audit; no credentials, keys or customer data are included in release packages.

### Remaining feature gaps (Unreleased, 2026-10-05)

Project thresholds and report sections require `projects.edit`; workspace report branding/logo requires `workspace.manage`; alert destinations require project `integrations.manage`. All protected routes first hide foreign/invisible projects with 404, and mutations require CSRF and audit events. Branch grids require `analysis.execute` and the existing budgeted paid path. CID matching and run UUIDs prevent cross-branch/history confusion.

Webhook URLs and bearer tokens are encrypted in binary columns with configuration-version AAD and registered rotation. Forms keep secrets blank; audit events and job payloads contain no destination secrets. Delivery logs omit provider response bodies and exception messages. The worker verifies the configuring user's active membership/permission again. Slack payload blocks use plain text to avoid interpreting provider/user content as channel mentions.

The central Redactor masks webhook URL fields and Slack incoming-webhook URLs in strings, including generic URLs with query parameters. Settings input is marked SensitiveParameter, and webhook objects mask debug output and reject serialization.

The shared HTTP client resolves the allowlisted hostname before transfer, rejects any private/reserved/mixed address set (including mapped private IPv4), pins a verified public address with `CURLOPT_RESOLVE`, disables proxy bypass and does not follow redirects. Successful lookups are cached in the PHP process for 60 seconds (`CachingHostResolver`). The cached answer passes the same public-address check on every request and is the address the connection is pinned to; failed lookups are not cached. Requests in one process share a connection and TLS-session cache (`curl_share`). Responses may be compressed, and byte limits apply to the decoded body. HTTPS/TLS verification remains mandatory. Webhooks additionally reject credentials, IP literals, explicit ports and fragments. Sampled crawler targets use the same safeguards and robots rules; neither scripts nor images are executed/rendered.

Workspace report logos accept only bounded raster MIME/dimensions, are stored as bytes and served through authorized project routes with nosniff/no-store and a restrictive CSP. Report CSS accepts a validated hex accent and escapes text in every output. No executable or SVG logo upload is allowed. A workspace override never changes global navigation branding.

### Page integrations (Unreleased)

New CrUX and GA4 settings require `integrations.manage`; secrets are encrypted in binary columns with workspace-bound AAD, never returned in forms, and included in secret rotation. Google service-account assertions and `X-Goog-Api-Key` are redacted both by structured key and inside strings. GA4 uses `analytics.readonly`, fixed Google endpoints and no new Search Console OAuth permissions. OpenSSL signing uses only the owner's explicitly configured GA4 credential in memory; it never accesses release signing keys.

GA4 credential parsing accepts inline RSA PEM data only. File references such as `file://` are rejected before OpenSSL, preventing a credential upload from reading an installation file. Credential debug output masks the private key.

Page/MCP reads authorize the project before joining source data. The new `pages:read` scope is explicit and is not added to existing tokens. Local branches are addressed by UUID within their project; job payload workspace/public-project identity is rechecked before paid calls. Review text is escaped, including owner replies, with normalized sample size/text bounds. Free task retrieval accepts only a saved UUID and a fixed endpoint; there is no arbitrary provider URL. Ambiguous paid submission is not automatically retried.

### 2026-09-27: changes 0.2–0.8 (Search Console to backups)

Checked all code added since 0.1.0 (280 files):

- **Output escaping (XSS):** all provider and user data in views goes through `$view->e()`. The raw echoes are fixed text or helpers that escape internally.
- **SQL:** dynamic SQL uses only constants, `%d` integers and table names from the application's own schema. All input is bound.
- **Authorization and tenant isolation:** every new route loads projects through `ProjectService::get()` (404 for other workspaces and unassigned members) and checks `projects.edit`, `analysis.execute`, `integrations.manage`, `tokens.manage` or `system.*` before changing or spending anything. Keywords, competitors, opportunities and tokens are looked up by public ID within the project or workspace. HTTP tests cover 403/404 for each module.
- **Paid calls:** only through `DataForSeoClient` with a budget reservation. Repeats are idempotent, and no web request calls a provider.
- **SSRF:** provider adapters call fixed host allowlists only.
- **Secrets:** DataForSEO passwords and OAuth secrets are encrypted and never rendered. MCP tokens and workspace invitation links are stored as hashes and shown once (not flashed into the session). Invitation links expire after 7 days, work once, and the public invitation page is rate-limited per IP and sent with `Referrer-Policy: same-origin` (not `no-referrer`, which makes browsers send `Origin: null` and the CSRF check refuse the form). Someone who creates a new account through an invitation is signed in straight away; an existing account joins and then signs in with its own password, because holding the link proves nothing about that password. Logs, the support bundle and audit metadata contain no secrets.
- **MCP:** bearer tokens bound to their creator's current access, scopes, project lists, Origin check, rate limits and audit per call. Reads, plus opt-in scoped writes (ADR-036).
- **Files:** backup downloads accept only generated file names, restore is console-only, and support bundle temp files are deleted.

Hardening applied in this review: backups are written with mode 0600, and failed MCP authentication is limited to 30 attempts per minute per IP.

## Brand-name mention boundaries (Unreleased)

Settings require scoped `projects.edit`; starting checks or opting into their weekly paid schedule requires `analysis.execute`. Protected POST routes enforce CSRF, project visibility (foreign/unassigned 404), permission denial (403), rate limits (six manual requests per project/hour), feature switch and provider budgets. Settings/request audit events use public IDs and non-content metadata; no raw name or answer is logged. Jobs recheck the active requesting user and current permissions with uncached membership before each paid step. All repository operations include workspace/project boundaries.

Brand names are bounded valid UTF-8 plain text, answer excerpts are escaped, source URLs are displayed as text, and provider content remains untrusted context. MCP exposes saved name results under existing `rankings:read` with public UUIDs only, no write or paid tool, and bounded history without repeating answer excerpts. A conditional persisted claim before transmission and single-attempt jobs prevent silent repeated billing after an ambiguous interruption. Already sent provider requests cannot be cancelled by later permission/settings changes.
