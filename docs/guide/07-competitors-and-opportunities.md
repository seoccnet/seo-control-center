# 7. Competitors and opportunities

## Competitors

Add up to **10 competitors** per project under **Competitors → Add a competitor**, for example `competitor.com`. Their subdomains count as theirs.

**Suggested competitors** lists the domains that appear most often in the top 10 for your keywords. Add them with one click.

For each competitor you see, on your tracked keywords:

- **Visibility** compared with yours;
- **Top 3 / Top 10:** how many of your keywords they rank for there;
- **Ahead of you:** keywords where they rank better;
- **Gaps:** keywords where they are on page one and at least 10 places ahead of you, or you are not in the top 100.

Click a competitor to see the keyword-by-keyword comparison.

Competitor data comes from your rank checks at no extra cost, so history starts when you add a competitor.

## AI visibility

For many searches, Google shows an AI-written answer (an **AI Overview**) above the results and lists the sites it used as sources. **Project → AI visibility** shows, from your rank checks:

- how many of your tracked keywords show an AI Overview;
- in how many of them your site is cited, and at which place among the sources;
- which of your competitors are cited instead;
- the sites cited most often for your keywords.

The figures compare with the check about a week earlier. The data arrives with each rank check; rank tracking must be on. AI Overviews change often and differ by location and device, so watch the trend rather than a single day.

### AI assistants

The **AI assistants** section on the same page shows how ChatGPT and Google's AI answers (AI Overviews and AI Mode) talk about your site, from DataForSEO's database of AI answers:

- **Check mentions** compares how often your domain and each competitor are mentioned or cited, and the AI search volume (the estimated monthly number of questions) behind those mentions. It costs about $0.20 per domain.
- **Find questions** lists the most searched questions whose AI answers mention your domain, with an excerpt of the answer, your place among the sources ("#2") or "mentioned" when you are named but not linked, and the other sites cited. It costs about $0.25.

Both run in the background and are stored, so viewing them again is free. ChatGPT data covers the United States in English; Google's AI answers use your project's market.

Click **Check every week** to run the mention check automatically every 7 days. Each check is kept, and **Your mentions over time** shows how your mentions develop.

**Answers to your prompts** asks Gemini and Perplexity your own questions. Enter up to 10 prompts your customers might ask (one per line, up to 500 characters each) and your brand name, then click **Check answers**. Each prompt goes to both assistants with web search. The table shows, per answer, whether your brand is **Named**, whether your site is **Cited** as a source, which competitors are named or cited, and **Read answer** opens the full answer with its sources. Each prompt is answered once; AI answers change between runs, so treat the result as a sample of today's answers, not as how often you are mentioned. It costs at most $0.06 per answer (usually much less), and what DataForSEO reports is what is charged.

### AI crawler access

AI search tools can only show and cite pages their crawlers may fetch. **AI crawler access** reads your site's `robots.txt` and `llms.txt` (free: only your own site is fetched) and shows, per crawler, whether robots.txt lets it in:

- **Search and answer crawlers** fetch pages so answers can show and cite them: OAI-SearchBot and ChatGPT-User (ChatGPT), PerplexityBot and Perplexity-User, Claude-SearchBot and Claude-User, Googlebot (Google Search, including AI Overviews and AI Mode), Bingbot (Bing and Copilot) and Applebot.
- **Training crawlers** collect text for model training: GPTBot, ClaudeBot, Google-Extended, Applebot-Extended, CCBot and meta-externalagent. Blocking them is your choice and does not stop the search crawlers from citing you.

Each crawler is *Allowed*, *Blocked* (robots.txt disallows the home page) or *Some top pages blocked* (the home page is allowed but one of your 10 most-clicked Search Console pages is not). *Rule used* says whether robots.txt has a group naming the crawler or the crawler falls back to the `User-agent: *` rules. Groups match the crawler's name exactly, so a group for `Applebot` does not apply to `Applebot-Extended`.

