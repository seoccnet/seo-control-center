# Implementation Instructions

This file is the authoritative implementation brief for the SEO Control Center project.

If another document conflicts with this file, follow this file unless the user explicitly changes the requirement.

## 1. Product

Build a self-hosted SEO intelligence platform for website owners and agencies.

The system must allow users to:

- create workspaces and projects;
- add and manage websites/domains;
- connect Google Search Console;
- connect their own DataForSEO account;
- import and normalize SEO data;
- track keywords and rankings;
- define and analyze competitors;
- discover competitor and keyword gaps;
- generate rule-based SEO opportunities;
- inspect trends and historical snapshots;
- expose authorized project data to external AI clients over MCP;
- run and change the application freely under AGPL-3.0;
- install updates from signed releases.

## 2. Non-negotiable technical constraints

- Target PHP 8.5+ (web and CLI; ADR-012).
- Target MariaDB 10.6+ and MySQL 8.0+, InnoDB, `utf8mb4` (ADR-011).
- Must run on ordinary shared hosting.
- Do not require Docker in production.
- Do not require Redis in production.
- Do not require RabbitMQ in production.
- Do not require Supervisor or another daemon manager.
- Do not require Node.js on the production server.
- Do not require a permanently running PHP process.
- Use a MariaDB-backed job queue and cron.
- The application must remain usable when Google, DataForSEO, the MCP client, or the update source is temporarily unavailable.

## 3. Architecture

Use a modular monolith.

The domain model and application services are the center of the system.

External providers must be behind interfaces/adapters.

Required dependency direction:

```text
HTTP / CLI / MCP / Cron
        ↓
Application services
        ↓
Domain services
        ↓
Repositories / provider interfaces
        ↓
Infrastructure adapters
```

Do not put business logic in controllers.
Do not query SQL directly from controllers.
Do not allow MCP tools direct database access.
Do not expose provider-specific models throughout the domain.

## 4. Required layers

### Core

- configuration;
- dependency container;
- HTTP kernel;
- router;
- middleware;
- authentication;
- authorization;
- validation;
- database abstraction;
- migrations;
- encryption;
- caching;
- queue;
- cron scheduler;
- events;
- rate limiting;
- audit logging;
- updater;
- system health.

### Domain

- Accounts;
- Workspaces;
- Projects;
- Websites;
- Integrations;
- Search Console;
- Keywords;
- Rankings;
- Pages;
- Competitors;
- Opportunities;
- Reports;
- MCP.

### Modules

Modules register routes, services, permissions, navigation, migrations, jobs, and optional event listeners.

## 5. Multi-tenancy

Use this hierarchy:

```text
User
  ↓
Workspace
  ↓
Project
```

All tenant-owned data must be scoped by `workspace_id`, and project-owned data by `project_id` where appropriate.

Never trust an entity ID alone for authorization.

Repositories must accept workspace/project context or enforce it internally.

Roles are assigned per workspace; project membership can restrict (never expand) access. See ADR-013 and `SECURITY.md` → *Authorization*.

Every cross-tenant access attempt must fail even when the caller knows another tenant's public UUID.

## 6. Public identifiers

Use numeric primary keys internally for efficient joins.

Use non-sequential public identifiers externally.

Recommended pattern:

- internal: `BIGINT UNSIGNED` primary key;
- external: UUID/ULID stored separately;
- APIs and browser routes expose the external identifier where feasible.

Do not expose sequential database IDs as the primary public identifier.

## 7. Request lifecycle

Use this conceptual flow:

```text
HTTP request
→ bootstrap
→ request ID
→ security headers
→ session
→ CSRF for browser mutations
→ authentication
→ rate limit
→ routing
→ authorization
→ validation
→ controller
→ application service
→ repository/domain/provider
→ response
```

Controllers should be thin.

## 8. Frontend

Use server-rendered HTML first.

Use:

- semantic HTML5;
- CSS with reusable design tokens and components;
- Vanilla JavaScript ES modules;
- Fetch API for partial interactions;
- a locally bundled chart library where needed.

Do not build the first release as a React/Vue SPA.

Avoid unnecessary frontend dependencies.

## 9. Queue and cron

Long or expensive work must run asynchronously.

Examples:

- Search Console synchronization;
- DataForSEO requests;
- ranking checks;
- competitor analysis;
- opportunity detection;
- exports;
- cleanup;
- aggregation;
- update checks;

Use a database-backed queue.

The preferred production cron command is:

```bash
php bin/cron.php
```

The system should require only one cron entry.

The worker must run for a bounded duration and then exit cleanly.

## 10. Provider boundary

Do not embed DataForSEO calls in controllers or domain classes.

Use provider interfaces such as:

- `KeywordMetricsProvider`;
- `SerpProvider`;
- `BacklinkProvider` later;
- `SearchPerformanceProvider` where useful.

Normalize provider data into the application's own model.

Raw provider JSON may be retained temporarily for diagnostics/reprocessing but must not be the canonical domain model.

## 11. Search Console

Use OAuth.

Request the smallest scope necessary.

Persist imported data in MariaDB so dashboards do not depend on live Google queries.

Use asynchronous imports.

Store OAuth secrets encrypted.

## 12. DataForSEO

Customers connect their own DataForSEO credentials.

Implement:

- encrypted credential storage;
- provider abstraction;
- async request handling;
- request cost accounting;
- daily/monthly budget controls;
- per-job limits;
- idempotency for expensive operations;
- retry policies;
- rate-limit handling;
- raw response retention with expiry.

## 13. Opportunities

The first opportunity engine must be rule-based, explainable, and testable.

Examples:

- keyword near top 10;
- keyword near top 3;
- ranking decline;
- traffic decline;
- CTR underperformance;
- competitor keyword gap;
- content gap;
- cannibalization;
- later: backlink and technical opportunities.

