# 4. First steps

## Start with the Quest Log: Chapter 1, Foundations

Open **Quest Log** in the navigation after signing in. Its first chapter, **Foundations**, walks you through setup in seven steps:

1. **Keep background tasks running:** the once-per-minute cron job (installation administrator).
2. **Add your website.**
3. **Connect Google Search Console** (optional).
4. **Connect DataForSEO and set a budget** (workspace owner or administrator).
5. **Choose your keywords.**
6. **Switch on rank tracking.**
7. **See your first results.**

Foundations reads your current settings live, so you can leave and return without losing progress, and a step can go back to **Needs attention** later, for example when Google is disconnected, cron stops or a budget is used up. Each step shows one of these statuses: **Ready**, **Needs setup**, **Needs attention**, **Waiting for first results** or **Optional**. Open **Tips and details** on a step for why it matters, what you will learn, pro tips, common mistakes and a link to the matching guide chapter.

Choose the website you want to set up at the top of the chapter. **Setup guide** in any project header opens Foundations for that website. The dashboard and the project overview show a small Quest Log card with your next step; **Hide this card** hides it for you in this workspace, and you can show it again from the Quest Log. Old **Get started** links and bookmarks open Foundations.

Steps your role can't perform, such as cron, the Google app or the DataForSEO connection for a member, are marked **Handled by your administrator**. They still show their live status, so you can see why imports aren't running, but they don't count against your own progress.

Google Search Console is optional if you want to start directly with keyword rankings. DataForSEO uses your own paid account: set a monthly spending limit before adding keywords, since keyword metrics can already trigger paid background work. Tracking starts only when you enable it on the Rankings page; the Quest Log never switches it on for you and never starts paid work.

“Waiting for first results” means setup has started but no results have arrived yet. Imports and ranking checks run through your hosting's cron job. “Needs attention” points you toward the connection, results page or system health check that needs help.

The progress card highlights your next required step. A zero or exhausted spending limit, a last account check reporting no credit, or sandbox mode needs attention before real paid tracking can run. Sandbox results are sample data. If your license doesn't include Search Console imports or rank tracking, those steps are simply left out; while the license is inactive they stay visible and explain that the work is paused until it is activated. Stored results stay available, and the results shortcut opens the source that actually has data. After changing Search Console properties, Foundations waits for data from the newly linked property.

When every step you can perform is ready, you earn the **Navigator** rank and can continue with the next chapters. See [the Quest Log](26-quest-log.md) for the whole campaign.

## Create a project

A **project** is one website.

1. Go to **Projects → New project**.
2. Enter a name and the domain (for example `example.com`).
3. Choose the time zone your reports should use.

The project opens on its **Overview**. With no saved results, **No imported results yet** links to the sources that fill the dashboard. Start with:

1. **Link Google Search Console:** clicks, impressions and positions ([chapter 5](05-google-search-console.md)).
2. **Add keywords:** the search terms you care about.
3. **Switch on rank tracking:** your Google positions for those keywords ([chapter 6](06-dataforseo.md)).
4. **Add competitors:** compare visibility and find gaps ([chapter 7](07-competitors-and-opportunities.md)).

## The project pages

| Tab | What you see |
|---|---|
| **Overview** | Search movement, recorded organic outcomes, evidence-backed priorities and risks, with observation dates and source coverage |
| **Performance** | Search Console clicks, impressions, CTR and position; charts; top queries, pages, devices and countries (7 days to 16 months) |
| **Keywords** | Your keywords with search volume, CPC and competition; suggestions from Search Console queries you are not tracking yet |
| **Rankings** | Visibility, average position, top 3/top 10, winners and losers, and the full keyword table with history per keyword |
| **Competitors** | Their visibility on your keywords, where they are ahead, and your gaps |
| **Opportunities** | Prioritised to-dos with the data behind each one |
| **Settings** | Name, domain, time zone, Search Console link, deleting the project |

## Exporting to CSV