The check runs weekly by itself; **Check again** runs it within a few minutes (members who can run analyses). When robots.txt cannot be read (a server error, a rate limit or a timeout), crawlers may stop fetching the site, so nothing is evaluated until it answers again. A firewall or CDN bot rule can still block crawlers that robots.txt allows; this check cannot see that.

`llms.txt` is a short Markdown guide to your most useful pages for AI tools. It is marked *Few published tests*: optional, and no published test has measured an effect on AI answers yet.

A blocked search crawler becomes an **AI visibility** opportunity with a plan to allow it.

### Citation readiness of your top pages

For your 10 most-clicked pages of the last 28 days, **Citation readiness** shows what each offers AI answers to quote, from the latest completed site audit, as *passed / known* checks and a list of what is missing:

| Check | How well tested |
|---|---|
| Names an author | Tested repeatedly |
| Structured data for the content (not only breadcrumbs) | Tested repeatedly |
| Links to sources from the text | Tested repeatedly |
| Facts with figures (percentages, prices, sizes, several numbers in a paragraph) | Tested repeatedly |
| Tables or lists | Tested repeatedly |
| Headings phrased as questions | Some tests, effect varies |
| States when it was updated (`dateModified` or `article:modified_time`) | Some tests, effect varies |
| Opens with a short answer (20 to 80 words) | Few published tests |

The author and source checks count only for articles: pages with Article structured data or `og:type` article, or in a blog, guide, news or similar folder. Product, category and home pages are not expected to name an author or cite sources, so they show the other six checks. Pages crawled before 0.43.0 show only the checks the audit recorded then, with how many checks follow after the next site audit ("Nothing missing so far · 5 checks after the next site audit"). Pages the audit did not crawl are listed as *Not in the latest site audit*.

### Visits from AI assistants

With a linked GA4 property, **Visits from AI assistants** shows the sessions, key events and purchase revenue that ChatGPT, Perplexity, Gemini, Copilot, Claude and other assistants sent to your site, in all channels. It compares the last 28 imported days with the 28 days before, per assistant. The data comes with each GA4 import: one extra read-only report, always covering both 28-day windows.

GA4 counts a visit for an assistant only when the assistant passes its address or a `utm_source`. Visits from assistant apps often arrive without one and count as direct traffic, so treat the numbers as a lower bound. A failed AI referral import is shown on the page and never fails the GA4 import.

### Brand-name mentions without a link

The separate **Brand-name mentions** section searches your public name in AI answer text, even without a website citation. Save the name and Google market, then request a paid check or explicitly opt into a separate weekly schedule. Review excerpts for namesakes and compare completed history. Name matches and domain counts overlap; never add them. See [Brand-name mentions](20-brand-name-mentions.md) for setup, costs and failure handling.

## Opportunities

Every 6 hours, SEO Control Center looks for concrete improvements in all the data stored for a project: Search Console and Bing, rank checks, competitors, the site audit, keyword research, backlinks, AI visibility and local SEO.

