# 30. Bot logs

*Improve → Bot logs* shows what search and AI crawlers actually request on your site, from your web server's access log: which crawlers come, how often they hit errors, redirects and parameter URLs, and which pages earn clicks but were not requested at all.

## Upload a log

Download the raw access log of the site from your hosting. In cPanel this is *Metrics → Raw Access*; other hosts offer it under logs or statistics. Upload it as it is, plain text or gzip (`.gz`), up to 200 MB or your server's upload limit, whichever is lower. A week or more gives a useful picture.

Uploading and deleting logs needs the *Edit projects* permission, and a project can upload 10 logs an hour. Everyone who can see the project can read the reports.

Background tasks read the log in chunks; the list shows how far they got. The Common and Combined Log Formats that Apache, nginx, LiteSpeed and cPanel write are read, with or without a virtual host at the start of each line.

## What is kept

Only requests of known crawlers are kept, and only as totals: per crawler, path and status code. Visitor requests, IP addresses and dates of single requests are never stored. The uploaded file is deleted as soon as it has been read, or when reading fails. The newest five logs of a project are kept; older ones are removed automatically. A log can hold up to 100,000 distinct crawler, path and status combinations; requests to further paths are counted but not listed per path.

Recognised crawlers: Googlebot (including the inspection tool and shopping crawlers), Bingbot, GPTBot, ChatGPT search and users, Claude, Perplexity, Applebot, Google-Extended and GoogleOther, YandexBot, DuckDuckBot, Baiduspider, and common SEO tools.

Crawlers are recognised by their user agent, which anyone can copy. A share of “Googlebot” requests may come from other tools; SEOcc does not verify the IP addresses.

## The report

The first table shows every crawler in the log with its requests, distinct paths, and the share of successes, redirects, client errors, server errors and parameter URLs. Choose a crawler to see its details:

- **Pages with clicks this crawler did not request**: of the 200 pages with the most Google clicks in the 28 days up to the log's end, those the crawler never asked for. A short log can miss pages that are crawled less often; check their internal links and sitemap entries.
- **Errors crawlers ran into**: the paths with the most 4xx and 5xx responses. Fix the links that lead there, or redirect them.
- **Parameter URLs crawled**: the most requested addresses with a query string, often filters, sorting and tracking parameters that use up crawling on duplicates. Consider canonical tags, robots.txt rules or fewer crawlable filter links.
- **Redirects crawled**: redirects crawlers keep following. Update internal links to the final address.
- **Most requested paths**: where the crawler spends most of its requests.
