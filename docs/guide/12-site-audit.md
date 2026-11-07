# 12. Site audit

The site audit checks your website the way a search engine sees it. It follows the links between your pages and reports technical problems: broken links, redirects, missing titles and more.

## Running an audit

Open a project and go to **Site audit → Start audit**. The audit runs in the background with every cron run, and the page updates itself while it works. Depending on your server and the cron interval, about 500 pages take 10 to 30 minutes. You can leave the page; the results are there when you come back. **Stop** cancels a running audit.

Owners, admins and members can start audits; viewers can read the results.

## What is checked

| Severity | Issue |
|---|---|
| Error | Broken page (4xx), server error (5xx), page not reachable, missing title |
| Warning | Redirect chain, internal link over `http://`, duplicate title, missing or duplicate meta description, missing H1, response slower than 2 seconds, HTML larger than 2 MB |
| Notice | Not linked internally (in the sitemap, but no checked page links to it), redirect, title longer than 60 or shorter than 10 characters, meta description longer than 160 characters, several H1 headings, noindex, canonical pointing elsewhere, fewer than 200 words, blocked by robots.txt |

Four growth notices point at improvements rather than errors and do not lower the health score:
- **No structured data**: no JSON-LD or microdata. Product and article pages get a hint about the markup to add.
- **Article without an author**: no byline, author markup or author meta on an article page.
- **Article without source links**: an article of 600 words or more whose text links to no other site. Menus, cards, footers and your own pages do not count.
- **Structured data without the page's own type**: the page has structured data, but only for the site (breadcrumbs, organization). A product page, by its Open Graph type, has no Product markup, or an article has no Article markup.
- **Product or category page without answered questions**: a product page, or a category page marked as a collection or item list, with no heading phrased as a question (H2 to H4, or an accordion title) and no FAQ markup. Google no longer shows FAQ rich results; answering buyers' questions is still worth it for visitors and AI answers.
- **Product page without a specification table**: no table or list of terms (three entries or more) with the product's key facts.
- **Deep in the site structure**: more than three clicks from the start page.
- **Business details missing**: checked once per audit on the start page and any contact page (an address ending in contact, kontakt, impressum, imprint, about, o-nama and similar). It lists what neither states:
  - Organization or LocalBusiness structured data;
  - a phone number as a `tel:` link or in the markup;
  - an address in structured data.

  It is reported on the start page. Pages crawled before 0.46.0 did not record these details, so the check waits for the next audit.

These appear as opportunities with their own action plans.

Title, description, heading and text checks apply only to pages that are meant to be indexed. The pages of a paginated listing (`?page=2`, `?page=3`, …) usually share a title, so they count as one page for duplicate titles and descriptions. Pages with *noindex*, or with a canonical tag pointing to another address, are listed once as such and not checked further.

## Reading the results

- **Health score:** 100 means that no checked page has an error or a warning. Pages with an error count fully against the score, and pages with warnings only count half. The arrow shows the change since the previous audit.
- **Issues by type:** how many pages have each issue, and how that changed since the previous audit. Click a type to see its pages and advice on fixing it.
- **Linked from:** for broken pages, redirects and blocked pages, up to three pages that link to them, so you know where to fix the link.
- **All checked pages:** every address with its status, title, word count, links, response time and depth (clicks from the home page; "—" when no link from the home page leads there).
- **Export CSV:** all issues of the audit as a spreadsheet.
- **Long titles worth fixing first:** click *Title too long* to see which long titles cost clicks.
  - **By page type:** how many long titles each page type has (the first part of the address, such as `/tastes/…`), how many of them are on Google's first page, and the clicks they miss together. Many long titles of one type usually come from one template, so fixing the template fixes them all.
  - **Pages first:** up to 20 pages in positions 1–10 with at least 100 impressions in the last 28 days of Search Console data, ranked by the clicks they get below your site's usual rate at that position. The rate comes from your site's own clicks by position, or a general curve while your own data is thin. Each page shows its title as cut at 60 characters, about what fits in a result, so you see what searchers see.

  A shortfall can also come from the search itself, such as ads, an AI Overview or a brand search. The title is one likely reason, not proof. Pages with too little search data are counted but not ranked.

