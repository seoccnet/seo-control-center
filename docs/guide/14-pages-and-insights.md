# Pages and insights

Page details also shows [saved Google indexing evidence](23-google-indexing.md): reported coverage, last crawl and canonical, with its check time and any latest failure. **Inspect or watch this URL** opens Google indexing. Viewing a page makes no inspection request.

Page details also show recorded changes for the exact URL and site-wide notes within the displayed period. Use [SEO change annotations](19-seo-change-annotations.md) to record edits and review their context without assuming they caused subsequent results.

Open **Project → Pages** to list the URLs SEOcc knows from connected search data, rankings, the latest completed crawl, stored backlinks and GA4. This is a catalog of stored URLs; it is not a guarantee that every URL on your website has been discovered.

## Content inventory

**Research → Content inventory** lists every page, draft and planned article SEOcc knows about. There is one row per address, with what each source says about it, and each source keeps its own status and date:
- the latest site audit crawl, with redirects, canonicals, noindex and errors;
- the sitemap watched by IndexNow;
- Search Console impressions;
- content briefs and articles marked as published from ideas;
- topic-plan pages and topic owners;
- your CMS records.

A CMS draft is not a public page, a canonical is not a redirect, and a page without rankings still exists. The *Sources* box says how far each source reaches and when it was last read.

SEOcc has no CMS connection. Add drafts and scheduled posts by hand (**Add a CMS record**), or paste a CSV export under **Import a CMS export**:
- `title` and `status` columns are required; `url`, `publish_date`, `cms_id` and `note` are optional;
- WordPress statuses (publish, future, draft, pending, private, trash) are understood;
- a row with a known `cms_id` updates that record instead of adding a second one.

Addresses are matched without tracking parameters (`utm_…`, `gclid`), a trailing slash or the http/https difference. Other query parameters, and www against non-www, are kept apart.

A record without an address may be a page that already exists. SEOcc shows it as a *Possible match* but never joins it on its own; choose **This is the page** to confirm. Edits use the record's version, so two people cannot overwrite each other.

**Before new content.** Article ideas and new content briefs check the inventory. When an article page, a draft, a scheduled post, a brief or an article published from an idea already covers an idea's main search, the idea shows *Already in your content inventory*, so you improve or publish that instead of writing a duplicate. Shop, category and other pages on the topic are listed as *Other pages on this topic, not articles*: they exist, but they do not answer an informational search. Link them to the new article. Pages kept in a topic plan or owning a topic are not taken to cover that plan's subject; they only link to it.

### Overlap review

From the inventory, **Overlap review** lists pages and plans that may cover the same thing:
- **Near-duplicate text**: two pages whose body text is mostly the same. Text repeated on many pages, such as delivery notes, is left out, and so are listing pages: paginated copies (`?page=2`), author archives, and tag, topic and category archives, which have their own check.
- **Several pages for one search**: from Topics & owners. This is often fine, for example a category and a guide; it mainly hurts when the leading page keeps switching.
- **Article ideas on the same subject**: two ideas worded differently, such as "mold on pepper" and "pepper mold".
- **Topic archives splitting a subject**: two archives on one subject: the narrower archive's words are all in the other, or the two share most of their distinctive words. Words used in many of your archive addresses (for example "outdoor tomato" on a growing blog) don't count. Archives that differ only in such words come first. When one archive adds words of its own ("yield" and "high yield indoor pepper"), the pair comes later and is marked as a possible subtopic, because a parent and a child archive can both be useful.

At most 100 candidates of each kind are listed, strongest first; the counts include them all. Each candidate shows its evidence and what is uncertain. When the pages are of different kinds (a shop page and an article) or serve different purposes, it says they may need to stay separate. The page-kind check knows common shop and article addresses; a shop that keeps products under its own folder, such as `/seeds/`, may not get that note.

**Before a merge: what each page is and gets** shows each page's state, 28-day clicks and impressions, any redirect or canonical, and the topics it owns. It also proposes the address to keep: the target of another page's redirect or canonical first, otherwise the page with the most clicks.

Choose **Decide** to record *Keep separate*, *Merge planned* (with the page to keep) or *Undecided*, with a note. Nothing is merged automatically and SEOcc changes nothing on your site: carry out a planned merge in your CMS and add a change note.

## Everything about one URL

Open a URL to see its Google and Bing clicks, Google search queries, tracked rankings, latest audit issues, AI Overview citations, open opportunities and sampled backlinks. When connected, GA4 adds Organic Search sessions, key events and purchase revenue, and CrUX adds mobile Core Web Vitals.

Google query rows omit some anonymized searches, so they do not always add up to page totals. Search clicks and GA4 sessions are different measurements. Missing data means the source has not supplied it, rather than a zero result.

## What to improve

