# 1. Requirements

SEO Control Center runs on ordinary shared hosting with cPanel. You do **not** need root access, SSH, Docker, Node.js or Redis.

## Hosting

| | Required | Recommended |
|---|---|---|
| PHP | **8.5** (for the website and for cron) | |
| Database | MariaDB 10.6+ or MySQL 8.0+ | |
| PHP extensions | pdo_mysql, curl, mbstring, openssl, **sodium**, zlib, json, filter | intl, zip |
| PHP memory | 128 MB | 256 MB or more |
| PHP execution time | 30 s | 60 s or more |
| HTTPS | yes (AutoSSL / Let's Encrypt is fine) | |
| Cron | every 5 minutes or more often | every minute |
| Disk space | 500 MB free | more if you keep many backups |

Also required:

- your own domain or subdomain, for example `seo.example.com`;
- the option to point its **document root** to a folder of your choice (standard in cPanel).

The installer and *System health* check all of this and tell you exactly what is missing.

## Accounts you may want

- **Google account** with access to your sites in Google Search Console. This is free.
- **DataForSEO account** for search volumes, rank tracking and competitors. You pay DataForSEO per use; see [chapter 6](06-dataforseo.md). The rest of SEO Control Center works without it.
- An **authenticator app** (Google Authenticator, Authy, 1Password …) for two-factor sign-in. Optional, but recommended.
