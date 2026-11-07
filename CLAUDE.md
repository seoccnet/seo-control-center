# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project

SEO Control Center: a free, open source (AGPL-3.0), self-hosted SEO platform built as a **PHP 8.5 modular monolith for shared hosting**. It targets MariaDB 10.6+ or MySQL 8.0+. There is no framework, and it must not need Docker, Redis, Node, Supervisor or any long-running process in production.

`INSTRUCTIONS.md` is the authoritative brief and wins over the other docs. `DECISIONS.md` holds the ADRs. Don't change an ADR's policy on your own. Record any unresolved tradeoff there instead. The topic docs are ARCHITECTURE, SECURITY, DATABASE, QUEUE_AND_CRON, INTEGRATIONS, MCP, TESTING and CODING_STANDARDS. Read the relevant one before working in that area, and update docs and `CHANGELOG.md` when behavior changes.

## Commands

```bash
composer check                 # cs + phpstan + phpunit — must pass before merge (what CI runs)
composer test                  # all PHPUnit suites
composer test:unit             # unit suite only
vendor/bin/phpunit --testsuite http            # suites: unit, http, security, tenant-isolation, integration
vendor/bin/phpunit --filter testMethodName tests/Http/ProjectsHttpTest.php
composer analyse               # PHPStan level 8 (app, bootstrap, config, routes, database, bin, tests); no new baseline entries
composer cs / composer cs:fix  # PHP CS Fixer (@PER-CS + strict_types, ordered/no-unused imports)

php bin/console list           # db:check, migrate, migrate:status, make:migration <name>, user:create,
                               # crypto:key:generate, crypto:rotate, queue:status, cron:run
php bin/cron.php               # the single cron entry (scheduler + bounded worker run)
```

Integration tests (`tests/Integration`) use a real MariaDB/MySQL database. They are **skipped** unless `TEST_DB_DATABASE` is set, either in `.env.testing` or in the environment. The database name must end in `_test`, because every table in it is dropped between tests. Don't use SQLite; queue claiming and locks need the real engine.

## Architecture

**Layers and dependency direction:** HTTP controllers, CLI commands, cron jobs and MCP tools call application services. Those call domain code, which works through repository and provider interfaces. Infrastructure adapters implement those interfaces. Controllers stay thin: they contain no SQL and no business workflows. Jobs and MCP tools must not bypass services.

- `app/Core/`: in-house kernel (ADR-016). It includes a PSR-11 container with **explicit factories and no autowiring**, and a PSR-15 middleware pipeline. It uses nyholm PSR-7 messages and FastRoute behind the `Router` interface. It also covers sessions, CSRF, crypto, queue, features, installer and logging.
- `app/Domain/<Name>/`: provider-independent entities, value objects, services and repository/provider interfaces. It has no HTTP, template, PDO or provider code.
- `app/Modules/<Name>/`: the delivery side. Each module contains a `*Module` class plus `Controllers/`, `Persistence/Pdo*Repository`, `Jobs/`, and provider adapters (for example `SearchConsole/Google/`). Modules don't reach into another module's persistence.
- `bootstrap/app.php`: the composition root, shared by `public/index.php`, `bin/console` and tests. It wires Core. Each module wires its own services in `registerServices()`.

**Adding module functionality:**
- Modules implement `Core\Modules\Module` (`registerServices`, `registerRoutes`, `migrationPaths`). If a module has background work, it also implements `ProvidesJobs` (`registerJobs`, `registerSchedule`).
- Enable a module by listing it in `config/modules.php`. Registration order matters: Workspaces must come before modules that need authorization.
- Routes use public UUIDs, e.g. `'{project:' . Uuid::ROUTE_PATTERN . '}'`, and each route gets a name. `routes/web.php` is only for cross-cutting routes.
- Views are plain PHP templates in `resources/views`. Escape output with `$this->e()`, add `$this->csrfField()` to POST forms, and apply the `$this->nonce()` CSP nonce to inline scripts.

