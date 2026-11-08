# Update System

## Public AI guide packaging

After changing numbered public guide chapters or version.json, run `php bin/sync-ai-guide.php` before the standard release build. The generated resources/guide/catalog.json must match the public source chapters/version; existing signed-resource packaging includes it. Sync the companion website with `php bin/build.php --production --sync-guide`. Never package internal developer documents as AI guide content. This adds no signing-key authorization or automatic publishing.

## Goals

- safe updates;
- signed releases;
- shared-hosting compatibility;
- migration automation;
- recoverable failure states.

## Release metadata

A release manifest should contain fields similar to:

```text
version
minimum_php
minimum_database_version if required
minimum_app_version if required
package identifier/location
SHA-256 hash
release timestamp
critical/security indicator
```

The manifest must be signed.

## Update workflow

2. Retrieve signed release manifest.
3. Verify signature.
4. Verify application/PHP/database compatibility.
5. Download package into `storage/updates/staging`.
6. Verify hash/signature.
7. Enable maintenance mode.
8. Create a consistent restore point/backup.
9. Apply staged files.
10. Run migrations.
11. Clear application caches.
12. Run health checks.
13. Disable maintenance mode.
14. Record update outcome.

## Safety

Never overwrite production code while a package is still downloading.

Do not execute unverified update code.

Keep enough state to detect and report interrupted updates.

Where automatic rollback is not reliable, fail safely and provide clear recovery instructions.

## Implementation (0.8)

Updates are installed from a manually uploaded signed package. Nothing is fetched or installed automatically, and SEOcc does not contact a vendor server (ADR-052).

The status file `storage/updates/available.json` contains only version, checked time and a safe error. The admin-only top-bar badge reads it locally; normal page loads make no vendor calls. Old notices disappear after the installed version catches up. The customer download portal separately compares eligible published releases with active installations' last reported versions and offers a cPanel guide. Portal notices appear on access; installed SCC notices appear after a cron check.

**Installation is never automatic:** the administrator makes/downloads a database and hosting-file backup, retains an offline encryption-key backup, checks the acknowledgment and clicks **Install**. The controller enforces that acknowledgment server-side for manual and automatically staged packages. The existing installer then verifies the package, enters maintenance, takes its own consistent database backup, applies files/migrations and records recovery state. Its database backup does not replace a complete hosting backup.

### Package format

The package is `scc-<version>.zip` and contains:

- `release.json`, the manifest:
  - `format`: 1
  - `version`
  - `minimum_php`
  - `created_at`
  - `files`: every file's path with its SHA-256
- `release.sig`, a base64 Ed25519 signature of `release.json`.
- The application files, under `app`, `bin`, `bootstrap`, `config`, `database`, `public`, `resources`, `routes` and `vendor` (production dependencies only), plus `version.json`, `composer.json`, `composer.lock` and `.htaccess`.

Packages never contain `.env`, `storage/`, tests or documentation.

`release:build` also writes the **install package** `scc-<version>-install.zip` for new installations. It has the same signed `release.json`, `release.sig` and files inside a `seo-control-center/` folder, plus the empty `storage/` skeleton: `storage/.htaccess` and a `.gitignore` placeholder in `backups`, `cache`, `keys`, `logs`, `sessions`, `tmp` and `updates`.

### Distribution

Official packages are attached to [GitHub Releases](https://github.com/seoccnet/seo-control-center/releases), signed with the project's release key.

If you maintain a fork, create your own key with `php bin/console release:keygen`, build with `release:build`, and set `UPDATE_PUBLIC_KEY` on your installations. Official packages then no longer verify there, which is intended.

### Verification

This runs on upload and again immediately before installing (`ReleasePackage::verify`). The package is rejected if:

- the signature doesn't match `UPDATE_PUBLIC_KEY` (or the key in `config/update.php`);
- the version is not newer than the installed one;
- the server's PHP is older than `minimum_php`;
- any path is unsafe (`..`, absolute, backslashes, outside the directories above, `.env`);
- the ZIP has a file that isn't in the manifest, or is missing one that is;
- a file's SHA-256 doesn't match;
- it has more than 30,000 files or more than 300 MB unpacked.

### Installation

*System → Updates* (`system.update`) runs `UpdateInstaller`. Each step is written to `storage/updates/state.json`:

1. **extract**: unpacks to a unique `storage/updates/staging-<version>-<random>` directory and checks the hashes again. One filesystem lock serializes installation, pending-package publication and discard across PHP workers.
2. **maintenance**: writes `storage/maintenance.json`. While it exists:
   - `public/index.php` answers 503 before loading application code. While the update lock is held, recovery routes are also blocked to avoid mixed code; after the active request stops, sign-in, `/system/updates`, `/system/health` and assets reopen;
   - `bin/cron.php` exits without running jobs.
3. **backup**: takes a consistent InnoDB database snapshot (*System → Backups*) with a unique filename. The original backup is protected from retention cleanup with a `.keep` marker until successful recovery. Retrying reuses that backup.
4. **copy**: replaces changed files atomically (write `*.scc-update`, then rename) and invalidates OPcache. Unchanged files are skipped.
5. **cleanup**: deletes files the release no longer has, in `app`, `bootstrap`, `config`, `database`, `resources`, `routes`, `vendor` and `bin`. `public/` and `storage/` are never pruned.
6. **migrate**: runs pending migrations.
7. **verify**: checks that `version.json` shows the new version. Then maintenance mode is switched off, the staging directory is removed and the result goes to `update_history` and the audit log (`system.update_installed`).

### Failure and recovery

- **Failure before copying:** application files and schema have not changed. Maintenance mode is switched off only if it was not already enabled before this attempt.
- **Failure after copying begins:** maintenance mode stays on, the step and error are shown on *System → Updates*, and administrators can sign in after the active request stops. The exact signed manifest hash, original version and original backup name are persisted atomically before copying. Retrying is allowed for that exact package even when `version.json` already advertises its version. Other packages and discarding the recovery package are refused. A failed retry during extraction never clears maintenance.
- **Migrations:** table creation, column/index additions and seeds are replay-safe. Completed migrations are skipped. Both a session-scoped database advisory lock and the legacy lease prevent concurrent runs; long DDL cannot outlive the advisory lock. Unexpected edits/removals of applied files block further migrations. `LegacyChecksums` accepts only exact old-to-repaired checksum pairs for the one-time resumability repair; new released migrations remain immutable and must be safe to retry after each statement.
- **If that doesn't help:**
  1. Restore the backup with `php bin/console backup:restore <file> --force`.
  2. Upload the files of the previous version.
  3. Only after both restores succeed, remove `storage/updates/state.json` and the pending package, release the original backup's `.keep` marker if present, then delete `storage/maintenance.json`.

### Building a release (vendor machine only)

```bash
php bin/console release:keygen            # once; writes ~/.scc-release/signing.key and prints the public key
php bin/console release:build --composer=/path/to/composer.phar [--key=<file>] [--out=build]
```

`release:build`:

- installs production dependencies in a temporary directory (`composer install --no-dev --optimize-autoloader`);
- collects the release files;
- signs the manifest;
- writes the update package and the install package;
- prints the package's path, size and SHA-256.

It warns if the working tree has uncommitted changes. Commit, bump `version.json`, then build.

The secret signing key must never be committed, uploaded or shared. Keep an offline backup of it: if it's lost, existing installations can only accept packages signed with a new key after `UPDATE_PUBLIC_KEY` has been changed on each of them by hand.