| Type | Example |
|---|---|
| **Quick win** | A keyword or search query on positions 4–20 with real demand: reaching the top 3 would bring clearly more clicks. Also a search for your own brand where you are not first (see below) |
| **Low click-through rate** | A query or page on page one gets less than half the clicks usual for its position: improve the title and description |
| **Ranking decline** | A tracked keyword that was in the top 20 lost at least 5 positions, or dropped out of the results |
| **Traffic decline** | A page lost at least 30 % of its clicks compared with the period before (from at least 20 clicks) |
| **Competitor gap** | A competitor is on page one and at least 10 places ahead of you for a tracked keyword |
| **Content gap** | Search engines show your site for a search with real demand, but only beyond page two: no page answers it well yet |
| **Technical issue** | The latest site audit found broken pages, missing titles or another error or warning on your site (one entry per issue type, with example pages). Issues on pages that get clicks in Search Console rank higher |
| **Keyword gap** | Competitors rank on page one for a search you do not rank for at all (from *Keywords → Keyword research → Keyword gap*); the more competitors, the more reliable |
| **Link building** | You have far fewer referring domains than your competitors; a strong site links to several competitors but not to you; a strong site stopped linking to you; or many of your links look spammy (from *Backlinks*) |
| **AI visibility** | Google's AI Overview for a tracked keyword cites other sites but not you, although you rank on page one; or ChatGPT or Google's AI mention a competitor far more often than you |
| **Local SEO** | You are not in the local pack (top 3 on Google Maps) for a local keyword; your Business Profile is not claimed, has fewer reviews or a lower rating than the local pack, or is incomplete |
| **Clicks falling, ranking stable** | A search query keeps its position and demand, but clearly fewer people click: the results page changed (for example a new AI Overview). Google data only |
| **Format mismatch** | Most top results for a tracked keyword are a different kind of page than yours, for example category pages while yours is a blog post |
| **Free tool idea** | People search for a calculator, checker, generator or similar tool with real demand, and you are not in the top 3 |
| **Merge near-duplicate pages** | Two pages of the latest site audit have nearly the same text and at least one is shown in search: keep the page with most clicks and redirect the other to it. Listed as *Cannibalization*. Pairs with a product page are never proposed, because different products often share a description. Pairs you mark *Keep separate* in the overlap review are left out too |
| **AI visibility (crawler access)** | robots.txt keeps an AI search or answer crawler (ChatGPT search, Perplexity, Claude, Google, Bing, Apple) out of your home page or of one of your top Search Console pages |
| **AI visibility (prompt gap)** | In your AI answer check, Gemini and Perplexity both recommend the same competitor for a prompt and mention you in neither |
| **Google indexing** | A saved URL Inspection reports an indexing concern for a page that gets impressions; Google indexes another URL than the canonical the page declares; or Google found errors in the page's structured data, so its rich results may not show |
| **Sitemap problems** | Search Console reports errors (or only warnings) for one of your sitemaps |
| **Bing crawl and index** | Bing has at least 10 % and 20 fewer of your pages in its index than four weeks ago, or at least 5 % of the pages Bing crawled returned errors (from at least 20 errors) |
| **Internal links: topic main page** | Pages Google shows for a topic in *Topics & owners* (at least 50 impressions) that do not link from their text to the topic's main page. Only main pages you set under *Page that should own this topic*, or article pages with at least half of the topic's searches, are used |
| **Refresh aging content** | An article (in a blog, news, guide or similar folder) still gets at least 300 impressions at an average position of 20 or better, but your sitemap says it has not been updated for over a year. Needs sitemap last-modified dates from the IndexNow sitemap check |
| **Traffic decline on another Google surface** | Clicks from Discover, Google News, the News tab, image or video search fell by at least 30 % compared with the period before (from at least 20 clicks) |

**Brand searches.** People who search your brand are looking for you, so anything ranking above you there takes clicks that should be yours. SEOcc reports a Google search for your brand, with at least 20 impressions, where your average position is below first.

Your brand words are the project name, unless it is generic, and the main part of your domain. A search counts when one to three of its words, written together, are a brand word, with or without a final "s". For `northwindgoods.test`, "northwind good" and "trail northwind good" both count. These searches are not repeated as ordinary quick wins. The action plan says what to check above you and what to make unmistakable on your home page.

Keyword research also adds **quick wins** for keywords you rank for at positions 4–20 but do not track yet. Where the keyword difficulty is known, it sets the effort of a quick win or keyword gap: up to 30 is low, up to 60 medium, above that high.
With a linked Bing site, Bing's queries and pages are added to Google's before the analysis. A query that is just below a threshold on Google alone can qualify with Bing's demand added. Its evidence then shows the period with *Google + Bing*. Traffic declines compare Google only, because Bing reports pages per week.

Each opportunity shows:

- a **priority**, based on impact, confidence and effort, with a **score breakdown**: demand (the estimated extra clicks), relevance (the BID check and intent), impact, confidence with its reasons, effort, risk (such as zero-click searches) and the data sources it relies on, plus the formula that combines them. Reports show the same figures in one line per opportunity, and AI assistants get the same breakdown over MCP;
- the **data behind it**, such as impressions, current CTR and expected CTR;
- an **estimate** of the extra clicks where possible.

Each action plan says **how well tested** its kind of change is:
- *Tested repeatedly*: several independent published tests measured an effect.
- *Some tests, effect varies*: results differ by site, template or intent.
- *Few published tests*: try it on a few pages with a comparison set first.
- *Search engine guidance*: technical fixes that follow search engine documentation.

Even a well-tested change is not a forecast for your site, so change a group of pages, keep a similar group unchanged, and compare. The label never changes a finding's priority.

**Start a change bundle** on a finding opens a new change bundle (*Manage → Change bundles*) with the finding's page, problem, evidence, expected effect and revert rule filled in, plus empty edit rows for the fields that kind of plan usually changes. Enter the new text, capture the baseline, approve, check the live page and record the deployment. SEOcc then drafts a measurement plan on the recorded change with the matching metric, the page and its query; add control pages there and freeze its baseline.

Each opportunity also has an **Action plan**: what to do about it, filled in with the finding's query and page. It says when the plan applies, what to prepare, what to change (numbered steps), what to keep unchanged so you can see the effect, what to measure and when to revert, plus what to avoid and other ideas to consider. Page action plans show each finding's next step, and team tasks show the full plan. The plan is guidance: it never changes a finding's priority or estimate.

### Business profile and the BID check

Under **Opportunities → Business profile**, describe the business: what kind of business it is, the main goal of search traffic, what it offers (one per line, up to 30), terms that never fit (for example *jobs* or *used*, up to 100), and two limits: the highest keyword difficulty you want to target (default 30) and the strength up to which a competing domain counts as beatable (default 20).

Every keyword finding then shows a **BID check**:

- **Business potential (B, 0–3):** how much a ranking helps your goal. Fixed rules decide, from your goal, the searcher's intent and whether the search mentions what you offer. Job searches and your excluded terms score 0. You can rate any keyword yourself on its finding; your rating wins over the rules. Business potential replaces the general intent weight in the finding's impact.
- **Format fit (I, 0–3):** whether your page is the kind of page that ranks (product, category, review, article, tool, …), from the stored top 10 of tracked keywords.
- **Rankability (D, 0–3):** one point each for difficulty within your limit, a weak result in the top 10 (a domain at or below your strength limit, or a forum thread) and an existing page-one ranking.
- **Verdict:** *Target*, *Consider* or *Reconsider*. Filter the list by verdict.

**Check competitor strength** on the business profile page rates the domains in your stored results pages in one DataForSEO request (the cost is shown first). Without it, rankability uses difficulty, forum results and your position.

### Zero-click risk

Each keyword finding shows a zero-click risk estimate: **high**, **medium** or **low**. It is higher when Google shows an AI Overview or an answer box, when the search asks for a definition or a quick fact, and when your click-through rate is far below what the position usually gets. It is lower when the search asks for comparisons, a purchase or a tool. A high risk lowers the confidence, not the estimate, and never hides the finding: you decide whether the clicks are worth it.

### Topics

Keyword findings name their **topic cluster**: keywords that share top results or subject words and that one page can usually target together. Question wording such as *how long does … last* or *types of* never links two different subjects.

Mark an opportunity **done** or **dismiss** it. If the underlying problem disappears, it moves to *resolved* by itself. **Analyze now** runs the analysis immediately. It is free, because it uses only data already on your server.

## Seeds and modifiers

*Keywords → Keyword research → Seeds and modifiers* builds a keyword list from short topics:

1. **Suggest seeds with AI** (needs the business profile and the AI assistant): the assistant proposes seed topics of one or two words and modifiers such as *best*, *how to*, *near me* or *calculator*. These are words to look up, not data. It is one model call, counted against the AI budget.
2. **Expand seeds** looks up the longer searches that contain each seed (up to 10 seeds, up to 100 searches each), with search volume, difficulty, intent and whether Google shows an AI Overview. Each seed is one DataForSEO request; the cost is shown.
3. Filter the result: **Contains one of your modifiers**, a single modifier, or **Tool searches only**. The Topic column groups searches one page can target.

Modifiers only filter the results; SEOcc never multiplies seeds and modifiers into paid queries.

## Relevance before and after paid research

The business profile also decides what is worth paying for:

- **Markets.** Under *Business profile → Markets you sell in*, list the countries and languages you sell in. A paid lookup in another market shows a notice and needs **Run anyway** ticked before anything is spent. With no markets set, nothing is checked.
- **Seeds that cannot fit are left out.** For *Expand seeds* and *Keyword ideas*, seeds with a term you exclude, and job or salary searches, are removed before the lookup is paid for. The message after queueing lists them with the reason. Tick **Run anyway** to include them.
- **Every result gets a verdict.** Keyword tables show **Relevant** (mentions something you offer), **Unsure** (names none of your offerings; wording alone cannot tell) or **Not relevant** (an excluded term or a job search), with the reason on hover. Not relevant keywords are hidden by default; the count below the table links to show them, and the **Relevance** filter switches between all keywords and only the hidden ones.
- **You decide.** Click *relevant?* or *not relevant?* next to a verdict to override it, or *reset* to go back to the rules. The override is the keyword's business-potential rating (not relevant = 0, relevant = 2), so opportunities use it too from the next analysis.

## Article ideas

*Research → Article ideas* suggests articles to write or update next, from the searches your competitors' articles rank for.

1. **Competitors.** SEOcc compares you with your tracked competitors, then with the sites it sees most often in your stored results pages (marked *found*). Big platforms such as Wikipedia, YouTube or Reddit are left out. At most 5 sites are used.
2. **Markets.** A lookup covers every market the project targets (a market is a country and language, such as *United States · English*). SEOcc takes them in this order:
   - the markets in the project's **business profile** (*Opportunities → Business profile*), at most 20;
   - without markets there, the markets of your **tracked keywords**, the market with the most keywords first;
   - without keywords, *United States · English*.

   The page says which of these was used, with a link to the business profile: add, remove or reorder markets there to change them. Markets that DataForSEO Labs has no competitor data for (such as *Germany · English*) are skipped and named on the page; they are never looked up or paid for.
3. **Find article ideas** buys up to 500 ranked keywords per competitor and market from DataForSEO. The cost grows with both: competitors × markets × the price of one lookup. With several markets the page shows that sum and the cost per market; tick the confirmation to pay. The run buys exactly the competitors and markets you confirmed: if they changed after the page was shown and the cost went up, the run is refused and you confirm the new cost. If the last lookup is under 30 days old, a second tick is needed: competitor rankings change slowly. The lookup runs in the background; ideas appear within a few minutes.
4. **Only articles count.** Competitor pages that look like blog posts, guides, how-tos or reviews are used, when they rank on page one or two (position 20 or better). Product, category and home pages are not. Searches under 10 a month, and searches your business profile or keyword ratings rule out, are skipped. Each search goes to the competitor article that ranks best for it, and one article makes one idea. Copies of one article in another language or country folder (/blog-… and /us/blog-…) count as one article, and articles that each rank in the top 10 for the other's main search are combined. Close variants that Google gives one shared search volume (*slicer vs paste*, *paste vs slicer plants*) are counted once.
5. **Per market.** Ideas are built for each market from that market's rankings, with your relevance rules applied to each. When the same search is an idea in several markets of the same language (*how to grow tomatoes* in the United States and Canada), it is listed once: its volume and traffic add the markets up, and a table on the idea shows the split per market (searches, clicks, difficulty and competitor articles). Difficulty and time to rank are those of its hardest market. The same words in another language stay a separate idea, and volumes of different languages are never added up.