The last 5 audits are kept, so you can open an earlier one from the **Audits** list.

## Settings

Project editors can configure the free audit below. Browser checks are requested separately.

## Internal links

Open **Improve → Internal links**. It uses the newest completed free audit and your topics with an owner page (*Research → Topics & owners*).

- **Link suggestions** lists paragraphs on your pages that mention a topic word for word (one of its searches, plurals included) but don't link to the page that owns it from their text yet. A link in the menu, header, footer or sidebar doesn't count, because it doesn't replace a link in the text. Pages that compete for the topic get no suggestion for it. A search that has its own page (an address ending in that search, also when the search adds words, such as *regular tomato seeds* for /regular-seeds) is left to that page. Only sentences count: paginated copies of a page and short product lines are skipped. The section says when the suggestions were built. The matched words are highlighted: add the link there, or around similar wording that reads naturally. Topics with more demand, and pages that already get many internal links, come first. There are at most 5 suggestions per topic and 3 per source page. Filter by topic or page address.
- **Reword with AI** opens Ask AI with a prepared request to rewrite the paragraph around the link. It sends nothing until you press send.
- **Links of one page** shows, for an address the audit crawled, every link into and out of it. Each link shows its anchor text, where it sits (text, menu, header, footer, sidebar) and, for links in the text, the paragraph around it.

Suggestions are rebuilt within the hour after an audit completes and once a day, so a new owner page shows up without a new crawl. The crawler reads server HTML only: links that JavaScript adds are not seen. Paragraphs are kept only for the newest audit. No suggestion means your pages already link where they mention a topic, no topic has an owner page yet, or no paragraph uses a topic's searches word for word.

AI assistants can read the same data with the `internal_links` tool (*Pages* permission).

## Browser-rendered site audit and Lighthouse

Under **Site audit**, choose an HTTPS start URL already known under Pages on the current project domain. Choose a mobile browser crawl of 1, 10, 50 or 100 pages, or a one-page Lighthouse mobile diagnostic. Browser estimate: $0.0051 per selected page limit; Lighthouse estimate: $0.005 per task. Existing API budgets apply. This is separate from the free weekly HTML crawl.

Cron checks robots/public DNS, submits the page, then retrieves the saved task in later runs. Same-page retries retrieve unfinished saved tasks without buying them again. Unknown submission outcomes block replacement checks until usage is reviewed. Previous completed evidence stays visible while a check is pending or fails.

For a failed known task, **Retry saved check** retrieves it again for free. **Start new paid check** explicitly buys a replacement, useful when old provider results have expired. Neither action overrides an uncertain submission outcome.

Results show rendered title/description/headings, sampled links, loaded resource entries and declared CSS/image references, provider findings and lab LCP/CLS. Lab numbers describe one browser run; Google CrUX separately reports real-user p75 LCP/INP/CLS. The browser check does not provide INP or an overall field-data pass/fail assessment, and does not change the free crawl's health score.

DOM samples are capped at 100 links, 20 H1s and 100 resource references. CSS backgrounds come from the first 100 DOM elements and image sources from the first 40 images. Provider resource statuses are retrieved in resumable batches, capped at 10,000 resource rows and 6 MB per crawl; each waterfall lists up to 1,000 resources. Provider-loaded CSS resources are included. Popup dismissal can be requested, but is not proof of consent. General scroll/click/consent journeys and complete unloaded CSS traversal are not supported; resources appearing later may be absent. Declared references are not proof that those resources loaded.

The latest five completed browser/Lighthouse diagnostics can be reopened. Browser metadata and proven 404/410 resource findings feed Opportunities and printable/email reports as separate browser findings. Lighthouse shows category scores, navigation metrics and up to 50 diagnostics; total blocking time is not INP, and lab scores are not a field-data pass.

New browser checks also retain a separate **Lazy-image candidates** sample: up to 40 declared URLs from `data-src`, `data-original` and `data-lazy-src` on the first 40 images. Placeholder sources do not hide these declarations, and a full loaded-resource sample does not crowd them out. Candidates are deduplicated and resolved against the page URL; they are not fetched or labelled as successful loads or broken assets. Empty samples mean no matching declarations in that sample. Older snapshots show this evidence as unavailable. Responsive declarations have their own sample; scrolling and interaction-only content remain outside these samples.