Each opportunity should retain evidence.

Do not make opaque AI-generated recommendations the source of truth.

AI may explain, summarize, prioritize, or assist with opportunities, but evidence must come from application data.

## 14. MCP

MCP is an adapter into authorized application services.

The first release is read-only.

MCP must not expose:

- credentials;
- refresh tokens;
- API secrets;
- password hashes;
- sessions;
- database credentials;
- unrestricted SQL.

MCP authorization must be scoped by workspace, project, and tool permissions.

All MCP calls must be auditable.

## 15. Security

Security is a release requirement, not a later enhancement.

Mandatory:

- `declare(strict_types=1);` in application PHP files;
- prepared statements;
- password hashing using PHP password APIs;
- secure session cookies;
- CSRF protection;
- RBAC/permission checks;
- output escaping;
- input validation;
- libsodium-based encryption for stored secrets;
- strict CSP;
- rate limiting;
- MFA support;
- audit log;
- secret redaction from logs;
- SSRF protection for URL fetching/crawling/webhooks;
- secure file upload handling;
- signed updates;

See `SECURITY.md`.

## 16. License

SEO Control Center is open source under AGPL-3.0 (ADR-052). There are no licence keys, editions, limits or vendor checks.

Features can be turned off only through `FeatureManager` (`DISABLED_FEATURES`). A feature that is off stops new work; existing data stays readable.

## 17. Updater

All vendor releases must be signed.

Update sequence:

1. retrieve signed release metadata;
2. verify signature;
3. check PHP/database/application compatibility;
4. download to staging;
5. verify package hash/signature;
6. create restore point where feasible;
7. enable maintenance mode;
8. apply files;
9. run migrations;
10. clear cache;
11. run health checks;
12. disable maintenance mode;
13. record the release.

Never stream a download directly over production files.

## 18. Reliability

External service failure must degrade features, not crash the application.

Examples:

- Google unavailable → existing imported data remains visible;
- DataForSEO unavailable → jobs retry and UI explains status;
- updater unavailable → current version remains usable;
- MCP fails → dashboard remains unaffected.

## 19. Performance

Shared hosting is the baseline.

Use:

- indexes deliberately;
- aggregate tables for dashboard metrics;
- bounded queries;
- pagination;
- caching where useful;
- async jobs for expensive work;
- retention for large raw datasets;
- historical snapshots rather than destructive overwrites.

Do not calculate large historical metrics from millions of raw rows on every page load.

## 20. Schema rules

- Prefer `DATETIME` stored in UTC for application timestamps; the connection sets `time_zone = '+00:00'`.
- Declare `ENGINE=InnoDB`, `utf8mb4`, and `utf8mb4_unicode_ci` explicitly in every table migration.
- Keep application-level timezone preferences separately.
- Use hashes for long URL/query lookup/index keys where appropriate.
- Use foreign keys when hosting compatibility allows and they do not cause problematic operational constraints.
- Every migration must be deterministic.
- Never require customers to manually run random SQL files during upgrades.

## 21. Versioning

Use semantic versioning.

Initial sequence:

- 0.1.0 Foundation
- 0.2.0 Search Console
- 0.3.0 DataForSEO
- 0.4.0 Rankings
- 0.5.0 Competitors
- 0.6.0 Opportunities
- 0.7.0 MCP
- 0.8.x hardening

Update `CHANGELOG.md` for every meaningful release.

## 22. What to implement first

Do not start by building SEO charts.

The first executable milestone is the foundation:

- composer project with PHPUnit, PHPStan, and PHP CS Fixer configured (ADR-017);
- bootstrap;
- configuration;
- dependency container (in-house PSR-11, explicit wiring; ADR-016);
- PDO connection;
- migration runner;
- router (FastRoute behind an application `Router` interface);
- middleware pipeline (PSR-15);
- safe error handling;
- structured logging;
- users/authentication;
- sessions;
- CSRF;
- workspace/project shell;
- permissions;
- audit log;
- secret encryption with versioned key ring (ADR-014);
- database queue with `SKIP LOCKED` claiming;
- cron runner with lease-based locks (ADR-015);
- feature manager;
- system health page.

Only after the foundation is stable should provider and SEO modules be added.

## 23. Definition of done for each feature

A feature is not complete until it includes, where applicable:

- authorization;
- validation;
- database migration;
- repository/application/domain separation;
- secure UI handling;
- audit event for sensitive actions;
- tests;
- documentation update;
- changelog entry when user-visible;
- queue/idempotency behavior if expensive;
- failure-state UI;
- workspace isolation verification.

## 24. Coding behavior for agents and contributors

This applies to every coding agent (Claude, Codex, or others) and to human contributors.

When implementing tasks:

1. inspect existing code first;
2. preserve architecture and naming conventions;
3. make the smallest coherent change;
4. do not introduce a framework or major dependency without explicit approval;
5. do not weaken security for convenience;
6. do not bypass domain/application services from controllers, MCP tools, or jobs;
7. add or update tests with implementation changes;
8. update documentation when architecture or behavior changes;
9. update `CHANGELOG.md` for user-visible behavior;
10. note any unresolved tradeoff in `DECISIONS.md` rather than silently inventing policy.

## 25. Prohibited shortcuts

Do not:

- put credentials in source control;
- store provider secrets plaintext;
- trust client-provided workspace/project IDs without authorization;
- execute arbitrary user SQL;
- expose SQL errors to users;
- run long provider requests inside normal browser requests;
- depend on hidden global state;
- use GET for destructive actions;
- allow wildcard MCP access by default;
- silently transmit SEO data to the project's maintainers;
- require a cloud service for the core dashboard to load.