Each idea says what to do:

- **New article:** none of your pages ranks within the top 30 for the topic.
- **Update your article:** one of your articles or guides ranks between 4 and 30. Improving it is usually faster. When only a shop page (a product or listing) ranks, the idea is a new article, which can link to that page.
- Topics where you already rank in the top 3 are left out.

Each idea also shows estimates, as ranges with how they were made:

- **Traffic:** clicks a month at positions 9 to 2, from the topic's search volume and your site's click-through by position.
- **Difficulty:** low, medium or hard, from keyword difficulty, the referring domains of the pages on page one and your own domain's links.
- **Backlinks to speed it up:** referring domains that would put the article in line with page one. Internal links and your domain carry part of it.
- **Time to rank:** a rough range of months by difficulty. It is an assumption until SEOcc has enough of your own ranking history.

With several markets, each idea names its market or markets, and a **Market** filter above the ideas shows one market at a time. With one market the page looks as before.

Open *Competitor articles and searches* to see the competitor pages, their positions and estimated traffic, the searches with volume and difficulty, and your page. **Hide** removes an idea; **Not relevant** also marks its main search as not relevant for future research. Ideas are rebuilt every day for free from the stored lookup, so they follow your rankings; hidden ideas stay hidden. **Rebuild ideas now** does the same right away, for free (up to 6 times an hour), for example after an update or a change to your business profile. Rebuilds use the stored rankings of every market of the last lookup and never buy anything; a market you add to the business profile is only looked up with the next paid lookup. The top 3 ideas also appear on the Opportunities page.

### Competitor outlines and briefs

**Read competitor outlines** on an idea reads its top 3 competitor articles, for free. It also reads your page when the idea is an update.

- SEOcc reads them like page comparisons do: HTTPS only, robots.txt respected. It keeps only their headings and word counts, never the text.
- The read runs in the background; refresh the page after a few minutes.
- An idea can be read once a week; a failed read can be repeated straight away.

The idea then shows its **subtopics**: headings that articles of at least two competitor sites share, most widely covered first. SEOcc reads one article per site first and uses only each article's own headings. Menus, footers, cookie and privacy notices are skipped, and so are headings a site repeats on all its articles. If only one site could be read, its headings are shown. For an update, each subtopic is marked ✓ when your page has it, or *missing on your page*. Questions the articles answer in their headings are listed too: those that name the idea's main search, or that two sites ask. *Competitor outlines* shows each article's headings, or why it couldn't be read.

**Create brief** opens a new content brief filled in from the idea:

- the target search and related searches;
- an outline with the main search as H1 and the subtopics as H2s;
- the questions to answer and, for an update, what is missing on your page;
- for an update, your page as the URL;
- evidence: the idea's market or markets, volume, estimates, the competitor articles and when their outlines were read.

Read the outlines first to get the subtopics into the brief. Review and edit everything before you save.

AI assistants can read the ideas, with their outlines and subtopics, using the `article_ideas` tool (*Keywords* permission).

### Did the prediction hold?

When an article from an idea is live, open the idea and choose **Mark as published**. Enter the article's address on your site and the date. A content brief whose target search is the idea's main search does the same once you save it as *Published* with its address.

**Published articles** at the top of *Article ideas* then compares the prediction with what happened, from your Search Console data, checked every morning:

- **Clicks/month now:** from the last four weeks, next to the predicted range.
- **Top 10 for the main search:** after how many months the main search first reached an average position of 10 or better (in a week with at least 10 impressions), or how long it has been so far.
- **Best position:** the best weekly position across the article's searches.

The prediction shown is the one made when you marked the article, even if the idea changes or disappears later (for example once you rank in the top 3).

