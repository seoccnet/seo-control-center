# 13. AI assistant and spending

## Ask AI

The **Ask AI** button at the bottom right of every page opens a chat with Claude or GPT, whichever your workspace chose. It reads your stored SEO data and answers in your language, for example:

- "What should I work on first this week?"
- "Why did my traffic drop in September?"
- "Which sites should I ask for a backlink?"
- "How visible am I in ChatGPT compared with my competitors?"

The assistant knows which project you are looking at. While it reads data, the chat shows what it looked at (for example *Read: SEO opportunities*). It is instructed to use stored evidence and explain missing data. Check important numbers against the underlying section, especially when a result is incomplete or sampled.

### Prompt library

When the chat is empty, it shows ready prompts in eight categories: Performance, CTR & snippets, Queries & intent, Pages & content, Countries & devices, Rankings & competitors, Technical & indexing, and Plan & report. During a conversation, **Prompts** in the chat header opens the same library. Selecting a prompt puts it in the message box, so you can change the dates, thresholds or URL first. Press Enter to send.

The prompts are written for the data SEOcc stores, for example:

- "Show me queries with at least 500 impressions and a CTR below 1 % in the last 28 days, sorted by impressions."
- "Show me queries containing "how to", "what is", "why", "which" or "can"…"
- "What was my CTR in the United States last week compared with the week before?"

Questions like these use filters that search every stored Search Console query and page, not only the top rows: text the query or URL contains, minimum impressions, CTR and position ranges, word count, new or lost queries, and the biggest gains or losses against the previous period. Country and device figures are totals for the whole site. Search Console data in SEOcc has no country breakdown per page or query, and the assistant says so when asked.

### Tables, charts and downloads

Answers can contain tables, lists and charts. Each answer has **Copy** (the text as Markdown) and **Download .md**. Tables have **Copy table**, which pastes into Sheets or Excel, and **Download CSV**. Charts have **Copy data** and **Download SVG**. Charts are drawn only from numbers the tools returned. **Export** in the header downloads the whole conversation as a Markdown file. The arrow button next to it makes the panel wider for tables and charts.

It can **mark opportunities** as done, dismissed or open again, but only after you click **Confirm** in the chat. If you write something else instead, nothing changes. It starts no paid lookups.

### Fixing issues with Ask AI

Every opportunity has an **Ask AI** button. It opens the chat and asks for the cause and the exact changes for that item. For technical issues, the assistant reads the affected pages from the latest site audit, with their current title, description, status and redirect target. It answers with concrete changes per page, for example "/trail-shoes: change the title from *Shoes* to *Trail Shoes – Acme Shop*".

When the changes cover several pages, the chat shows a **Download fix plan** link. The CSV lists each URL with the issue, the current value and the suggested change, ready to hand to whoever edits the website. The assistant cannot change your website itself.

When the changes are made, tell it "I fixed it, check again". It proposes a new **site audit**; click **Confirm** to start it. Audits are free and take a few minutes. The opportunities then close by themselves once the problems are gone.

Your conversation stays when you move to another page. **New chat** starts over. Each person's conversations are private.

### Waiting, retries and efficient questions

When the provider is busy or rate-limited, Ask AI shows a waiting period and enables **Try again** afterwards. It never retries automatically. Chat and optional digest summaries share the same workspace/provider cooldown. Repeated failure of the same step is limited to three attempts per hour.

If a browser request times out or loses its connection, use **Refresh conversation** first: the server may already have completed it. Refresh reads saved messages without another model call. An incomplete provider answer is labelled and stops; ask a narrower follow-up to continue. Concurrent requests for your conversation are rejected while one is running.

Use one focused question with a project, date range and URL or keyword. The model sees the last three question turns within a 90 KB limit; **New chat** helps when changing topics. Each question has at most eight model steps and each response at most six tool calls. Duplicate reads are suppressed and large results are explicitly bounded. These controls reduce unnecessary work; they cannot guarantee a fixed token cost or perfect answers.

Budgets are checked before a call. Known rejected calls release their reservation. If a provider connection fails after sending the request, SEOcc keeps the estimated charge conservatively because the provider may have processed it. An explicit retry may cost again. Local spending totals can differ from the provider invoice.

### Setting it up

1. Create a key:
   - **Claude:** in the [Claude Console](https://console.anthropic.com/settings/keys);
   - **GPT:** on the [OpenAI platform](https://platform.openai.com/api-keys).

   Add credit under *Billing* first.
2. Go to **Integrations → AI assistant**, choose a model and paste the matching key. The model decides the provider:
   - **Claude:** Sonnet 5.5 (balanced), Opus 5.5 (most capable) or Haiku 4.5 (fastest, cheapest);
   - **GPT:** GPT-6.1 Sol (balanced), GPT-6 Astra (most capable) or GPT-6 Luna (fastest, cheapest).
3. Set daily and monthly **budgets**. A question usually takes one to four calls; with Sonnet or Sol, a call costs about $0.01–0.05. When a budget is reached, the chat says so.

Owners and admins can set up the assistant; every member of the workspace can use it.

## DataForSEO spending

The top bar shows what DataForSEO cost this month and what to expect by the end of the month, for example **$8.29 → $40.47**. The small bar fills up towards the monthly budget and turns amber or red when the forecast gets close to a limit or passes it.

Click it for the details:

- today and this month;
- the forecast and last month;
- the budgets and what is left;
- your DataForSEO balance and how many days it lasts at the current pace;
- a 14-day chart;
- the spending per feature.

The forecast adds the average of the last 14 days for each day left in the month.

It is shown to members who can start paid lookups. Owners and admins change the budgets under **Integrations → DataForSEO**.
