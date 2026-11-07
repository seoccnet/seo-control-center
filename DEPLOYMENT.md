# Deployment and Hosting

## Target environment

- PHP 8.5+ (web and CLI; see ADR-012)
- MariaDB 10.6+ or MySQL 8.0+ with InnoDB and `utf8mb4` (see ADR-011)
- HTTPS
- outbound HTTPS access
- cron strongly recommended
- writable application storage directory

## Not required

- Docker
- Redis
- RabbitMQ
- Supervisor
- Node.js production runtime
- root access

## Recommended hosting capabilities

- at least 128 MB PHP memory, preferably 256 MB or more;
- 30+ second PHP execution limit, preferably 60+ seconds;
- PHP CLI access for cron where available;
- ability to configure document root to `/public` where possible (see `ENVIRONMENT.md` → *Shared-hosting directory layout*).

## Required PHP capabilities

Expected extensions include:

- PDO;
- PDO MySQL;
- cURL;
- JSON;
- mbstring;
- OpenSSL;
- Sodium;
- filter;
- Intl recommended;
- ZIP recommended.

## Installation wizard

### Installing on a subdomain

Install on a dedicated HTTPS subdomain at its root address, with cPanel's default document root unchanged. A website subdirectory is not supported.

1. Download `scc-<version>-install.zip` from GitHub Releases and verify its checksum if the release lists one.
2. Upload it to the subdomain's empty folder and extract it.
3. Move everything from the `seo-control-center` folder up into the subdomain's folder, including hidden files such as `.htaccess`.

The root `.htaccess` sends every request to `public/` and denies direct access to `app`, `bin`, `bootstrap`, `config`, `database`, `resources`, `routes`, `storage` and `vendor`. Check that it works: `https://seo.example.com/version.json` must answer 403. If it does not, stop and ask your host about `.htaccess` and `mod_rewrite`, because private files would be reachable. On hosts that allow it, you can instead point the document root at `public/`.

Hosting requirements: PHP 8.5+, cURL/ZIP/sodium/application extensions, Apache or compatible LiteSpeed with .htaccess and mod_rewrite, and a valid certificate.

### Workspace setup

While uninstalled, public/index.php serves the minimal installer kernel. The five-step browser wizard covers Welcome, Site & access, Database, Your account and Review & install. Back/Continue, required-field validation, password matching and a non-secret review progressively enhance a normal form. With JavaScript disabled all sections remain usable. Server-side validation remains authoritative. Passwords are never redisplayed or stored in browser storage.

A dedicated empty database is required. Setup rejects any existing tables before writing configuration, while a marked pending attempt can resume. The chosen cron interval (1, 5, 10, 15, 30 or 60 minutes; default 5) is validated and saved in CRON_INTERVAL_MINUTES. The installer writes .env, creates an encryption key ring, applies migrations, creates the administrator and workspace, locks setup and removes its server setup key.

Completion shows the actual quoted cron-script path, matching schedule and exact key-backup path. The cPanel PHP executable remains a host-dependent hint; the user's host may need to confirm it. No cron process is started automatically.

### cPanel without SSH

1. Create a dedicated subdomain. Disable shared document root if offered and keep the default folder.
2. Select PHP 8.5+, enable required extensions and obtain a valid HTTPS certificate.
3. Use Database Wizard to create an empty database and user with ALL PRIVILEGES.
4. Upload and extract the install package as described above, then open the subdomain over HTTPS.
5. Copy the setup key from `storage/setup-key.txt` in File Manager into the wizard.
6. Enter database and administrator details and choose the hosting cron interval.
7. Copy the generated cron command and matching schedule into Cron Jobs.
8. Back up the encryption key at the completion page's exact path, then sign in.

### Existing layouts and manual ZIPs

Existing public-folder installations keep their layout. Signed ZIPs keep their `seo-control-center/` wrapper folder.


Release packages use Composer's optimized class map **without** `--classmap-authoritative`, so classes added by a code-only update are still found through PSR-4. (An authoritative class map made the first 0.2 update fail with HTTP 500 until `vendor/composer` was replaced.)

Updating before the signed updater exists: upload the new release zip, extract it over the existing folder (never over `.env` or `storage/`), then open System health and press **Apply database updates**. That button requires the `system.update` permission and is audit-logged (`system.migrations_applied`).

Installation must be locked after completion.

The installer refuses to proceed on PHP < 8.5, MariaDB < 10.6, MySQL < 8.0, a missing `sodium` extension, or a non-InnoDB default engine that cannot be overridden.

## Storage