Once **3 tracked articles** of the same kind (new or update) and difficulty reached the top 10, new *time to rank* estimates for that kind and difficulty use your site's own range instead of the assumption. The estimate's note then says so. **Stop tracking** removes an article from the list; the idea stays.

### Topic plans

**Plan a topic** on an article idea makes it the *pillar* of a topic plan, for free. Use it for an article that should cover a subject broadly, with supporting articles around it. Plans are listed under *Research → Topic plans*.

SEOcc proposes three kinds of members; tick the ones to keep and **Save plan**:

- **Supporting articles:** other article ideas on the pillar's subject, in a language of the pillar's markets. The subject is the pillar's rarest words. For "mold on pepper" it is *mold*, because *pepper* appears in most of your searches.
- **Searches to consider:** your Search Console queries on the subject that no idea covers. They start unticked; keep one to plan an article for it.
- **Your pages:** pages that already get impressions on the subject. They should link to the pillar.

The **link map** is built in the background:

- links between the pillar and each kept article (with their main searches as anchor text);
- links from your kept pages to the pillar;
- places on your crawled pages that already mention a planned article's search, with the paragraph and the words to link. This needs a finished site audit. Add those links once the article is published. **Rebuild link map** refreshes it after a new audit.

**Check backlinks** buys the backlinks of the pillar's top 2 competitor pages from DataForSEO (about $0.05; tick the confirmation). The plan shows their referring domains, backlinks and page rank, and about how many referring domains would put your pillar level with the strongest one. A second check within 30 days needs another tick.

**Create brief** works for the pillar and every supporting article. A brief made from a planned idea lists the plan's links under *Internal links to add*. AI assistants can read plans with the `topic_plans` tool (*Keywords* permission).

## Topics and page owners

*Keywords → Topics & owners* lists the topics the opportunity analysis found: searches from Search Console, tracked keywords and research that one page can target together. Searches your business profile rules out (excluded terms, job searches, keywords marked not relevant) are left out, and a topic is named after its top remaining search. By default the list shows topics with at least one search that names what you offer, or that your site already gets traffic or rankings for, or that you gave an owner. The others are counted in a note; choose **Show all topics** to see them. A tracked keyword is always a topic, even alone, and searches whose results pages show the same three or more sites in the top 10 are grouped together. Topics are saved after every analysis, so run **Analyze now** if the page is empty.

- **Owner.** The page that gets most of the topic's clicks in Search Console (or impressions, when there are under 10 clicks) owns it. Pages of one listing (`?page=2`, `/page/3/`) count as one page. Under 5 clicks and 50 impressions in total there is too little data, so no owner is named; the leading page is still listed. Without Search Console data, the page your rank checks find most often. **Contested** means a second page is close (at least 20 % and at least 60 % of the leader's share): decide which one should own the topic. **Unowned** means no page shows up yet; a new or improved page could take it.
- **Set the owner yourself** with *Page that should own this topic*. Your choice stays when the analysis runs again; SEOcc still tells you if Search Console sends the traffic elsewhere. *Use Search Console again* removes your choice.
- **Overlap.** Under each topic, searches that more than one of your pages appears for are labelled from 8 weeks of data:
  - *Switching pages*: the leading page changed at least twice. Google cannot decide; make one page clearly the best answer and link to it from the other.
  - *Split*: one page leads, but another takes a real share. Check whether they answer different questions before merging or redirecting.
  - *Two listings*: the same page leads and the other appears next to it on page one. Usually good.
  - *Brand search*: your name with the home page among the results. Normal.

## Why they outrank you

Choose **Why they outrank you** next to a topic in *Topics & owners* to find out what kind of page Google ranks for it, how the competing pages of that kind differ from yours, and what to change.

