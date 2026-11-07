# 8. AI access (MCP)

AI assistants such as Claude can read your SEO data through the **Model Context Protocol (MCP)**. You can then ask questions like "Which pages lost clicks this month?" or "Where is seedhouse.com ahead of us?".

Access is **read-only** unless you allow changes: you can let an assistant add change notes, update team tasks, write content briefs, set opportunity status and start or stop page watches. It can never delete anything or start paid lookups.

## Create a token

1. Go to **Integrations → AI access → New token**. Owners and admins can do this.
2. Enter a name, for example "Claude Desktop".
3. Choose the **data** it may read, including **Pages** for URL-level insights. Existing tokens need a new explicit grant for `pages:read`.
4. Optionally tick the **changes** it may make. Nothing is ticked by default.
5. Choose **all projects** or only some.
6. Choose an **expiry** (30, 90 or 365 days, or never).

The token (`scc_…`) is shown **once**. Copy it into your AI client right away. You can revoke tokens at any time on the same page.

## Connect your AI client

- **Server URL:** `https://seo.example.com/mcp`
- **Authentication:** header `Authorization: Bearer scc_…`
- **Transport:** Streamable HTTP

**Claude Code** (terminal):

```
claude mcp add --transport http seo-control-center https://seo.example.com/mcp --header "Authorization: Bearer scc_YOUR_TOKEN"
```

Other MCP clients: add a remote (HTTP) MCP server with the URL and header above.

## Connect ChatGPT

ChatGPT connects with a sign-in instead of a token. You need a paid ChatGPT plan (Plus, Pro, Business, Enterprise or Edu).

1. In ChatGPT, open **Settings → Apps & Connectors → Advanced settings** and turn on **Developer mode**.
2. Click **Create** (or *Add custom connector*). Enter a name such as "SEO Control Center" and the **server URL** shown under *Integrations → AI access*, for example `https://seo.example.com/mcp`, and choose **OAuth** as the authentication.
3. ChatGPT opens a sign-in page of SEO Control Center. Sign in if needed, choose the **workspace** and the **data** ChatGPT may read, and click **Allow**.

ChatGPT then appears in the token list as "ChatGPT (sign-in)". To disconnect, revoke that token. Only owners and admins can connect apps. Claude.ai custom connectors work the same way.

## What the assistant can use

The assistant can read the public guide through `guide_list`, `guide_search` and `guide_read`. These explain how SEOcc features work without exposing internal developer documents. Ask it to consult the guide when you need setup or workflow instructions.

It can also read a scoped project overview, stored GA4 landing-page outcomes and daily coverage, project alerts and report schedule/section status. Workspace spending totals require administrator permissions. Saved audits, backlinks, AI visibility, local branches, annotations, change impact, content briefs, indexing and team tasks remain available through their dedicated tools. Recipient addresses, credentials and private configuration are excluded.

For useful answers, specify the project, dates and page or keyword. Large lists return limited pages or clearly labelled samples; the assistant should inspect coverage and fetch another page only when needed. Google/Bing search dates can cover up to 486 days; GA4 ranges up to 365 days. Missing imports and incomplete or thresholded analytics must not be treated as zero. Local review text and grid history are requested explicitly to keep ordinary reads small.

