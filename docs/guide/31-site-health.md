# 31. Site health

*Visibility → Site health* watches the basics that can cost a site all of its search traffic overnight: the site being down, an expired TLS certificate or domain, a robots.txt that suddenly blocks everything, or a sitemap that loses its pages. The checks are free, need no setup and are on for every project.

## What is checked

| Check | How often | When you are told |
|---|---|---|
| **Uptime**: the home page of the project's domain over HTTPS | Every 15 minutes | Two checks in a row get no answer or a server error (5xx): *down*. The first check that answers again: *back up*. Redirects, 401 and 403 count as an answer. |
| **TLS certificate**: its expiry date and issuer | Daily | 14 days and 3 days before it expires, and when it cannot be verified (expired, self-signed or for another name). |
| **Domain registration**: expiry date and registrar | Weekly | 30 days and 7 days before it expires. |
| **robots.txt** | Daily | When its content or status code changes, with the lines added and removed. The title says so when it now blocks the whole site. |
| **Sitemap**: how many page URLs the sitemaps listed in robots.txt (or `/sitemap.xml`) contain | Daily | When it loses at least 10% of its pages (and at least 10 pages), or no longer lists any. |

Each event is recorded once and listed under **History**. People who want the matching [alert types](17-alerts-and-digest.md) also get an e-mail; the alert scan runs hourly, so an outage alert arrives within about an hour of the second failed check.

The first robots.txt and sitemap check only records a baseline; changes are reported from the second check on.

## Domain dates

Domain expiry comes from RDAP, the registries' public successor to WHOIS. SEOcc reads IANA's list of RDAP servers (cached for a week) and asks the registry of your domain's ending. Many country registries (for example `.de`) publish no RDAP service or no expiry date: then the page says so, and you should check the date at your registrar. SEOcc never guesses a date. Subdomains are checked through the domain they belong to (`shop.example.co.uk` → `example.co.uk`).

## Settings

Editors can **turn monitoring off** for a project (its last results stay visible) and on again, and use **Check now** to run all checks right away (three times an hour at most). Turning monitoring off or on is recorded in the audit log.

## Privacy and limits

- Requests go only to the project's own domain over HTTPS, to IANA's RDAP list and to the domain's registry. Only public addresses are contacted, as with the site audit. No data about your installation is sent.
- The uptime check reads the home page only. A broken page elsewhere on the site is found by the [site audit](12-site-audit.md).
- Checks run from your own server. If your server's network is down, checks fail too; two failures in a row are needed before *down* is reported, which filters out short hiccups but not a longer outage of your own host.
- Cron must run at least every 15 minutes for the uptime check to keep that pace.
