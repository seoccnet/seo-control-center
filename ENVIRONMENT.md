# Environment and Configuration

## Principles

Configuration is separate from code.

Sensitive values must not be committed to source control.

## Supported runtime

| Component | Minimum | Notes |
|---|---|---|
| PHP (web and CLI) | 8.5 | See ADR-012. Cron must use a PHP 8.5 CLI binary. |
| MariaDB | 10.6 | See ADR-011. Tested on 10.6 LTS and current LTS. |
| MySQL | 8.0 | See ADR-011. Tested on 8.0 and 8.4 LTS. |
| Storage engine | InnoDB | Required for transactions and row locks. |
| Charset / collation | `utf8mb4` / `utf8mb4_unicode_ci` | Set per table in migrations; do not rely on server defaults. |
| SQL mode | strict (`STRICT_TRANS_TABLES`) | The connection sets its own SQL mode and `time_zone = '+00:00'` on connect. |

`APP_ENV` accepts `local`, `testing`, `staging` and `production`. `APP_DEBUG` only takes effect in `local` and `testing`. `staging` is for test servers: production-like behaviour.

Required PHP extensions: `pdo`, `pdo_mysql`, `curl`, `json`, `mbstring`, `openssl`, `sodium`, `filter`. Recommended: `intl`, `zip`, `zlib` (compresses PDF reports; without it they are written uncompressed).

## Expected configuration categories

```text
APP_ENV
APP_DEBUG
APP_URL
APP_KEYRING_PATH   (or APP_KEYS + APP_ACTIVE_KEY_ID)

DB_HOST
DB_PORT
DB_DATABASE
DB_USERNAME
DB_PASSWORD

SESSION settings
CACHE settings
QUEUE settings

VENDOR_UPDATE_API_URL
DISABLED_FEATURES        comma-separated Feature values to turn off, for example backlinks,mcp; default: none
SOURCE_CODE_URL          the sidebar source link (AGPL-3.0 section 13); set it when you run a changed version
UPDATE_PUBLIC_KEY        base64 Ed25519 key that release packages must be signed with (default: the vendor key in config/update.php)
UPDATE_MAX_UPLOAD_MB     default 64; the page also respects upload_max_filesize and post_max_size

CRON_INTERVAL_MINUTES    default 1; set to 5 on hosts that run cron at most every 5 minutes (common on shared hosting)
QUEUE_CRON_MAX_SECONDS   default 50 (1-minute cron) or up to 240 for longer intervals
QUEUE_WORKER_SLOTS       default 2
QUEUE_MEMORY_LIMIT_MB    default 0 = 80 % of memory_limit

SEARCH_CONSOLE_BACKFILL_DAYS        default 486 (about 16 months)
SEARCH_CONSOLE_MAX_ROWS_PER_DAY     default 5000 (per day, for queries and for pages)
SEARCH_CONSOLE_TOTALS_REFRESH_HOURS default 6

DATAFORSEO_SANDBOX                  default false; true sends all requests to sandbox.dataforseo.com (sample data, free)
DATAFORSEO_RAW_RESPONSE_DAYS        default 7 (raw responses kept for diagnostics)

BACKUP_KEEP                         default 7 (database backups kept in storage/backups)

SESSION_IDLE_MINUTES     default 120
SESSION_LIFETIME_HOURS   default 168
```

Provider credentials such as Google or DataForSEO should generally be stored as encrypted database connection records rather than global environment variables because they are customer-managed and workspace/project scoped.

## Production

Production should default to:

- debug disabled;
- stack traces hidden from users;
- secure cookies;
- HTTPS enforcement where possible;
- strict security headers;
- structured logs with secret redaction.

## Encryption keys

The application encrypts stored secrets with a versioned **key ring** (ADR-014). It is resolved in this order; the first source found wins:

1. **Environment variables** — `APP_KEYS` as a comma-separated list of `id:base64key` pairs, plus `APP_ACTIVE_KEY_ID`.
2. **`APP_KEYRING_PATH`** — absolute path to a key ring file, set in the application's `.env`/config file.
3. **Default location** — `storage/keys/keyring.php`.

Key ring file format (a PHP file, so that an accidental web request executes it instead of printing it):

```php
<?php
return [
    'active' => 2,
    'keys' => [
        1 => 'base64:…',   // retired, still used to decrypt old values
        2 => 'base64:…',   // active, used for all new encryption
    ],
];
```

Rules:

- Keys are 32 random bytes generated with `sodium_crypto_aead_xchacha20poly1305_ietf_keygen()` by the installer or `bin/console crypto:key:generate`.
- The key ring must be outside the public web root and not readable by other hosting accounts (file mode `0600` or `0640` where the host allows).
- Where hosting allows, place it **outside the application directory** (e.g. `~/scc-private/keyring.php`) and point `APP_KEYRING_PATH` at it.
- Application backups must **not** include the key ring. The installer tells the administrator to store a separate offline copy. Losing the key ring makes stored integration credentials unrecoverable (users would need to reconnect integrations; SEO data is unaffected).
- System Health warns if the key ring is missing, world-readable, or inside the public directory.

## Shared-hosting directory layout

The guided installer now keeps the dedicated subdomain's default document root. It automatically creates `seocc-app/` inside that root, routes HTTP only to `seocc-app/public/` through tested Apache/LiteSpeed protection rules, and preserves recognized cPanel PHP handlers and SSL challenge files. No client folder naming or document-root change is required. The outer hosting `.htaccess` stays outside signed application-update ownership; `.env`, storage and the key ring belong to the managed application folder. See DEPLOYMENT.md and the illustrated downloads guide. Website subdirectory URLs are not supported.

The following layouts remain valid for existing installations and advanced manual deployment:

Preferred (document root can point to `/public`):

```text
~/scc/            application root (not web accessible)
~/scc/public/     document root
~/scc-private/    key ring (optional, recommended)
```

Fallback (document root is fixed, e.g. `public_html`):

```text
~/scc/                 application code, storage, config
~/public_html/         contents of scc/public only; index.php points to ~/scc/bootstrap
```

Only when neither layout is possible may the whole application live inside the web root. In that case the shipped `.htaccess` files deny access to every directory except `public/`, and System Health shows a persistent warning.
