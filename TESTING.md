# Testing Strategy

## Required test categories

- Page improvement coverage checks deterministic priority, decline thresholds, missing and incomplete data, query impression/position thresholds, tracked-keyword evidence, provider text escaping, MCP parity and existing project restrictions. Persistence coverage verifies project/date-scoped completed import-day counts.

- Pages persistence includes a database-default regression: temporarily set only the disposable `_test` schema default to Latin-1, verify Unicode backlink URLs through catalog count/list/find, then restore the original default in `finally`. Tables remain UTF-8. Run this on both supported engines; the live failure was observed on MariaDB 11.4, while the current local runner is MySQL 8.4.

- unit tests;
- integration tests;
- HTTP/request tests;
- database migration tests;
- provider contract tests;
- queue concurrency/idempotency tests;
- security regression tests.

## Highest-priority cases

### Tenant isolation

Attempt to access Workspace B resources while authenticated as Workspace A.

Test browser routes, application services, API endpoints, and MCP tools.

### Authorization

Verify every protected action checks a capability/permission.

### Authentication/session

Test session rotation, logout, CSRF, MFA flow, throttling, and reset behavior.

### Encryption

Verify secrets are encrypted at rest and can be rotated by key version.

### Logging

Verify secrets are redacted.

### Queue

Verify two workers cannot claim the same job.

Verify retries, failures, idempotency, and bounded execution.

### Licensing

Test:

- valid signature;
- invalid signature;
- expired entitlement;
- cached/grace behavior;
- feature limits;
- vendor endpoint unavailable.

### Updater

Test signature/hash rejection, migration failure, interrupted state, and health-check failure.

### Provider limits

Verify DataForSEO budget limits and duplicate-job prevention.

### MCP

Verify:

- scope enforcement;
- project constraints;
- revocation;
- expiry;
- rate limiting;
- no secret exposure;
- audit creation.

### SSRF

Test private IPv4/IPv6 ranges, DNS resolution, redirects, and metadata endpoints.

## Upgrades

`tests/Integration/UpgradePathTest.php` (needs the test database) checks two things:

- **Schema:** the database of every release, from 0.1.0 on, upgrades to exactly the same schema as a fresh installation. The test compares `SHOW CREATE TABLE` for every table and checks that no applied migration has changed.
- **Data:** Search Console data written by 0.7 works after the upgrade, both before and after the monthly rollups are built.

When a release ships a migration, add its last migration to `RELEASES` in that test.

## Accessibility

`tests/Http/AccessibilityTest.php` renders every signed-in page, the sign-in page and forms with validation errors, and checks them against the CODING_STANDARDS accessibility rules:

- `lang` and `<title>` are set;
- there is exactly one `h1`;
- there is a `<main>` with a skip link;
- ids are unique;
- every form control has a label;
- buttons and links have text;
- table headers have a scope;
- images have `alt`;
- field errors are linked to their controls.

Manual checks with a keyboard and a screen reader are still needed for new interactions.

## Definition of done

The shared UI refresh adds HTTP coverage for the integration directory's real card hierarchy and role-sensitive setup links, readable setup guides without embedded action forms, and the expanded accessibility route set. Pages HTTP/PDO cases cover filtering beyond the first page, page-size persistence, global traffic sorting before pagination, literal SQL wildcard escaping and project isolation. Manual browser verification covers dark/light themes, native disclosure help and Escape, mobile navigation, local loaded-row filtering/sorting/pagination, chart keyboard values and responsive table containment. Static previews use Scenario fakes only and are removed after verification; real provider credentials are not needed for UI checks.

Verified on 2026-10-04: full PHPUnit **802 tests, 6,569 assertions**, all passing (16m00s, MySQL 8.4); PHPStan level 8 no errors; CS Fixer dry run zero changes. The final mock browser sweep checked 35 screens at 320/768/1440px with no document-level horizontal overflow. Health's visual preview uses mock check rows because the in-memory harness does not provide its real database/diagnostic environment. See `build/ui-refresh-verification.md` and `build/ui-responsive-checks.json` for local evidence.

A high-risk or user-visible feature is not done until its relevant tests exist and pass.

## Tooling and CI

- PHPUnit, PHPStan, and PHP CS Fixer, as configured in `CODING_STANDARDS.md` → *Tooling*.
- `composer check` must pass before merging to `main`.
- Integration tests run against a real database, not SQLite, because queue claiming, locks, and SQL portability are what is being tested.

CI matrix (minimum):

| PHP | Database |
|---|---|
| 8.5 | MariaDB 10.6 |
| 8.5 | MariaDB current LTS |
| 8.5 | MySQL 8.0 |
| 8.5 | MySQL 8.4 |

Add newer PHP versions to the matrix as they are released.

### Additional required cases from recent decisions

