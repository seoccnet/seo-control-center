# Project Structure

Recommended repository layout:

```text
/
├── app/
│   ├── Core/
│   │   ├── Audit/         # AuditLogger, AuditEvent (append-only audit_logs)
│   │   ├── Authorization/ # Permission catalog, Authorizer (ADR-013), MembershipProvider contract
│   │   ├── Cache/
│   │   ├── Config/
│   │   ├── Console/
│   │   ├── Container/     # in-house PSR-11, explicit factories (ADR-016)
│   │   ├── Crypto/        # KeyRing, Encrypter (ADR-014), SecretRotator
│   │   ├── Database/      # PDO connection, migrations, LeaseLock (ADR-015)
│   │   ├── Errors/        # process-level PHP error/fatal handling
│   │   ├── Events/
│   │   ├── Filesystem/
│   │   ├── Http/          # PSR-15 pipeline, Router interface + FastRoute adapter (ADR-016)
│   │   ├── Features/      # FeatureManager, Feature (DISABLED_FEATURES)
│   │   ├── Logging/
│   │   ├── Modules/
│   │   ├── Queue/         # Queue, Worker (SKIP LOCKED), Scheduler, CronExpression, CronRunner
│   │   ├── RateLimit/
│   │   ├── Security/      # CSRF middleware, password hashing, TOTP
│   │   ├── Session/       # Session, SessionStore (ADR-019), SessionMiddleware
│   │   ├── Support/
│   │   ├── Update/
│   │   ├── Validation/
│   │   └── View/          # plain-PHP template renderer with escaping helper, SVG LineChart geometry
│   ├── Domain/
│   │   ├── Accounts/
│   │   ├── Workspaces/
│   │   ├── Projects/
│   │   ├── Websites/
│   │   ├── Integrations/
│   │   ├── SearchConsole/
│   │   ├── Keywords/
│   │   ├── Rankings/
│   │   ├── Pages/
│   │   ├── Competitors/
│   │   ├── Opportunities/
│   │   ├── Reports/
│   │   └── MCP/
│   └── Modules/
│       ├── Accounts/      # login/logout, user repository, user:create command
│       ├── Workspaces/    # workspace context, membership provider, members page
│       ├── Shared/        # WebContext (typed access to request context)
│       ├── Dashboard/
│       ├── Projects/
│       ├── SearchConsole/
│       ├── DataForSEO/
│       ├── Keywords/
│       ├── Rankings/
│       ├── Competitors/
│       ├── Opportunities/
│       ├── Reports/
│       ├── AI/
│       ├── MCP/
│       └── Admin/
├── bootstrap/
│   └── app.php        # composition root: explicit container wiring
├── config/
├── database/
│   ├── migrations/
│   └── seeds/
├── public/
│   ├── index.php
│   └── assets/
├── resources/
│   ├── views/
│   ├── emails/
│   └── translations/
├── routes/
│   ├── web.php
│   ├── api.php
│   └── mcp.php
├── storage/
│   ├── cache/
│   ├── logs/
│   ├── sessions/
│   ├── tmp/
│   ├── backups/
│   ├── keys/          # default key ring location; never included in backups
│   └── updates/
├── bin/
│   ├── console
│   └── cron.php       # the single cron entry
├── tests/
│   ├── Unit/
│   ├── Integration/       # real MariaDB/MySQL
│   ├── Http/
│   ├── Security/          # CSRF, sessions, throttling, redaction, error disclosure
│   ├── TenantIsolation/   # cross-workspace access attempts (in-memory fakes)
│   └── Support/           # TestApp, Browser, Scenario, in-memory fakes
├── composer.json
├── phpunit.xml.dist
├── phpstan.neon.dist
├── .php-cs-fixer.dist.php
└── version.json
```

## Domain vs. Modules

- `app/Domain/<Name>/` holds provider-independent entities, value objects, domain services, repository interfaces, and events. It has no HTTP, template, or provider code.
- `app/Modules/<Name>/` holds the delivery side: controllers, views, routes, jobs, module registration, and infrastructure adapters (e.g. `Modules/DataForSEO` implements `KeywordMetricsProvider` from the domain).
- `Domain/Integrations` covers connections, encrypted credentials, and sync state shared by all providers.
- `Domain/MCP` covers MCP clients, tokens, scopes, and tool authorization rules. `Modules/MCP` contains the transport and tool adapters.
- `Domain/Pages` covers the site's own URLs and their metrics, shared by Search Console, Rankings, and Opportunities.

## Module contract

A module may register:

- routes;
- services;
- permissions;
- navigation;
- event listeners;
- jobs;
- migrations;
- health checks.

A module should not reach into another module's internal persistence implementation.

Cross-module work should use application services, public contracts, or events.
