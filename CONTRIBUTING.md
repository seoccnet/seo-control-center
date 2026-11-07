# Contributing to SEO Control Center

Thanks for helping. Bug reports, documentation fixes, tests and code are all welcome. For a larger change, please open an issue or discussion first, so that your work isn't wasted.

## Set up

You need PHP 8.5 with `pdo_mysql`, `sodium`, `mbstring`, `intl`, `zip`, `curl` and `openssl`, plus Composer and MySQL 8+ or MariaDB 10.6+. The [README](README.md#from-source) has the install steps.

```bash
composer check
```

This runs the code style check, PHPStan and all tests, and must pass before merge. Faster options:

```bash
vendor/bin/phpunit --testsuite unit,http,security,tenant-isolation
```

```bash
composer cs:fix
```

Integration tests need a real database and are skipped unless `TEST_DB_DATABASE` is set in `.env.testing`. The database name must end in `_test`, because every table in it is dropped between tests.

## Before changing code

Read `ARCHITECTURE.md`, `SECURITY.md`, `CODING_STANDARDS.md` and `DECISIONS.md`. `CLAUDE.md` is a short summary of the conventions, for people and for coding agents.

## Pull requests

Every change should:

- preserve tenant isolation;
- include authorization where required;
- include validation;
- avoid new production dependencies without justification;
- include or update tests;
- update relevant documentation;
- update `CHANGELOG.md` for user-visible behavior;
- avoid unrelated refactors in the same change.

Use a short-lived branch and a concise imperative commit subject ("Add X", "Fix Y").

## Sign your commits

This project uses the [Developer Certificate of Origin](https://developercertificate.org/). It states that you wrote the change, or otherwise have the right to submit it. Add a sign-off to each commit:

```bash
git commit -s -m "Fix the CSV export"
```

Contributions are accepted under the project's license, AGPL-3.0. No separate contributor agreement is needed.

## Architecture changes

If a change reverses a documented decision, add a new entry to `DECISIONS.md` explaining:

- previous behavior;
- new decision;
- rationale;
- migration/compatibility implications.

## Security-sensitive changes

Changes involving authentication, authorization, cryptography, updates, MCP, URL fetching, uploads or external credentials get extra review before release. Report vulnerabilities privately, as described in [SECURITY.md](SECURITY.md), not in a public issue.