Remaining-gap coverage (2026-10-05) includes threshold validation and project permissions; webhook encryption/rotation, hidden form secrets, DNS/SSRF denial, revoked access, changed configurations, redacted failure history and once-per-event queue/delivery; GA4 retained history, exact-URL comparisons beyond a global row cap, quality flags in both selected periods, empty-date coverage and property changes; referenced-resource persistence and safe status classification; exact-CID branch grids with duplicate/stale-run protection and resumable points; workspace branding, section choices across printable/HTML/text output and raster logo validation/tenant scope. UpgradePathTest covers the latest released schema (`000046`) and proves its GA4 snapshot/empty dates survive all six new migrations.

Final verification (2026-10-05): full PHPUnit **825 tests / 7,165 assertions** passes in 17m40s against local MySQL 8.4; PHPStan level 8 clean; CS Fixer dry run zero changes across 943 files. Responsive synthetic previews cover mobile/tablet/desktop, including 7×7 branch grids and selected-section printable reports; see `build/gaps-ui-verification.md`. Website guide synchronization and production build validate 31 pages. Temporary previews removed. No live provider calls, credentials, deployment or publication; shared-host/MariaDB and real-provider verification remain owner-controlled.

Local responsive previews use only in-memory fictional data, without provider calls or database mutation. Live Slack/JSON delivery, paid Maps checks, provider history quotas and MariaDB/shared-host installation remain separate owner-controlled acceptance checks. Browser Print/Save as PDF remains the PDF path; no server-side PDF dependency is added.

Unreleased vendor-update cases cover signed metadata, altered/incomplete/oversized packages, same-vendor download paths, vendor outage, cached reads without network, preserved manual uploads, role isolation and CSRF, and a real-MySQL HTTP enqueue → deduplicated queue → signed staging → backup-gated install flow in a disposable application tree. Signing keys and credentials are synthetic. License-server tests cover activated-domain ownership, active/expired/suspended license rules, release withdrawal, one-hour token download checks and the portal notice/tutorial. Desktop/mobile previews use synthetic data only. The fixture's installer omits a real backup; the existing full DatabaseBackup/UpdateInstaller suites separately cover backup and recovery behavior.

UI audit regression coverage verifies keyword entry opens for first use and validation errors and collapses for an existing tracked list. Populated browser fixtures check bordered table insets, provider-badge help, named scroll regions, local filtering/page size, unchanged suggestion checkboxes, Performance containment at 320/390/768/1280/1440px, and 3×3/5×5/7×7 ranking grids at 320px. These supplement the earlier broad route sweep, which did not exercise populated Performance columns or the largest local grid.

UI audit verification on 2026-10-04: full PHPUnit **803 tests / 6,576 assertions**, passing in 16m01s against local MySQL 8.4; PHPStan level 8 no errors; CS Fixer dry run zero changes. Changed keyword view lint and git diff whitespace checks pass. See `build/ui-audit-verification.md` and its synthetic-data screenshots. Temporary public previews and the temporary server were removed; no live settings or provider actions changed.

Page integration coverage includes joint query/page replacement and purge without altering marginal totals, exact URL case/query matching and cross-project catalog isolation, cannibalization thresholds, crawl-edge exclusions, new MCP scope restrictions, CrUX no-data/encrypted-key cases, GA4 signed read-only assertions and property-change guards, branch exact-CID matching, primary-history preservation, free review polling without resubmission, escaped reviews and all new route permissions/CSRF. Every new repository has a fake in `TestApp` and a real MySQL persistence test. Service-account test keys are generated in memory with the test-only OpenSSL config; no installation signing keys are fixtures.

- **Roles (ADR-013):** member without project membership is denied; project role narrows but cannot widen; last owner cannot be removed; `system.*` permissions require `system_role = admin`.
- **Key ring (ADR-014):** decrypt with retired key; encrypt uses active key; AAD mismatch (value copied to another row) fails; missing key id fails safely; re-encryption job converges.
- **Locks (ADR-015):** acquire, contention, expiry takeover, non-owner release fails, killed-process recovery.
- **Scheduler:** overlapping cron runs enqueue each scheduled slot exactly once.
- **Installer:** refuses unsupported PHP/database versions with a clear message.

Customer-center/vendor-update final verification on 2026-10-04: full PHPUnit **812 tests / 6,635 assertions**, passing in 15m54s against local MySQL 8.4; PHPStan level 8 clean; CS Fixer dry run zero changes. License-server full suite passes **33 tests / 721 assertions**, with its PHPStan level 8 and CS Fixer checks clean. Responsive badge plus spending-chip checks at 320/390/768px show no document overflow. Temporary previews removed; both repositories remain undeployed.

Installer UI verification on 2026-10-04: full PHPUnit **812 tests / 6,635 assertions**, passing in 16m36s; CS Fixer zero changes, PHPStan level 8 clean and all changed PHP views lint clean. Synthetic ready, blocked, inline-error and completion screens verified; ready/completion layouts fit 320/390/768/1440px without document overflow. Setup-key protection and installation behavior remain covered by InstallerTest. See build/installer-ui-verification.md and screenshots. No live installation, credentials or settings changed.