Only `/public` should be directly web accessible where hosting allows it.

Keep logs, caches, encrypted settings, backups, update packages, sessions, and temporary exports outside the public web root.

## Diagnostics

Provide a system-health page showing:

- application version;
- PHP version;
- database version;
- extension availability;
- HTTPS state;
- storage permissions;
- cron heartbeat and the CLI PHP version it reported;
- scheduler lock holder and expiry;
- encryption key ring location/permissions;
- queue state;
- update connectivity;
- integration health;
- MCP health.

Provide a sanitized support bundle generator.

## Backups and restore

- **Create:** *System → Backups → Create backup now*, or `php bin/console backup:create` (for example as a daily cron line on hosts that allow a second cron entry). The database is written as gzip-compressed SQL to `storage/backups/scc-backup-YYYYMMDD-HHMMSS.sql.gz`, and the newest `BACKUP_KEEP` files are kept. The data of sessions, rate-limit buckets and locks is left out; their structure is included.
- **The key ring is never in a backup.** Keep an offline copy of `storage/keys` (or of `APP_KEYS`). Without it, the encrypted settings in a restored database (Google tokens, DataForSEO password, MFA secrets) cannot be decrypted.
- **Download** the files regularly (*System → Backups*) or include `storage/backups` in the hosting backup, so a copy exists outside the server.
- **Restore** replaces the entire database and is only possible on the command line: `php bin/console backup:list`, then `php bin/console backup:restore <file> --force`, then `php bin/console migrate` if the backup is older than the installed version.

## Hosting compatibility

The installer and *System health → Hosting* check the limits shared hosts commonly set (`Core\Support\HostingCompatibility`):

| Check | Error (installation blocked) | Warning |
|---|---|---|
| Required PHP functions | `curl_init`, `curl_exec`, `gzopen`, `gzencode`, `hash_file`, `rename`, `unlink` or `mkdir` disabled | — |
| Optional PHP functions | — | `set_time_limit`, `ignore_user_abort`, `chmod` or `ini_set` disabled; `opcache_invalidate` or `opcache_reset` disabled while OPcache is on |
| `open_basedir` | excludes the application or storage directory | — |
| Updater write access | — | PHP cannot write the release directories (updates then need FTP) |
| Upload limit | — | below 16 MB |
| Free disk space | below 100 MB | below 500 MB |

Since PHP 8, calling a disabled function throws an error. The application calls such optional functions only through `Core\Support\Runtime`, which skips them when they are unavailable. Required extensions now include `zlib` (backups, stored provider responses). Temporary files are written to `storage/tmp`, never to the system temp directory, because `open_basedir` often excludes `/tmp`.

## Updates

From 0.8 on, updates are installed from a signed package under *System → Updates*: upload `scc-<version>.zip`, review it, then click Install. The updater:

- switches to maintenance mode;
- takes a consistent database backup and protects it until successful recovery;
- replaces the files;
- runs the database updates;
- switches maintenance mode off.

The details are in UPDATES.md. FTP uploads are no longer needed.

The update **to** 0.8.0 is the last one uploaded by FTP, because earlier versions don't have the updater. After that upload, open *System health → Apply database updates* (migration `000019`, update history).

PHP must be allowed to write in the application directory, because the updater replaces files there. On cPanel accounts this is the default, since PHP runs as the account user.

## Support bundle

*System health → Download support bundle* creates a ZIP for troubleshooting: health checks, PHP/database versions and extensions, pending migrations, queue state with recent failures, allowlisted settings and the last lines of the three newest application logs. E-mail addresses, bearer tokens and MCP tokens are removed from logs. It never contains `.env`, passwords, keys, tokens or SEO data.

0.9.81 signed packages (2026-10-08): installer build/scc-0.9.81-install.zip, SHA-256 b079f0b588efabe6a5542fb52c2b90f633f69843e63a4442a3fda7bb3ae4cb70; updater build/scc-0.9.81.zip, SHA-256 3da762826b0d59c4fc78cad70ca612486c4a150e8cde223c7a06c711be76519d. UI-only release based on signed 0.9.80; not published or deployed.

0.9.82 signed packages: installer build/scc-0.9.82-install.zip (SHA-256 8510f60edfb48ee0dcaeee9fdc6e810b7cd52caa1226349656c5942bc4f547b8); updater build/scc-0.9.82.zip (SHA-256 d4a97280bb7c2348008dbe99fd8637e617d6929e0fb0445bd3f8d95523edfdc9). Based on signed 0.9.81. Ready for deployment; not published or installed.
