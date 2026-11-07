# 6. DataForSEO: keywords, rankings, research, local SEO and backlinks

Search volumes, rank tracking, keyword research, AI mentions, local SEO and backlinks come from **DataForSEO**, a data provider you pay per use. You use your own DataForSEO account: SEO Control Center adds no markup, and you only pay for what you use.

## Interrupted lookups

Domain overviews, backlink profiles, AI mention metrics and AI answer samples save each completed request, including responses with no matches. A safe temporary failure retries only the unfinished requests. Previous published results remain visible until the entire lookup completes.

If the budget is reached or queue retries run out, use the same lookup button with the same settings after resolving the problem. Completed requests are reused. Changing the domain, competitors or locale starts a fresh lookup; a completed lookup also starts fresh when requested again. Failed weekly/monthly batches can be resumed manually with the same analysis settings.

A timeout, interrupted worker, malformed paid result or uncertain charge stops resumption. Review DataForSEO usage before another paid lookup; SEOcc blocks an identical request while its outcome is unresolved. This prevents a silent second purchase. Progress cannot be recovered for failures that occurred before this feature saved checkpoints.

## Connect

1. Create an account at [dataforseo.com](https://dataforseo.com/), **verify it**, and top up credit.
2. In the DataForSEO dashboard, open **API Access** and copy the **API login** and **API password**. They are *not* your website password.
3. In SEO Control Center, go to **Integrations → DataForSEO**, paste both values and click **Connect**. The credentials are checked for free and stored encrypted.

If the connection is refused, the page shows DataForSEO's reason, for example an account that is not verified yet. Fix it at DataForSEO, then click **Test connection**.

## Costs

What SEO Control Center uses, at DataForSEO's current list prices. Your DataForSEO dashboard shows the exact prices.

| Action | Roughly |
|---|---|
| Keyword metrics (volume, CPC, competition) | about $0.075 per request of up to 1,000 keywords per country, reused for 30 days |
| Rank check | about $0.015–0.02 per keyword and check (top 100 results) |
| Local check | about $0.002 per local keyword plus $0.005 for the Business Profile |
| Keyword research lookup | $0.012 per lookup plus $0.00012 per returned row (1,000 ranking keywords ≈ $0.13) |

Examples:

- **100 keywords, checked weekly:** about $1.50–2 per week.
- **100 keywords, checked daily:** about $10–14 per week.

Competitor tracking costs **nothing extra**: it uses the results of your rank checks.

## Budgets

**Integrations → DataForSEO → Budget** sets:

- a **daily** limit;
- a **monthly** limit;
- a **maximum per request**.

Before every paid request, SEO Control Center reserves the estimated cost against these limits. A request that would exceed a limit is not sent. The page also shows what was spent today and this month.

## Rank tracking

1. Open the project → **Rankings → Tracking settings**.
2. Switch tracking on and choose the device (desktop or mobile) and how often to check (daily or weekly).

Checks run in the background. **Check now** starts one immediately; keywords already checked today are not checked again.

What the Rankings page shows:

- **Visibility:** your estimated share of possible clicks, weighted by search volume. 100 % means position 1 for every keyword.
- **Winners and losers** compared with the check about a week earlier.
- **History per keyword:** click a keyword to see its position chart, the ranking URL, the SERP features and the Search Console clicks for that exact query.

**Results-page changes** (*Research → Results-page changes*) shows what changed on the results pages of your tracked keywords between two checks on the same device, at most 14 days apart: features such as the AI Overview appearing or disappearing, tracked competitors entering or leaving the top 10 (and whether above you), new URLs of competitors or your own site, you entering, leaving or moving 5 places or more, and the kind of page Google ranks shifting. It counts the last 30 days, lists new URLs and keywords gained and lost per competitor, and can be filtered by change, keyword, domain or URL and dates. Everything comes from your stored checks; nothing extra is bought. A keyword needs two checks before changes appear; a missed check is a gap, not a change.

## Keyword research

**Keywords → Keyword research** looks up data from the DataForSEO keyword database. Each lookup runs in the background and its result is stored, so viewing it again costs nothing. A new lookup replaces the previous result.

| Section | What it shows | Roughly |
|---|---|---|
| Domain overview | organic keywords, top 3/top 10 and estimated monthly traffic of your domain and your competitors | $0.012 per domain |
| Your ranking keywords | up to 1,000 keywords your domain ranks for, including ones you do not track yet | $0.13 |
| Keyword gap | per competitor, up to 500 keywords they rank for and you do not | $0.07 |
| Keyword ideas | up to 300 related keywords for up to 20 seed keywords | $0.05 |
| Competitor discovery | the 20 domains that share the most keywords with you; add them as competitors with one click | $0.01 |

Click a column header to sort a table (click again to reverse the order). Keyword tables also have filters: keyword contains, volume from/to, difficulty up to and position from/to. Sorting and filters cover the whole stored result, not only the rows on the page.

Choose the country and language at the top before starting a lookup. Every country and territory Google Ads targets and every Google Search language is available. When you pick a country, the languages keyword research has data for there are listed first. A lookup in a combination it does not cover (Croatia in English, for example) is refused before anything is paid, and the message names the languages that work. Keyword volumes come from Google Ads, which filters by 48 languages; for any other language, such as Bosnian, the volume is the country's volume across languages. Tick keywords in any table and click **Track selected keywords** to add them to your tracked keywords. Already tracked keywords are marked with ✓.

Lookups need the role *Owner*, *Admin* or *Member*, a connected DataForSEO account and the workspace budget; viewers can read the stored results.

**Research on file** (*Research → Research on file*) lists every paid lookup the project already has: keyword research, gaps, competitors, AI visibility and backlinks, with the market, the seeds or prompts, the date, and whether it is still *current* (30 days). It also lists the topics analyzed with *Why they outrank you* and how many tracked searches have a stored results page. Check it before buying new data.

**Repeated lookups ask first.** If you start a lookup that already ran with the same settings and is still current, SEO Control Center does not buy it again. A notice shows when it ran; choose **Run again and pay** if you really need newer data. A lookup in another market, with other seeds or for another competitor runs as usual.

## Local SEO

**Project → Local** shows where your business appears in Google Maps when people search near a location.

1. **Settings:** enter the business name exactly as on Google Maps, the location your customers search from and the size of the area. To get the location, right-click the spot in Google Maps and click the coordinates at the top of the menu; they are copied, for example `43.8563, 18.4131`.
2. **Local keywords:** add up to 50 searches people make on Google Maps, such as "plumber" or "pizza near me". The place name is not needed, because the search is made at your location.
3. **Check now** (or tick *Check every week*): the check runs in the background and costs about $0.002 per keyword plus $0.005 for your Business Profile.

The page then shows:

- your **position in Google Maps** for each keyword (the top 3 are the places Google shows in the local pack of the normal search results), with the change since the last check;
- the **top 3 businesses** for each keyword with their rating and number of reviews, and the **local competitors** that appear most often;
- your **Business Profile**: category, rating, number of reviews, whether the profile is claimed, address, phone, website, photos and the rating history;
- **Profile and website:** whether your website states the same business name, phone number and address as the profile, whether the profile links to this website, and whether your pages have LocalBusiness structured data. The details come from the latest completed site audit, so run one after you change your site;
- **Review handling:** from the review texts you imported (see *Local branches and reviews*): the share of reviews with an owner reply, critical reviews still waiting for an answer, new reviews per month, and your review count against the local pack;
- **Categories in the local pack:** the primary categories of the other businesses in the top 3, with yours marked. If most of them use a category you do not have, check whether it describes your business.

Differences in these three areas also appear as *Local SEO* findings, each with an action plan.

If your business is not found, check that the name matches Google Maps exactly and that the location is close to it.

### Ranking grid

Google ranks local businesses by distance, so your position changes across a city. **Ranking grid** checks one keyword at a grid of points around your location (3 × 3, 5 × 5 or 7 × 7 points, 250 m to 5 km apart) and shows your position at each point as a coloured map: green for the top 3, amber for places 4–10, orange for 11–20 and red where you are not found. The outlined cell is your location. A grid costs about $0.002 per point; a 5 × 5 grid about $0.05.

## Backlinks

**Project → Backlinks** shows who links to your site and to your competitors, from DataForSEO's link index. Each section has its own button, so you only pay for what you look at:

- **Link profiles:** domain rank (0–100, higher is stronger), referring domains, backlinks, the share of nofollow domains and the spam score for your domain and each competitor. Tick **Check every month** to keep a history; the table then shows the change since the previous check.
- **Referring domains:** up to 500 domains linking to you. Click a column header to sort.
- **New and lost referring domains** of the last 30 days.
- **Link gap:** domains that link to your competitors but not to you, those linking to several competitors first. These are good candidates for outreach. It needs competitors (*Competitors*).
- **Anchor texts:** the link texts other sites use most when they link to you.

Each lookup costs about $0.024 plus a small amount per returned row (a full referring domain list about $0.04) from the workspace budget. The Backlinks API must be activated in your DataForSEO dashboard; if it is not, the lookup shows DataForSEO's error message.