- **Cost.** When SEOcc stored the results page for the topic's main search in the last 14 days (from rank checks or opportunity research), the analysis is free. Otherwise it needs one DataForSEO results-page lookup; the page shows the estimated cost and you confirm it before anything is spent. Without DataForSEO and without a recent stored page, the analysis cannot run and the page says why.
- **Reading the pages.** The analysis runs in the background. Your server reads up to five competing pages and your own page (the topic owner, or your ranking URL) directly, over HTTPS only, and respects each site's robots.txt. Pages that block it, need a login or show a bot challenge are listed as *blocked* and left out; forums, videos and marketplaces count towards the format but are never read. Only page structure is stored (title, headings, word count, schema types, page elements, dates), never the competitors' text.
- **What Google wants.** Every result on the page is classified (article, product, category, tool, forum, video and so on) from its schema and page elements, or from its address when it was not read, with a confidence. The format is *dominant* when one type takes at least 60 % of the weighted results, *mixed* when two types each take at least 25 %, and *fragmented* otherwise.
- **Main bottleneck.** One of *technical* (your page could not be read), *format* (Google prefers another page type), *content gaps* (same-type competitors cover subtopics you do not), *freshness* (they were updated recently, your page was not) or *unclear*.
- **Actions.** Up to seven concrete changes, each with the competing pages it was seen on and an effort: create a page of the right type, pair your page with one of the other type, fix the technical problem, add or expand sections, add schema or media, refresh the page, or improve the title. When the format differs, SEOcc never tells you to rewrite your page into another type; it suggests a second page and linking the two.
- **Opportunities.** The actions of each topic's latest analysis (up to 30 days old) become opportunities at the next analysis, with the evidence and an estimated gain, so they are ranked and tracked with your other findings.

- **AI explanation.** With the AI assistant set up (*Integrations → AI assistant*), the analysis also asks your AI model once to explain the findings: a short verdict, a page plan (which page should target which searches), specific instructions and a suggested outline for each action, and things to avoid. The model only explains what the rules found; anything that names an unknown address or would turn a page into another type is dropped. The cost is shown before you analyze and comes from the AI budget. Without the assistant you get the same findings without the written explanation.
- **Internal links.** From your latest complete site audit, SEOcc recommends up to five links that make the topic's pages work together: supporting guides to the main page and back, the category page to the product, and strong related pages that do not link yet. Each comes with two or three anchors from the target's real Search Console searches and where to place it. Links only point to the page that owns the topic (or its main shop page); when no page owns the topic yet, the section says so and recommends none. Pages with very few internal links, and pages that compete for the same searches (merge or split them instead of linking them), are flagged. Without a site audit, the section says so. Each target page becomes one internal-links opportunity.
- **Client report.** **Client report (PDF)** downloads the analysis as a PDF with the report branding set under *Reports*, ready to send to a client.

Run **Analyze again** after you change the page. Only one analysis per topic runs at a time, and a finished analysis is reused for 14 days unless you choose to analyze again.

### Did the change work?

When you mark an opportunity done, SEOcc records the clicks of the 28 days before and measures again after 28 and 56 days: **Opportunities → Predicted vs observed**. Each row also says whether the work was really done:

- **Verified: page changed**: page monitoring saw the page change within 14 days of completion. The page is the one set on the team task, otherwise the finding's page. Watch the page beforehand (see [Page monitoring](25-page-monitoring.md)) to get this automatically.
- **Confirmed** or **Not done**: an editor chose **Confirm done** or **Not done** (on the day-28 row; it applies to both checkpoints). Results marked not done no longer count when SEOcc adjusts its estimates.
- **Not verified**: neither of the above.

**Overlaps** lists other changes in the same window: change notes for the whole site or the same page, other completed opportunities for the same search or page, and later changes detected on the page. The result may then not come from this change alone. Once a type has five verified or confirmed results without overlaps, SEOcc adjusts its estimates from those alone. Measured results remain correlation, not proof.

## Team work from opportunities

Choose Manage task on a finding to assign work, set a deadline and keep notes. The separate Team tasks view filters assignments and progress without replacing SEO finding statuses. Link writing briefs and recorded changes, then review their impact. See [SEO team tasks](24-seo-team-tasks.md).
