<p align="center">
  <a href="https://seocc.net/"><img src="public/assets/img/logo.svg" alt="SEO Control Center logo" width="72" height="72"></a>
</p>

<h1 align="center">SEO Control Center (SEOcc)</h1>

<p align="center"><strong>Free, open source, self-hosted SEO software.</strong><br>
Search Console analytics, site audits, rank tracking, keyword research, backlinks, local SEO and AI visibility, on your own server.</p>

<p align="center">
  <a href="https://seocc.net/">Website</a> ·
  <a href="https://seocc.net/#explore">See it in action</a> ·
  <a href="#install-seocc">Install</a> ·
  <a href="https://seocc.net/#faq">FAQ</a> ·
  <a href="https://github.com/seoccnet/seo-control-center/discussions">Discussions</a>
</p>

<p align="center">
  <a href="LICENSE"><img alt="License: AGPL-3.0" src="https://img.shields.io/badge/license-AGPL--3.0-blue"></a>
  <img alt="PHP 8.5+" src="https://img.shields.io/badge/PHP-8.5%2B-777bb4">
  <img alt="MySQL 8.0+ or MariaDB 10.6+" src="https://img.shields.io/badge/database-MySQL%208%20%7C%20MariaDB%2010.6-4479a1">
  <a href="https://github.com/seoccnet/seo-control-center/actions/workflows/ci.yml"><img alt="Tests" src="https://github.com/seoccnet/seo-control-center/actions/workflows/ci.yml/badge.svg"></a>
  <img alt="Status: early alpha" src="https://img.shields.io/badge/status-early%20alpha-orange">
</p>

**SEO Control Center (SEOcc) is an open source SEO platform you host yourself.** It brings Google Search Console, Bing Webmaster Tools, Google Analytics 4 and Core Web Vitals into one workspace, adds site audits, rank tracking and research, and helps you decide what to fix next. It runs on ordinary PHP shared hosting, with no Docker, Redis, Node.js or background daemon.

Your search data stays in your own database. There are no seat limits, project limits or feature tiers, and SEOcc never reports back to a vendor.

<p align="center">
  <a href="https://seocc.net/#explore"><img src="https://seocc.net/images/performance-prognosis-demo.jpg" alt="SEO Control Center dashboard showing a search performance forecast for a fictional example.com project" width="860"></a><br>
  <sub>The actual SEOcc interface, with a fictional example.com project and simulated data.</sub>
</p>

> **Early alpha.** SEOcc is used on live websites, but expect rough edges and changes between versions. Back up before you update.

## Why people choose a self-hosted SEO tool

- **See the whole picture in one place.** Stop piecing together reports from separate tools. Each page shows its searches, rankings, audit issues and changes together.
- **Know what to do next.** SEOcc turns findings into prioritized opportunities, with the evidence behind each one and an action plan.
- **Find out whether it worked.** Record a change, freeze a baseline, and compare matching periods afterwards.
- **Own your data.** Everything lives in your MySQL or MariaDB database, so your history stays when you change tools or stop paying someone.
- **Pay only for what you use.** The software is free. Paid data is optional and billed directly by the provider, under budgets you set.
- **Run it almost anywhere.** If your hosting offers PHP 8.5, a database and one cron job, you can run SEOcc.

## SEO features

### Search analytics and rank tracking

