# 10. Backups and updates

## Backups

**System → Backups → Create backup now** saves the whole database as a compressed file on your server. The newest 7 are kept. A backup needed to recover an unfinished update is protected from automatic cleanup until that update succeeds.

- **Download backups regularly**, or make sure your host's backup includes the folder `seo-control-center/storage/backups`, so a copy exists outside the server.
- **The encryption key is never in a backup.** Keep the offline copy of `storage/keys` from the installation ([chapter 2, step 6](02-installation.md)). Without it, stored passwords and tokens in a restored database cannot be decrypted.
- Every update creates a backup first.
- *System health* warns when the last backup is older than 7 days.

**Restoring** replaces the whole database. It is done on the command line (`php bin/console backup:restore <file> --force`). If you have no shell access, ask your host.

## Updates

Updates are signed packages (`scc-<version>.zip`). Your installation only accepts packages signed with the project's release key, and only if every file is intact.

1. Download the **update package** from the [project's releases](https://github.com/seoccnet/seo-control-center/releases). (The install package, `scc-<version>-install.zip`, is only for new installations.)
2. Go to **System → Updates**, choose the file and click **Upload and verify**. The page shows the version and what the package contains.
3. Click **Install**.

The update:

1. switches the site to maintenance mode;
2. creates a consistent database backup;
3. replaces the changed files and updates the database;
4. switches maintenance mode off.

This usually takes less than a minute. During the active installation request, all pages temporarily show a maintenance notice so they cannot load partially replaced application files. After that request finishes or stops, administrators can sign in and reach *System → Updates* to recover a failed update.

If a step fails after file replacement begins, the page says which one and the site stays in maintenance mode. Retry the same signed package: completed database migrations are skipped and interrupted migrations can resume. This also works when the installed version already shows the target release. The original database backup is kept under *System → Backups* and is reused for recovery; a retry does not replace it with a backup of the partially updated database. Keep the pending package until recovery succeeds.

Schema updates are forward-only. If retrying still fails, restore both the original database backup and the matching application files with your host. Restoring the database alone does not roll back the application files. Keep your offline encryption-key backup available.

SEOcc doesn't look for updates by itself. To hear about new versions, watch the repository's releases on GitHub.