| Tool | Answers | Data access |
|---|---|---|
| `projects_list`, `project_overview` | Which projects exist; a compact overview with search totals, rank check and counts | Projects |
| `seo_report` | The weekly or monthly report as data: search totals against the previous period, top pages and queries, rankings, GA4, audits and top opportunities | Projects (each section also needs its own access) |
| `project_monitoring` | Alerts, report schedule, IndexNow setup and submissions (`kind`: alerts, reports, indexnow, budget) | Projects |
| `annotations_list` | The SEO change timeline: recorded website changes by date | Projects |
| `guide_list`, `guide_search`, `guide_read` | This guide | Projects |
| `search_performance_summary` | Clicks, impressions, CTR and position with the change against the previous period | Search |
| `search_queries_list`, `search_pages_list` | Top queries and pages, Google and Bing | Search |
| `forecasts` | 7, 14 or 28-day forecasts of stored daily series, scored earlier forecasts and the event calendar | Search |
| `keywords_list`, `keyword_research` | Tracked keywords with metrics; saved keyword research (ranked, gap, ideas) | Keywords |
| `rankings_summary`, `keyword_history` | Positions, visibility, winners and losers, and one keyword's history | Rankings |
| `serp_changes` | What changed on tracked results pages: features, competitors, new URLs, your movement, page types | Rankings |
| `ai_visibility` | AI Overviews, ChatGPT/Google AI mentions, brand-name matches and the Gemini/Perplexity answer checks | Rankings |
| `local_rankings` | Google Maps positions, ranking grids and the Business Profile | Rankings |
| `competitors_list`, `competitor_compare`, `backlinks` | Competitor visibility, gaps, who is ahead, and backlink profiles | Competitors |
| `opportunities_list`, `seo_tasks_list` | Opportunities with evidence and action plans; assigned work and due dates | Opportunities |
| `site_audit`, `browser_audit` | The latest crawl with issues and affected pages; saved browser and Lighthouse diagnostics | Opportunities |
| `pages_list`, `page_details` | Known URLs and everything stored about one URL | Pages |
| `analytics_summary` | GA4 organic sessions, conversions and revenue | Pages |
| `web_vitals` | Core Web Vitals field data per checked URL, with weekly history | Pages |
| `page_changes` | Watched pages and detected title, description, H1, canonical, noindex and status changes | Pages |
| `seo_change_impact`, `indexing_status` | Before/after comparison around a recorded change; saved Google indexing evidence | Pages |
| `content_briefs_list`, `content_brief_details` | Saved writing plans and their original evidence | Pages |

The three search tools combine Google and Bing by default. Ask for one engine with `source`: *google* or *bing* (for example "Bing clicks of the last 28 days"). Combined figures use Google's average position, because Bing reports none per day. Page details read stored data without fetching another paid lookup.

Before drawing conclusions, the assistant can call `data_quality` (Projects): it lists which datasets are current, stale, sampled or missing, so a missing import is never read as zero.

Before starting new research, it can read `investigations_list` and `investigation_get` (Keywords) to reuse what was already found, and call `research_on_file` (Keywords): the lookups and analyses the project already has, with market, inputs, date and whether they are still current. Opportunities come with a `score_breakdown` (demand, relevance, impact, confidence, effort, risk, evidence and the priority formula), the same one the opportunity page shows.

## Changes an assistant may make

Only with the matching box ticked on the token (or on the sign-in page):

| Tool | Does | Permission |
|---|---|---|
| `annotation_create`, `annotation_update` | Adds or edits a note on the SEO change timeline | Change notes |
| `seo_task_save` | Creates or updates the team task of an opportunity | Team tasks |
| `content_brief_create`, `content_brief_update` | Creates a brief (prefilled from stored data) or edits one | Content briefs |
| `opportunity_set_status` | Done, dismissed, open again, or snoozed until a date | Opportunity status |
| `page_watch_set` | Starts or stops weekly checks for up to 50 URLs | Page watches |
| `keyword_set_relevance` | Marks up to 50 keywords relevant or not relevant for the business, or back to automatic | Keyword relevance |
| `cluster_set_owner` | Sets the page that should own a topic, or clears it | Topic owners |
| `investigation_save`, `investigation_add` | Starts or updates an investigation; adds dated sources, checks, limitations, notes or a finished analysis | Keep research notebooks |

Changes use the permissions of the user who created the token, so a viewer's token cannot change anything. Each change is logged and shows "via <token name>" on the note, brief or task. If someone changed the same record in the meantime, the assistant gets a conflict and has to read it again; it never overwrites. Ask the assistant for changes in plain words, for example "Record that we rewrote the shoe category titles today" or "Assign the duplicate-title task to Sam, due Friday".

Practical questions: "Give me last month's SEO report for Web Shop", "Which pages fail Core Web Vitals on mobile?", "What changed on our watched pages this week?", "What do Gemini and Perplexity answer for our prompts?", "Forecast clicks for the next 28 days", "Which opportunities have an action plan and who is working on them?".

## Safety

- Every call is **logged** in the audit log (tool, project, result), never with its content.
- A token acts **for the user who created it**. It stops working when that user loses access.
- Limit: 60 requests per minute per token.
- Treat a token like a password. If one leaks, revoke it and create a new one.