| Feature | What it does |
|---|---|
| [Google Search Console and Bing analytics](https://seocc.net/#search-analytics) | Clicks, impressions, click-through rate and positions by query and page, stored beyond Google's 16-month window |
| Google Analytics 4 reporting | Connects organic visits to key events and purchase revenue |
| Keyword rank tracking | Daily or weekly desktop and mobile Google positions, with competitor visibility and search result history |
| SEO forecasts and data quality | Estimated trends with a clear range, and import coverage checks before you compare periods |
| [AI search visibility tracking](https://seocc.net/#visibility-tools) | Google AI Overview citations and sampled brand mentions in ChatGPT and Google AI answers |

### Technical SEO and site audits

| Feature | What it does |
|---|---|
| [Technical SEO site audit](https://seocc.net/#technical-seo-audit) | Crawls your site for broken links, redirects, duplicate titles, missing descriptions and more |
| [Google indexing and IndexNow](https://seocc.net/#google-indexing) | Google's saved indexing status and chosen canonical, plus IndexNow notifications for changed pages |
| Core Web Vitals and speed tests | Real-user data from Google CrUX, with lab tests to investigate slow pages |
| Page monitoring | Alerts when titles, descriptions, headings, canonicals or indexing directives change |
| Internal links and keyword cannibalization | Suggests useful internal links and finds pages competing for the same search |

### Keyword research and content

| Feature | What it does |
|---|---|
| [Keyword research and competitor analysis](https://seocc.net/#keyword-research) | Search volume, difficulty, keyword gaps and an analysis of why competitors outrank you |
| [Content briefs and team tasks](https://seocc.net/#content-briefs) | Turns a keyword, page or opportunity into a brief, then assigns and tracks the work |
| Content inventory, article ideas and topic plans | Reviews what you have before you write more, then plans what to create or update |
| Backlink analysis | Referring domains, link samples, competitor gaps and optional lost-link monitoring |
| [Local SEO, Google Maps and reviews](https://seocc.net/#local-seo) | Local rankings and grids per branch, Business Profile history and review samples |

### AI, reports and teamwork

| Feature | What it does |
|---|---|
| [Ask AI with Claude or OpenAI](https://seocc.net/#ai-assistant-mcp) | Ask questions about your stored SEO data using your own API key |
| [MCP server for AI assistants](https://seocc.net/#ai-seo) | Connects Claude, ChatGPT and other MCP clients to the projects you allow. Read-only by default, with scoped tokens and an audit log |
| [Opportunities and change impact](https://seocc.net/#seo-change-impact) | Prioritized findings, a change timeline and before-and-after results |
| [Reports, alerts and budgets](https://seocc.net/#seo-workflows) | Scheduled reports with PDF, alerts, a weekly digest and spending limits for paid APIs |
| [Workspaces, roles and white label](https://seocc.net/#self-hosted-seo) | Separates clients into workspaces, with roles, two-factor sign-in and your own branding |
| WordPress plugin | A companion plugin for IndexNow notifications, in [`integrations/wordpress`](integrations/wordpress) |

Not sure where to begin? The [SEO use cases](https://seocc.net/#use-cases) start from common problems, such as a traffic drop or pages that get seen but not clicked.

## An open source alternative to Ahrefs, Semrush and Moz?

Partly, and it depends on what you need. Those are large hosted suites with their own web-wide data. SEOcc takes a different approach: it starts with the first-party data you already have for free, and buys third-party data only when you ask for it.

| | SEOcc | Typical hosted SEO suite |
|---|---|---|
| **Software cost** | Free (AGPL-3.0) | Monthly subscription |
| **Where your data lives** | Your own database | The vendor's servers |
| **Projects and users** | No limits in the software | Usually tied to the plan |
| **First-party data** (Search Console, Bing, GA4) | Core of the product | Varies by product |
| **Keyword and backlink data** | Optional, pay per use through your own DataForSEO account | Included, from the vendor's own database |
| **Own web-wide link index** | No | Often yes |
| **Source code** | Open, change anything | Closed |
| **Maintenance** | You run it, including updates and backups | The vendor runs it |

If you mainly need your own site's analytics, audits, rank tracking and a clear to-do list, SEOcc may be all you need. If you depend daily on a very large proprietary link index, keep that tool and use SEOcc alongside it.

*SEOcc is not affiliated with Ahrefs, Semrush or Moz. Their names are trademarks of their respective owners.*

## What does it cost to run?

| Connection | Cost |
|---|---|
| SEO Control Center | Free |
| Google Search Console, Bing Webmaster Tools, Google Analytics 4, Google CrUX | Free |
| Site audits, change timeline, opportunities, tasks and reports | Free, they run on your server |
| DataForSEO (keyword data, rank checks, backlinks, local and AI checks) | Optional, paid to DataForSEO |
| Anthropic Claude or OpenAI API for Ask AI | Optional, paid to the provider |
| Your web hosting | Whatever your host charges |

Every paid lookup is reserved against a workspace budget first, so a spending limit you set stops new work.

## Install SEOcc

### Requirements

- PHP 8.5 or newer, with `pdo_mysql`, `sodium`, `mbstring`, `curl`, `zip`, `openssl` and `intl`
- MySQL 8.0+ or MariaDB 10.6+, with an empty database
- Apache or LiteSpeed with `.htaccess` and `mod_rewrite`, on an HTTPS subdomain such as `seo.example.com`
- One cron job (every minute is best; every 5 minutes works)

### On shared hosting (cPanel, no SSH needed)

1. Download `scc-<version>-install.zip` from the [latest release](https://github.com/seoccnet/seo-control-center/releases/latest).
2. Create a subdomain, select PHP 8.5 and turn on HTTPS.
3. Upload the zip to the subdomain's empty folder and extract it, then move everything from the `seo-control-center` folder up into the subdomain's folder. Include hidden files such as `.htaccess`.
4. Open your subdomain and follow the installation wizard. It asks for a setup key, which you can read from `storage/setup-key.txt` in File Manager.
5. Add the cron job shown on the last screen and download your encryption key.

The [full installation guide](docs/guide/02-installation.md) covers each step.

### From source

```bash
git clone https://github.com/seoccnet/seo-control-center.git
```

```bash
cd seo-control-center && composer install && cp .env.example .env
```

Set your database in `.env`, then:

```bash
php bin/console crypto:key:generate
```

```bash
php bin/console migrate
```

```bash
php bin/console user:create you@example.com "Your Name" --system-admin --workspace="My Agency"
```

```bash
php -S localhost:8080 -t public public/index.php
```

Run background work with `php bin/cron.php` from cron, once a minute.

## Documentation

- [User guide](docs/guide/README.md): setup, integrations, every feature and troubleshooting
- [Deployment](DEPLOYMENT.md), [environment settings](ENVIRONMENT.md) and [updates](UPDATES.md)
- [Architecture](ARCHITECTURE.md), [database](DATABASE.md), [queue and cron](QUEUE_AND_CRON.md) and [integrations](INTEGRATIONS.md)
- [MCP server and tools](MCP.md)
- [Security](SECURITY.md) and [design decisions](DECISIONS.md)

## Built to be easy to run and to change

SEOcc is a PHP 8.5 modular monolith with a small in-house kernel and only a handful of runtime dependencies. Pages are server-rendered with vanilla JavaScript, and there is no build step. Anything slow runs through a database-backed job queue driven by a single cron entry.

- **Security:** secrets are encrypted with libsodium, data is separated by workspace, and outbound requests have SSRF protection. Update packages are signed with Ed25519.
- **Quality:** PHPStan at level 8, a strict coding standard, and more than 1,400 automated tests, including cross-workspace isolation tests.
- **Privacy:** no telemetry. SEOcc only contacts the services you connect.

## FAQ

### Is SEO Control Center really free?

Yes. The full software is free under AGPL-3.0, with no paid edition, licence key or feature limits. You pay only for your hosting and any optional paid APIs you connect.

### Can I use SEOcc for my agency and my clients?

Yes. You can use it commercially, manage any number of client projects and apply your own branding. If you change the code and let others use your version over a network, AGPL-3.0 requires you to offer them your changed source code.

### Do I need Docker, a VPS or command-line access?

No. SEOcc was built for shared hosting and installs through a browser wizard. You do need PHP 8.5, which not every host offers yet, and the ability to add a cron job.

### Does SEOcc send my data anywhere?

Only to the services you connect, such as Google, DataForSEO or your chosen AI provider. There is no telemetry and no connection to a vendor server.

### Does it work with Claude and ChatGPT?

Yes. Ask AI works inside SEOcc with your own Anthropic or OpenAI key. The built-in MCP server also lets compatible assistants read the project data you allow, and suggestions are never published to your site automatically.

### Is it a replacement for Google Search Console?

No, it builds on it. SEOcc imports your Search Console data and keeps it in your database, then combines it with audits, rankings and your change history.

### Does it work with WordPress?

Yes. SEOcc works with any website it can crawl. There is also a [WordPress plugin](integrations/wordpress) for IndexNow notifications.

More answers are in the [SEOcc FAQ](https://seocc.net/#faq).

## Community and contributing

Bug reports, documentation fixes, translations of ideas into pull requests and feedback from real SEO work are all welcome.

- Read [CONTRIBUTING.md](CONTRIBUTING.md) and the [code of conduct](CODE_OF_CONDUCT.md).
- Ask questions in [GitHub Discussions](https://github.com/seoccnet/seo-control-center/discussions).
- Report bugs in [GitHub Issues](https://github.com/seoccnet/seo-control-center/issues).
- Report security problems privately, as described in [SECURITY.md](SECURITY.md).

If SEOcc is useful to you, a star helps other people find it.

## License

SEO Control Center is licensed under the [GNU Affero General Public License v3.0](LICENSE). See [NOTICE](NOTICE) and [THIRD-PARTY-NOTICES.md](THIRD-PARTY-NOTICES.md).

"SEO Control Center", "SEOcc" and the logo are trademarks of Trio Group LLC. The code license does not cover them, so please read the [trademark policy](TRADEMARKS.md) before you publish a fork or a hosted service.

<p align="center"><a href="https://seocc.net/"><strong>seocc.net</strong></a>: free, open source SEO software you host yourself.</p>
