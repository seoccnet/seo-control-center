# Git Workflow

## Branches

Recommended:

- `main` — stable/releasable branch;
- short-lived feature/fix branches.

Avoid long-lived divergent development branches unless needed.

## Commit style

Use concise imperative subjects.

Examples:

```text
Add workspace authorization middleware
Implement database queue reservation
Fix Search Console token refresh retry
```

## Checks before merge

`composer check` (CS, PHPStan, PHPUnit) must pass on the CI matrix in `TESTING.md`. Security-sensitive changes also need the review described in `CONTRIBUTING.md`.

## Release tags

Use semantic tags:

```text
v0.1.0
v0.2.0
v1.0.0
```

## Secrets

Never commit:

- `.env` production values;
- vendor private signing keys;
- OAuth client secrets intended to remain private;
- DataForSEO credentials;
- test fixtures containing real customer data;
- generated license entitlements containing real identifiers.