**Tenancy and authorization (ADR-013):**
- Ownership chain: User → Workspace → Project. Tenant data is scoped by `workspace_id`, and project data by `project_id`.
- Never authorize on an entity ID alone. Repositories take workspace/project context.
- Every check goes through `Core\Authorization\Authorizer`. Each user has one role per workspace. Project roles can only narrow permissions, never widen them.
- System admin rights come from `users.system_role` and are checked through `system.*` permissions, not ad-hoc `isAdmin()` calls.
- A foreign or invisible project returns **404, not 403**.
- Sensitive actions write an `AuditLogger` event.
- IDs: tables use internal `BIGINT UNSIGNED` keys and a separate `public_id` UUID. Only `public_id` is ever exposed.

**Async work (QUEUE_AND_CRON.md):**
- Anything slow or that calls a provider becomes a job in the DB-backed queue. Workers claim jobs with `SKIP LOCKED`, and jobs are idempotent and bounded.
- Cron takes a lease lock (a `locks` table, ADR-015) to enqueue due scheduled slots.
- Failed jobs stay in `jobs` with `status='failed'` (ADR-022).
- Never make long provider calls inside a browser request. The only exception is the AI assistant's single Claude call per request (ADR-028).
- Paid DataForSEO calls go only through `Modules\DataForSEO\Api\DataForSeoClient::post()`. It reserves the estimated cost against the workspace budget in `UsageLedger`, then settles it with the cost DataForSEO reports. Imported data is persisted so dashboards never query Google live.

**Migrations (ADR-018):**
- Files go in `database/migrations/YYYY_MM_DD_NNNNNN_name.php` and return `new class implements Migration { up(Connection $db) }`. Create one with `make:migration`.
- Migrations are **forward-only, with no `down()`**. Never edit an applied migration: checksums are stored, and `migrate:status` flags changed files. Write a new corrective migration instead.
- Every table explicitly declares `ENGINE=InnoDB`, `utf8mb4` and `utf8mb4_unicode_ci`. Timestamps are `DATETIME` in UTC.

**Security essentials (SECURITY.md):**
- Every PHP file starts with `declare(strict_types=1);`. Use prepared statements only.
- Store provider and OAuth secrets and TOTP seeds with `Encrypter`/`EncryptedColumn`. This uses the versioned libsodium key ring from `storage/keys` or env, never from the database (ADR-014). The AAD binds each value to its row.
- Sessions are DB-backed, and only the token hash is stored (ADR-019). There are no `$_SESSION` or globals.
- Logs pass through `Redactor`. Outbound HTTP goes through `Core\Http\Client\HttpClient`, which has SSRF protections.
- Turning features off goes only through `FeatureManager` (`DISABLED_FEATURES`, ADR-052). There are no licence keys, editions or limits, and SEOcc never contacts a vendor server.

## Testing conventions

- HTTP, security and tenant-isolation tests run without a database. They use `tests/Support/TestApp` (a fully wired app with in-memory fakes from `tests/Support/Fakes`), `Browser` and `Scenario`. `Scenario` sets up owners, members and a rival workspace for cross-tenant tests.
- If you add a repository interface, add an in-memory fake and wire it into `TestApp`. Also add a PDO integration test for it.
- Use `Clock`/`FrozenClock` instead of `time()`.
- New protected routes need cross-workspace tests in `tests/TenantIsolation`.
- PHPUnit fails on risky tests, warnings and unexpected output.

## Workflow

- Use short-lived feature branches off `main` and concise imperative commit subjects ("Add X", "Fix Y"). Release tags look like `v0.2.0`.
- Don't add runtime Composer dependencies beyond the ADR-016 baseline without approval (see CODING_STANDARDS → Dependencies).
- Frontend: server-rendered HTML with vanilla ES modules. No SPA framework and no production build step.