**Keywords**, **Rankings**, **Opportunities** and the query and page tables on **Performance** have an **Export CSV** button. The file contains what you are looking at:

- **Rankings:** the selected position filter (for example only the top 3), with the latest position of every keyword, not just the current page.
- **Opportunities:** the selected status and type.
- **Performance:** the selected date range, up to 5,000 queries or pages.

The files open directly in Excel, Google Sheets and Numbers: UTF-8, comma separated. Anyone who can see a project can export its data.

## Reports

The **Reports** tab shows a project's report for a week (Monday to Sunday) or a calendar month, compared with the period before. It covers:

- Google + Bing clicks, impressions and CTR, and the average position;
- clicks per day;
- the top 10 pages and queries;
- rankings: visibility, top 3 and top 10, winners and losers;
- the open opportunities.

**Download PDF** saves the report as a PDF file with your report sections and branding. **Print** uses your browser's print dialog. **← Earlier** and **Later →** move between periods.

**By e-mail:** set a schedule, weekly or monthly, with up to 10 recipients; recipients need no account. Each report e-mail includes the PDF as an attachment.

- **Timing:** weekly reports go out on Wednesday at 07:00 and monthly reports on the 3rd at 07:00, in the project's time zone, once Search Console and Bing have the last days.
- **Format:** the e-mail shows the key figures as a formatted report, with a plain-text version for mail programs that prefer it, and links to the full report. The link needs a sign-in.
- **Testing:** *Send a test report to me* shows what recipients get.

E-mail must be set up under *System → E-mail*.

**Customize client reports:** in Reports, project editors choose the included search, rankings, GA4, audit and opportunity sections. The selection applies to printable reports and both email formats. Workspace managers can set a report brand name, accent and footer shared by all projects in that workspace. Blank fields inherit installation branding. Upload a PNG/JPEG/WebP logo (up to 512 KB, 16–2,000 pixels per side) for printable reports; emails use text branding without loading images. Separate client workspaces can therefore have separate report brands.

## Alerts and the weekly digest

SEO Control Center watches your projects and e-mails you when something needs attention. It checks every hour and sends each alert once:

- **Ranking drop:** keywords that fell out of the top 10 since the previous rank check;
- **Traffic drop:** clicks down 30% or more in the last 7 days compared with the 7 days before (from at least 100 clicks);
- **Site audit errors:** a new audit found more errors than the one before;
- **Lost backlinks:** strong referring domains (domain rank 30+) in the latest lost-links lookup;
- **DataForSEO budget:** the month will exceed the budget, or the balance lasts less than a week. Only owners and admins get this one.

The **weekly digest** arrives on Monday morning, one e-mail per workspace. For each project you can see, it shows clicks, top 10 keywords, site health, winners and losers, the top open opportunities, the week's alerts and a link to the weekly report.

Under **Your account → Notifications** you can turn alerts and the digest on or off, choose which alerts you get and mute projects. You can also add a short **AI summary** at the top of the digest. It is written by the workspace's AI assistant and counts against its budget. The page also lists the alerts of the last 30 days.

Alerts only use data that is already in SEO Control Center. They don't trigger paid lookups.

Project editors can tune alert thresholds in Notifications. Integration managers can optionally send future project alerts to Slack or a generic HTTPS JSON endpoint; see [Alerts and weekly digest](17-alerts-and-digest.md) for setup, security and delivery limits.

## Adding keywords

1. Go to **Keywords**.
2. Paste one keyword per line, up to 1,000 per project.
3. Choose the country and language, then click **Add keywords**.

Search volume, CPC and competition are fetched from DataForSEO in the background, if it is connected. Metrics are reused for 30 days, so they are not paid for twice.

**Tip:** the **Suggestions from Search Console** box lists queries that already bring you impressions. Tick them and add them with one click.

## Background work

Imports, rank checks and analyses run in the background with cron, usually within a few minutes. The page shows what is queued or running. If something seems stuck, look at **System → Jobs** ([chapter 11](11-troubleshooting.md)).