**Responsive-image candidates** lists up to 40 distinct URL declarations from `srcset` and `data-srcset` on the first 20 images and `<picture>` sources combined. At most 20 attributes are retained, each no longer than 2,048 characters; longer attributes are omitted rather than truncated. URL tokens retain embedded commas; unsupported schemes such as data URLs are filtered out. The sample does not validate descriptors, evaluate media conditions or format support, or predict which image the browser selects. These declarations are separate from loaded resources and do not create broken-resource findings. Older snapshots show responsive evidence as unavailable.

New snapshots record the browser's document base URL under **Reference base URL**. Relative declarations resolve against it, including when an HTML `<base>` points to a CDN; absolute references keep their own address. The audited page's identity and crawl scope remain unchanged. An invalid or unsupported supplied base leaves relative references unavailable while valid absolute references remain. Older provider responses without base evidence use the audited page URL. References remain passive evidence and trigger no extra fetches.

CSS background sampling also inspects `::before` and `::after` on the first 100 DOM elements. Resource evidence marks these as `css_before_background` and `css_after_background`. Loaded entries and ordinary element backgrounds are sampled first; all resource references share the existing 100-reference cap, so a full sample can omit later declarations. Each background value is sampled up to 16,384 characters. These references do not establish visibility, HTTP status or successful loading, and do not create broken-resource findings by themselves. This is computed-style sampling, not exhaustive stylesheet traversal.

Free sitemap startup now persists its pending documents and robots cache between cron runs. Each startup step makes at most one HTTP request. Robots redirects follow up to five guarded public HTTPS hops, persisting each hop before continuation; redirects alone never grant crawl permission. HTTP reserves do not provide a hard deadline guarantee for host operating-system DNS resolution.

## Free audit settings

Project editors can set:

- **Pages per audit:** 100, 250, 500 (default), 1,000 or 2,000. The audit stops when it has found this many addresses.
- **Audit the site every week:** a new audit starts automatically 7 days after the last one.

## How the crawler behaves

- It starts with your home page and the pages in your sitemap (the sitemap listed in robots.txt, otherwise `/sitemap.xml`). Sitemap pages fill at most 80 % of the page limit, so pages that are only linked stay included.
- It follows paginated listings up to page 10; products further back are usually in the sitemap.
- Page discovery stays on your project's domain, with and without `www.`, over HTTPS. A separate bounded resource pass can check referenced assets and external-link destinations; it does not crawl those websites.
- It identifies itself as `SEO-Control-Center` and follows your robots.txt: the rules for `SEO-Control-Center`, otherwise the rules for `*`. To exclude parts of your site, add for example:

  ```
  User-agent: SEO-Control-Center
  Disallow: /shop/cart
  ```

- It loads one page at a time, so it does not put your server under load.
- It does not log in, submit forms or run JavaScript. Resource checks read at most 1 KB to establish reachability; they do not render images or execute scripts. Pages that only show their content after JavaScript runs may be reported as having little text.

## Referenced assets and external links

For each HTML page, SEOcc samples up to 15 image/script/stylesheet references and five external links, with at most 500 distinct targets per audit. It follows each target host's robots rules, requires public HTTPS destinations and does not follow redirects.

- Missing image `alt` attributes are notices. An explicitly empty `alt` is valid for a decorative image.
- HTTP asset URLs are mixed-content warnings; HTTP targets are not fetched.
- A 404 or 410 is a broken-asset or broken-external-link warning.
- Transport failures and server errors are resource-unavailable notices. A 403, 429, redirect or robots exclusion is not labelled a proven broken link.

Issue details list sampled URLs on the referring page. Checks are bounded samples, not a complete browser rendering: CSS background images, dynamically injected resources and lazy-loading attributes are outside this pass. The page-crawl count can reach its limit while resource checks are still finishing. Stopping the audit also stops remaining resource checks.

If the audit reports that your domain *does not resolve to a public address*, the domain points to a private network address and cannot be checked from your server.