Each URL detail view starts with a ranked summary of useful next actions. SEOcc places audit errors before significant Google click declines, then ranking gains, audit warnings, internal links, possible overlapping intent, matched open opportunities and audit notices. This order is a review heuristic, not a prediction of ranking impact. Each candidate shows up to three supporting examples, a suggested next action and a link to the full evidence below.

Click declines compare the displayed 28-day search window with the preceding 28 days. Both periods need a completed Google page import for every day and stored metrics for the exact URL. A decline candidate requires at least 100 previous clicks and a drop of 30% or more. Missing metrics are not interpreted as zero; Bing weekly buckets are not compared.

Ranking candidates use Google queries at average positions 4–20 with at least 50 impressions, or stored tracked keywords at positions 4–20. Review the source dates and search intent. No candidates means only that the available evidence did not meet these rules; it does not establish that the page is problem-free.

When the page could gain many clicks, *Improve rankings within reach* moves up. With about 200 or more extra clicks it comes right after audit errors, ahead of click declines. With 50 or more it stays just behind click declines. Its first line says how many clicks are at stake.

## What the page could gain

Three sections under the search queries show what the page could gain and what was already tried:

- **Worth reaching the top 3:** for each search at positions 4–20 with at least 50 impressions in the window, the clicks now, the clicks expected at position 3 and the difference, at most five searches with the largest difference first. The expected clicks come from your site's own clicks by position across its non-brand searches, blended with a general curve where your own data is thin. It is an estimate: AI Overviews, ads, maps and the intent of the search all change how many people click.
- **Pages ranking above it:** when a *Why they outrank you* analysis from the last 180 days included this page, the page is compared with the readable pages ranking above it in that analysis, up to five. Each measure (words, questions answered, tables, lists, images, videos, structured data types, internal links) shows this page's count against the median of the pages above. A measure below 60 % of that median is marked *Behind*, and *What to improve* adds *Close the gap with the pages above it*. Counts describe the pages, not why they rank: add what helps searchers, never filler to match a number. To see this for another page, run the analysis on a keyword topic the page ranks for.
- **Changes to this page and their results:** up to ten changes recorded on this exact URL in the last year, newest first, with the measured metric, the result and its effect, read the same way as on *Manage → Change timeline → What worked*. A change without a plan shows *No measurement plan*; open it to add one.

The summary uses stored data and does not start imports, paid lookups or AI calls. Ask AI and MCP `page_details` receive the same recommendations and comparison availability. Their existing permissions remain unchanged.

## Possible cannibalization

The Pages view highlights queries where several pages compete in the top 20. It requires at least 200 query impressions, and each candidate needs at least 50 impressions and a 10% share. Review the intent and the pages before deciding to consolidate or rewrite content; several ranking pages are not always a problem. A notice appears when the analysis reaches its row limit.

## Internal link suggestions

SEOcc uses the latest completed crawl's link graph and relevant titles or H1s to suggest indexable, self-canonical pages with incoming links that could link to a page ranking at positions 4–20. Existing links are excluded. Suggestions do not edit your website; review and add useful links yourself.

### Kinds of links

Each internal link found by the site audit is classed:
- *In the text*: a link inside a sentence of real text;
- *Related-article card*: a card linking to another article;
- *Product or cross-sell card*;
- *Menu, footer or sidebar*: template links, breadcrumbs included;
- *Other body link*.

Each class shows a confidence (high, medium, low): page regions and class names are clear signals, while a heading or image card is a weaker one. For one page, **Links of one page** groups the links by class. A footer or menu link that repeats on every page counts once, with the number of pages that carry it. For an audit crawled before 0.35.0, links in the page body show as *Not classified* until the next site audit.

Only a link *in the text* stops a suggestion: a related-article card or a menu link to a page leaves room for a link in the paragraph. Before suggesting a link, SEOcc also checks that the paragraph and the target are about the same kind of thing. "Free seeds" in a paragraph about earning reward points is not suggested as a link to a prize-draw page; the left-out matches are counted. The box **What the link evidence covers** gives the crawl size and date and the limits the suggestions are built under. No suggestion for a page does not prove it is well linked.

## Ask about the page

Ask AI can read the page's stored evidence. Connected MCP clients can use `pages_list` and `page_details` when their access includes **Pages** (`pages:read`). Existing tokens do not gain this permission automatically; create a token or approve a connection with the scope you need.

## Backlinks by page

From **Backlinks**, request live links or links lost in the last 30 days. Each result stores up to 1,000 links and is a sample, not a complete count. Page details reuse the stored samples without another provider charge.

Weekly lost-link monitoring is paid and off by default. Review its estimated cost and your DataForSEO budgets before enabling it. It also refreshes lost referring domains so stored strong-link losses can feed alerts.
