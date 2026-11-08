# Changelog

## [1.1.0] — 2026-11-08 — Article ideas across markets

- **Article ideas across markets.** *Research → Article ideas* now covers every market a project targets, not only the market of the newest tracked keyword. Markets come from the business profile; without them from the tracked keywords, most keywords first; without keywords the default market. The page names the source and links to the business profile to change them.
- **Cost per market.** One lookup is bought per competitor and market. The page shows competitors × markets × the price per lookup, and the cost per market. The run buys exactly what you confirmed: if competitors or markets changed and the cost went up, it asks you to confirm again. The estimate now counts the rows of every lookup, so it is a little higher, and closer to the real cost, also for one market.
- **Unsupported markets are skipped.** Markets DataForSEO Labs has no data for are named on the page and never bought, instead of failing the lookup.
- **Ideas per market.** Ideas are built per market. The same search in markets of the same language becomes one idea with the split per market; other languages stay separate and are never added up. Each idea names its markets, and a market filter shows one at a time. Single-market projects look as before. *Rebuild ideas now* stays free and uses the stored rows of every market.
- **Briefs, topic plans and tracking** carry the idea's markets: a brief's evidence names them, topic plans propose supporting articles in the pillar's languages, and published articles keep the markets with their prediction.
- **MCP.** `article_ideas` returns `markets` (with cost), `market_source`, `skipped_markets`, `estimated_cost` and `last_lookup.markets`, and `market` and `markets` for each idea. The top-level `market` stays, as the market labels joined. Published articles return `markets`.
- **Migration:** adds `article_idea_runs.markets` and `article_competitor_rows.market`. Earlier lookups keep working as their one market.

## [1.0.0] — 2026-11-08 — Open source

- **SEO Control Center is now free and open source under AGPL-3.0.** Every feature is available, with no licence key, edition or limit on projects and workspaces.
- **No connection to a vendor server.** The licence checks and the daily update check are gone. Download updates from GitHub Releases and upload them under *System → Updates*.
- **New installations** extract the install package and use the built-in wizard. See the installation guide.
- **Source code link** in the sidebar. If you run a changed version for others, set `SOURCE_CODE_URL` to your changed source.
- **Report a bug** now opens GitHub issues, unless you set `SUPPORT_EMAIL`.
- **`DISABLED_FEATURES`** turns off features you don't want, such as `backlinks` or `mcp`. Existing data stays readable.
- **Migration:** removes the `license_state` table, including any stored licence key.

## [0.51.0] — 2026-11-05 — Measure your past changes

- **Measure past changes.** *What worked* now offers to give your page changes from the last year the default measurement plan: clicks for the page (CTR for a title or snippet change), 28 days before and after, with the baseline frozen from the search data SEOcc already stored. Changes old enough show a result right away, so you don't have to wait 28 days. It handles up to 40 changes per click, is free and fetches nothing. Changes without enough stored data say so instead of showing a result.
- **Measured afterwards.** A result whose baseline was frozen more than 7 days after the change is marked *measured afterwards*, on *What worked*, on the page's details and on the change's Impact page. The plan was set after the change, so treat it as a rough guide.
- **Overlapping changes.** *What worked* and the page's details now show when other changes fall inside a result's comparison period: the effect cannot be credited to that change alone, and the same effect can appear on each of them.
- **MCP.** `what_worked` returns `measured_afterwards` and `overlapping_changes` for each change, and `page_details` returns `afterwards` and `overlapping` in `change_results`.
- No migration.

## [0.50.1] — 2026-11-03 — Brand findings scored properly

- **Brand searches now score like other searches.** On 0.50.0 they were stuck at about 17 % confidence and "very low priority", because they did not carry their search the way the scoring expects. They now use the same evidence as other search findings for confidence, effort and value, and count both Google and Bing. Marking one done now measures its outcome, which could not work before (0.50.0 live check, finding 43). Open findings update at the next analysis.
- **A generic project name is no longer a brand.** A project called "Web Shop" or "Online Store" no longer makes "web shop" a brand word, so searches containing it stay ordinary searches and count in your click curve. Your domain still counts. To have a name recognized, use it as the project name (finding 44).
- No migration.

## [0.50.0] — 2026-10-31 — Business details and brand searches

- **Business details missing (site audit).** Once per audit, SEOcc checks whether the home page or a contact page (contact, kontakt, impressum, about, …) states who runs the site:
  - Organization or LocalBusiness structured data;
  - a phone number as a `tel:` link or in the markup;
  - an address in structured data.

  Whatever is missing is reported on the home page, for example "Not found on the home page (no contact page found): a phone number (tel: link), an address in structured data". It works without a Google Business Profile, so it suits online shops too. It also becomes a finding with its own action plan, *State who runs the site*. Pages crawled before 0.46.0 are skipped until the next audit.
- **Brand searches below first place.** A new *Quick win* reports searches for your brand where your site is not first on average (with at least 20 impressions), for example "Rank first for your brand search “northwind good” (now 7.1)". A search counts as a brand search when its words, written together, are your brand word, with or without a final "s". So "northwind good" and "trail northwind good" count for northwindgoods. Its action plan, *Rank first for your own brand*, says what to check above you and what to make unmistakable on your home page. These searches no longer appear as ordinary "Move into the top 3" quick wins.
- No migration.

## [0.49.0] — 2026-10-30 — Every page change is measured

- **Measure this change.** When you record a change with a page URL on the change timeline, SEOcc now drafts its measurement plan and freezes the baseline at once. The plan measures the page's Google clicks (its CTR for a title or snippet change), 28 days before and after the change. The box is ticked by default; untick it for a change you do not want measured. Before, a plan existed only when someone opened the change and filled one in, so almost no change could ever show a result. Web Shop had 79 page changes in 180 days and no plan.
- The result appears on the change's Impact page, on the page's details and on *What worked*, once 28 days of data after the change are imported. Change the metric, target searches or control pages on the Impact page; changing them clears the baseline, so freeze it again there.
- Site-wide changes, edits of an existing change and changes from a change bundle (which drafts its own plan) are not affected.
- **MCP.** `annotation_create` drafts the plan too, unless `measure` is `false`, and returns what happened as `measurement_plan`.
- No migration.

## [0.48.0] — 2026-10-30 — Long titles worth fixing first

- **Long titles worth fixing first.** Click *Title too long* in the site audit to see a new card above the list. It ranks the pages with a long title by the clicks they miss. These are pages on Google's first page (with at least 100 impressions) that get fewer clicks than your site usually gets at that position. The estimate uses your own clicks by position, the same as on page details. The card also groups the long titles by page type (`/tastes/…`, `/seeds/…`, …), because many long titles of one type usually come from one template. For each page it shows the title as cut at 60 characters, its position, its clicks against the expected clicks, and how many it misses. A cut-off title is one likely reason for the shortfall, not the only one.
- **MCP.** `local_rankings` now returns the Local page's checks: `profile_vs_website`, `review_handling` and `local_pack_categories`. The AI assistant can therefore answer questions about name, address and phone consistency, review replies and the category gap.
- No migration.

## [0.47.0] — 2026-10-30 — What a page could gain

Three new sections on each page's details (*Pages → a URL*). All three come from data SEOcc already stores, and nothing new is paid for.

- **Worth reaching the top 3.** For the page's searches at positions 4–20 with at least 50 impressions, it shows how many more Google clicks per 28 days the page would get at position 3. The estimate uses your site's own clicks by position, or a general curve while there are too few of your own searches. *What to improve* now puts *Improve rankings within reach* higher when the estimate is large, and its first line says how many clicks are at stake.
- **Pages ranking above it.** When a *Why they outrank you* analysis from the last 180 days included this page, the page is compared with the readable pages above it. The measures are words, questions answered, tables, lists, images, videos, structured data and internal links, each against the median above, plus when each was last updated. Measures well below the pages above are marked *Behind*, and *What to improve* adds *Close the gap with the pages above it*.
- **Changes to this page and their results.** The changes recorded on this URL in the last year, each with its measured result (*Improved*, *Declined*, *No clear change*, …) or *No measurement plan*. They are read the same way as on *What worked*.
- **MCP.** `page_details` returns the same evidence as `click_potential`, `competitor_gap` and `change_results`.
- No migration.

## [0.46.1] — 2026-10-26 — Local check without a Business Profile

- **A business Google Maps cannot find no longer fails the Local check.** When DataForSEO finds no Business Profile for the business name near the location ("No Search Results"), the check now completes with the Maps positions it saved. The Local page says that no profile was found and what to check: the name exactly as on Google Maps, and a location close to the business. A shop that sells only online has no profile. Before, the whole check was marked failed, weekly checks retried it, and the profile, review and category sections stayed hidden without saying why (0.46.0 live check, finding 42).
- No migration.

## [0.46.0] — 2026-10-26 — Local: profile, website and reviews

- **Profile and website.** *Project → Local* now compares your Google Business Profile with what your website states: business name, phone number, address, the website link in the profile, and LocalBusiness structured data. Each detail shows *Matches*, *Differs* or *Not on the site*. The site audit now reads these details from every crawled page: the business in the structured data (its type, name and address) and every phone number, including `tel:` links. Run a new site audit once to fill the comparison; earlier audits did not record them.
- **Review handling.** From the imported Google reviews, the Local page shows the average rating, how many reviews have an owner reply, critical reviews (2 ★ or lower) still without a reply, with the ones to answer first, how many new reviews arrive per month, and your review count against the businesses in the local pack.
- **Categories in the local pack.** The Local page lists the primary categories of the other businesses in the top 3 for your local keywords and marks yours. When most of them use a category your profile does not have, it says so.
- **Three new Local SEO findings, each with its own action plan:** *Make your website match your Business Profile*, *Answer your Google reviews*, and *Consider "…" as your Business Profile category*. They read stored data only; nothing new is paid for. The data-quality page lists imported Google reviews as a source.
- Migration `2026_10_26_000100` adds `site_audit_pages.business_signals`.

## [0.45.1] — 2026-10-26 — Overview without empty space

- **Performance prognosis** now spans the full width of the overview, with *Where to act next* below it. Before, the prognosis box was stretched to the height of the longer list beside it, which left a tall empty band under *About this data* (about 280 px on a wide monitor, more on smaller ones). On a wide enough screen, the query candidates and the saved findings now sit side by side.
- **Why it changed:** *Landing-page outcomes* now sits under *Recent SEO changes*, and *Where keywords rank* has its own column.
- No migration.

## [0.45.0] — 2026-10-26 — What worked on your site

- **What worked.** A new page under *Manage → Change timeline → What worked* collects the measured results of your SEO changes that have a measurement plan:
  - **by kind of change:** title and snippet, content update, internal links, technical change and others;
  - **by action plan:** for changes deployed from a change bundle that started on a finding.

  Each group shows how many changes were measured, how many improved, declined or showed no clear change, the median effect, and how many were compared with control pages. Results that could not be read and plans still running are counted apart, never as "no change". A group with fewer than three measured changes says it is too few to show a pattern.
- **Your record next to the published evidence.** On a finding, the action plan now shows your own result for that plan below its "how well tested" label, for example "On your site: 3 of 4 measured changes improved, median +12 %".
- **Reminders.** The page lists plans whose baseline was never frozen, so their result can never be read. It also lists changes to a page from the last 180 days that have no measurement plan.
- **MCP.** New read-only `what_worked` tool (`pages:read`, 65 tools).
- No migration.

## [0.44.3] — 2026-10-26 — Every country and language

- **All Google countries and languages.** Keyword tracking, research, brand mentions and your business profile now offer every country and territory Google Ads targets: 244, from Afghanistan to Zimbabwe, including Taiwan, Hong Kong, Puerto Rico and Kosovo. They also offer all 128 languages Google Search is available in, including Chinese (Simplified and Traditional), Japanese, Arabic, Hebrew, Greek, Thai and Ukrainian. Before, there were 15 countries and 10 languages.
- **The right language first.** When you pick a country, the language list shows first the languages keyword research has data for there (French, Dutch and German for Belgium, for example) and selects the country's main one.
- **No paid request that cannot work.**
  - Keyword volumes come from Google Ads, which knows 48 of these languages. For any other language, such as Bosnian, SEOcc now asks for the country's volume without a language filter. Before, DataForSEO refused the whole request with "Invalid Field: 'language_code'" (0.44.2 live check, finding 41).
  - Keyword research covers 94 countries, each in its main languages. A lookup in an uncovered pair, such as Croatia in English, is refused before anything is queued or paid, and the message names the languages that work.
- **Keywords in every script.** Keywords are stored in one Unicode form, so "é" typed as one character and as "e" plus an accent is the same keyword. Non-breaking, ideographic and zero-width spaces from copy and paste no longer create separate keywords. Text that is not valid UTF-8 is refused instead of being dropped silently.
- Russia and Belarus are not offered, because DataForSEO no longer serves them.
- No migration.

## [0.44.2] — 2026-10-25 — Invitations and project links work

- **Invitations work again.** Someone invited to a workspace could open the link from the e-mail, but creating the account failed with "This request came from another site and was blocked". The invitation page told the browser to hide where the form came from, and the security check then took the form for one sent from another site. The page now keeps the link private from other sites without hiding it from SEO Control Center itself.
- **New members land in the workspace.** After choosing a name and password, a new member is signed in and sees the workspace they were invited to, with the role they were given. Someone who already has an account joins and then signs in with their own password, as before.
- **Password reset links** had the same problem and are fixed too.

From the 0.44.1 live check (an internal audit, finding 40):

- **Links to a project in another of your workspaces work.** A new sign-in starts in your first workspace. Before, a link to a project in another workspace (from an alert e-mail, a report, a bookmark or another tab) answered "Not found" until you switched workspace by hand. Now SEOcc switches to the project's workspace, opens the page and says so ("Switched to the workspace NorthwindGoods to open Web Shop.").
- Projects you are not allowed to see still answer "Not found", exactly like addresses that do not exist (ADR-013, amended). Forms sent to another workspace's project are never redirected.
- No migration.

## [0.44.1] — 2026-10-25 — No merges of different products

From the 0.44.0 live check (an internal audit, finding 39):

- **Merge near-duplicate pages** no longer proposes pairs in which a page is a product. A product page is one whose Open Graph type or Product markup marks it as a product in the latest site audit. Different products often share a description. On Web Shop, one cultivar from two breeders (Fast Blossoms and Hillside Farm) and its sunflowering variant would have been proposed for a merge with a 301 redirect. The overlap review still lists such pairs, so a person can decide with the preview in view.
- No migration.

## [0.44.0] — 2026-10-25 — Product, category and duplicate-page checks

More changes that published SEO tests measured, turned into checks (ADR-031, amended):

- **Structured data without the page's own type.** A new site audit notice for pages that have structured data, but only for the site (breadcrumbs, organization, website):
  - product pages (by their Open Graph type) without Product markup;
  - articles (by Open Graph type or a blog, guide or news address) without Article markup.

  Before, only pages with no structured data at all were reported. The opportunity's plan is *Tested repeatedly*.
- **Product or category page without answered questions.** A new notice for product pages, and for category pages marked as a collection or item list, that have no heading phrased as a question and no FAQ markup. Its plan, *Tested repeatedly*, adds a short section answering what buyers ask before choosing. It notes that Google no longer shows FAQ rich results, so the value is in the answers themselves.
- **Product page without a specification table.** A new notice for product pages with no table or list of terms holding their key facts. Its plan is *Tested repeatedly*.
- **Merge near-duplicate pages.** Pages of the latest site audit with nearly the same body text, of which at least one is shown in search, become a *Cannibalization* opportunity (*Some tests, effect varies*):
  - It keeps the page with the most clicks and folds the other into it with a permanent redirect.
  - It links to the overlap review. Pairs marked *Keep separate* there, or whose titles suggest a different purpose, are left out.
  - *Near-duplicate pages* appears as a new dataset in Data quality.
- **Better reading of pages.** A list of terms with three entries or more (`<dl>`, as many shops use for specifications) now counts as a table. Accordion titles and H4 headings phrased as questions count as question headings. Citation readiness uses the same signals.
- These checks need the next site audit. Pages crawled before 0.43.0 stay unknown and are not reported.
- No migration.

## [0.43.2] — 2026-10-25 — Citation checks still to come

From the 0.43.1 live check (an internal audit):

- **Citation readiness** says when a page's checks are not all known yet: "Nothing missing so far · 5 checks after the next site audit". Before, a page crawled before 0.43.0 with its one known check passed read "1 / 1 · Nothing missing", as if it were ready. On Web Shop this affected the product and taste pages. "Nothing missing" alone now means every check that applies to the page is known and passed.
- No migration.

## [0.43.1] — 2026-10-25 — Citation checks that fit the page

From the 0.43.0 live check (an internal audit):

- **Citation readiness** counts *Names an author* and *Links to sources* only for articles. An article is a page with Article structured data or `og:type` article, or a page in a blog, guide, news or similar folder. Product, category and home pages are no longer listed as missing an author or sources, so on Web Shop the product pages and the home page show only the checks that apply to them. *What is checked* marks both checks *Articles only*.
- **Data quality** says *Not checked yet* for AI crawler access before the first check, and tells you how to start it. Before, the note suggested that robots.txt could not be read. The unreadable-robots.txt notice now appears only after a check that actually failed to read it.
- No migration.

## [0.43.0] — 2026-10-25 — AI visibility readiness

Three new sections on **Project → AI visibility** (ADR-043):

- **AI crawler access.** A free check reads your site's robots.txt and llms.txt every week, or within minutes with *Check again*. It shows whether each AI crawler may fetch your home page and your 10 most-clicked Search Console pages.
  - **Search and answer crawlers:** OAI-SearchBot, ChatGPT-User, PerplexityBot, Perplexity-User, Claude-SearchBot, Claude-User, Googlebot, Bingbot and Applebot.
  - **Training crawlers:** GPTBot, ClaudeBot, Google-Extended, Applebot-Extended, CCBot and meta-externalagent. Blocking these is shown as your choice.

  Each crawler is *Allowed*, *Blocked* or *Some top pages blocked*, and the page shows which robots.txt rule applied. A blocked search crawler becomes an *AI visibility* opportunity with a plan to allow it. llms.txt is reported with the *Few published tests* label. When robots.txt cannot be read, nothing is evaluated.
- **Citation readiness of your top pages.** For the 10 most-clicked pages, from the latest site audit, a checklist of what AI answers can quote. Each check carries its how-well-tested label:
  - *Tested repeatedly:* names an author, structured data for the content, links to sources, facts with figures, tables or lists.
  - *Some tests, effect varies:* question headings, a stated update date.
  - *Few published tests:* a short opening answer.

  The crawler now records these signals. Pages crawled before this release show only the first three checks until the next audit.
- **Visits from AI assistants.** With GA4 linked, each import also reads the sessions, key events and revenue that ChatGPT, Perplexity, Gemini, Copilot, Claude and others send, across all channels. The page compares the last 28 days with the 28 before, per assistant. These numbers are a lower bound: visits from assistant apps often count as direct traffic. A failed AI referral report never fails the GA4 import.
- **MCP:** `ai_visibility` adds `crawler_access` and `citation_readiness`, and `analytics_summary` adds `ai_referrals`.
- **Migration** `2026_10_25_000200`: `ai_crawler_checks`, `site_audit_pages.citation_signals`, `ga4_ai_referrals` and `ga4_ai_referral_imports`.

## [0.42.1] — 2026-10-25 — Only confirmed main pages for topic links

From the 0.42.0 live check (an internal audit):

- The topic-link check only uses a topic's main page when it can be trusted: a person set it under *Page that should own this topic*, or Search Console shows an article page with at least half of the topic's searches. Before, a product page that merely led a topic, such as `/seeds/blue-lobster` with 34 % for "slicer vs paste" on Web Shop, could have become the page that articles were told to link to.
- The finding says where its main page came from. Topics & owners explains that setting the main page also turns on the link check.

## [0.42.0] — 2026-10-25 — Source and pillar checks

More of the changes that published SEO tests have measured, turned into checks (ADR-031, amended):

- **Article without source links.** A new site audit growth notice. The crawler now counts the other sites an article links to from its text; menus, cards, footers and the site's own pages do not count. An article of 600 words or more that links to none gets the notice. It becomes an opportunity whose plan, *Tested repeatedly*, cites the sources of facts and figures inline. Pages crawled before this release stay unknown until the next audit.
- **Topic pages that do not link to their main page.** Topics & owners names each topic's main page. Other pages that Google shows for the topic, with at least 50 impressions and no link to the main page from their text or related cards, become an internal-links opportunity, *Tested repeatedly*. Its plan adds a descriptive link from each to the main page and links back. Menu and footer links do not count, and pages the latest audit did not crawl are skipped.
- **Delete a change bundle.** A draft or approved bundle that was never deployed can be deleted with its history. A deployed bundle stays as the record of what changed.
- **Migration** `2026_10_25_000100`: `site_audit_pages.source_links`.

## [0.41.0] — 2026-10-25 — Evidence-led opportunities

Uses what published SEO tests have measured to make opportunities easier to act on and to test (ADR-031, amended).

- **How well tested.** Every action plan now says how well tested its kind of change is:
  - *Tested repeatedly*: several independent tests measured an effect.
  - *Some tests, effect varies*: results differ by site or intent.
  - *Few published tests*: try it on a few pages first.
  - *Search engine guidance*: technical fixes.

  The labels are SEOcc's own words, with no study named or scored, and they never change a finding's priority. *Also consider* ideas are marked as worth testing on a few pages first.
- **Refresh aging content.** A new opportunity type for articles (blog, news, guide and similar folders) that have at least 300 impressions and an average position of 20 or better, and that your sitemap says have not been updated for over a year. The action plan updates facts and figures, adds missing subtopics, keeps what ranks, and warns against changing only the date. No click gain is estimated. It needs sitemap last-modified dates from the IndexNow sitemap check; the new *Page update dates* dataset in Data quality shows whether they are there.
- **Start a change bundle from an opportunity.** *Start a change bundle* on a finding opens the new-bundle form with:
  - the finding's page, problem and evidence;
  - its action plan's expected effect and revert rule;
  - empty edit rows for the fields that kind of plan usually changes (title and description for a low CTR, for example).

  When the deployment is recorded, SEOcc drafts a measurement plan on the recorded change: the matching metric (CTR, clicks, position or impressions), the page, its query and 28 days. Add control pages and freeze its baseline there. A plan that cannot be drafted never blocks the record.
- No migration.

## [0.40.0] — 2026-10-24 — Change bundles

Phase 3 of the grounded recommendations plan (ADR-042), the last item. SEOcc records, verifies and guards changes; it never edits the live site.

- **Manage → Change bundles.** A bundle is a reviewed set of exact edits to one page: title, meta description, main heading, canonical, noindex, body text, redirect or other, each with its current and new text. It also holds the problem, the evidence, the expected effects and how to validate.
- **Live baseline and approval.** *Capture live baseline* reads the page as it is now (status, title, description, headings, canonical, noindex, word count, body text). The approval binds to a SHA-256 of the bundle's content and that baseline, and any edit clears it.
- **Version guard.** Before a deployment can be recorded, *Check the live page* reads the page again. It must match the approved baseline and be from the last 24 hours. If someone changed the page after the approval, recording is blocked and the differences are listed ("The live page changed since the approval (Title)").
- **Deployment record.** People make the edits in their CMS and record which were applied. A partial deployment is recorded as such, with what failed. The deployment is added to the change timeline, where its impact and measurement plan follow.
- **Verification and rollback.** *Verify on the live page* reads the title, description, main heading, canonical and noindex back; body text and other edits are marked for a check by hand. A rollback is recorded with its reason and also goes on the timeline. Every step is kept in the bundle's history.
- **MCP.** New read-only `change_bundles` tool (64 tools).
- **Migration** `2026_10_24_000600`: `change_bundles` and `change_bundle_events`.

## [0.39.1] — 2026-10-24 — After-only goals for new pages

From the 0.39.0 live check (an internal audit):

- **After-only goal.** A plan can set a goal for its primary metric over the period after the change, for example at least 500 impressions in 28 days (at most a position for average position). It needs no baseline, so a new page, which has no data before the change, can be measured. It reads *Goal reached* or *Goal not reached* behind the same import gates, and says that reaching a level does not show what the change caused. Before, the Mold on Pepper article could only ever be *Insufficient evidence*.
- **Hint.** A before/after plan whose targets had no data before the change suggests an after-only goal instead.
- **MCP.** `seo_change_impact` adds `goal` to the plan, and `reached` and `not_reached` to the result statuses.
- **Migration** `2026_10_24_000500`: `measurement_plans.goal`.

## [0.39.0] — 2026-10-24 — Measurement plans

Phase 3 of the grounded recommendations plan (ADR-042).

- **A measurement plan per SEO change** (Manage → SEO changes → change impact). Before the results come in, record what the change should move:
  - the objective;
  - the primary metric: clicks, impressions, CTR, average position, organic sessions or key events;
  - target pages and queries (none means the whole property);
  - control pages the change did not touch;
  - 14, 28 or 90 days on each side of the change day;
  - review dates in the project timezone.
- **Frozen baseline.** *Freeze baseline* stores the period before the change with how complete each source was (Search Console and GA4 days, targets without data). A later change of metric, targets, controls or period clears it.
- **Readiness gates.** The result is read only when every gate passes: the change was made, the baseline is frozen and complete, the period after the change is over, and it is fully imported. Complete means every day imported and every target measured: a target without data, such as a page that did not exist yet, is a gap, not a zero. Until then the plan is *Planned* or *Pending* ("Only 2 of 28 days after the change are imported so far"), never a result.
- **Results.** *Improved*, *Declined* or *No clear change* (under 5 %, or half a position). *Insufficient evidence* covers a missing or incomplete baseline, days missing after the change, or fewer than 100 impressions (30 sessions) before it.
- **Controlled or association.** With control pages measured over the same periods, the effect is the difference between targets and controls, and the result is marked *controlled*. Without them it is an association. Other recorded changes in the periods are listed.
- **MCP.** `seo_change_impact` adds `measurement_plan` (plan, baseline, gates and result; still 63 tools).
- **Migration** `2026_10_24_000400`: `measurement_plans`.

## [0.38.2] — 2026-10-24 — LCP phases as shares

From the 0.38.1 live check (an internal audit):

- The LCP phase table reads *How the observed page load split the LCP time* and shows each phase's share next to its observed time. A note says that Lighthouse measures the phases in the page load it observed, before its simulated throttling, so they don't add up to the run's LCP. Before, *Where the LCP time went* invited a sum that doesn't hold: 2.6 s of phases against a 7.4 s LCP on Web Shop.

## [0.38.1] — 2026-10-24 — Speed diagnostics for Lighthouse 13

From the 0.38.0 live check (an internal audit):

- **LCP element on Lighthouse 13.** PageSpeed Insights now runs Lighthouse 13, which moved the LCP element and its phases into the *LCP breakdown* insight. The panel reads them there, and also shows the *LCP request discovery* checks: fetch priority, discoverable in the HTML, and not lazy-loaded. Before, the LCP section was missing on Lighthouse 13 runs.
- **PageSpeed settings explained.** PageSpeed Insights does not report its throttling or screen size. Instead of "not reported", the panel says it uses its fixed mobile or desktop settings, and that its runs are compared by Lighthouse version only.
- **Context apart from problems.** Insights without an estimated saving or value, such as *Network dependency tree*, are listed as *Also flagged* after the ranked diagnostics. The LCP insights are shown in the LCP section.
- **MCP.** `lab_tests` diagnostics add `lcp.checks` and `also_flagged`.

## [0.38.0] — 2026-10-24 — Speed diagnostics

Phase 3 of the grounded recommendations plan (ADR-042).

- **Why the latest run scored N.** Each speed test profile explains its latest run from the Lighthouse report the run already received. No extra call and no extra cost. It shows:
  - the LCP element with its markup, its image address and where the LCP time went (TTFB, load delay, load time, render delay);
  - the render-blocking resources with their delay and size;
  - the top diagnostics below a good score, with Lighthouse's estimated savings;
  - requests by type and the largest requests;
  - the test configuration (device and screen, throttling, Lighthouse version, tested address, user agent);
  - a reference to the raw report: SHA-256, size, fetch time and the DataForSEO task. The report itself is not stored.
- **Only compatible runs are compared.** A run that used another Lighthouse major version, throttling or screen than the run before is marked *Different setup*. Its score change is not shown, and the reason is given instead.
- **MCP.** `lab_tests` adds `diagnostics` for each profile's latest run, and `comparable_with_previous` with its reason on each run (still 63 tools).
- **Migration** `2026_10_24_000300`: `lab_runs.diagnostics`. Runs from before keep their metrics without diagnostics.

## [0.37.1] — 2026-10-24 — Indexing history: sitemap wording

From the 0.37.0 live check (an internal audit):

- The timeline's sitemap event reads *SEOcc first read it in your sitemap* and says the address may have been listed earlier. Before, *First seen in your sitemap* on 2026-10-19 read like a publication date for an article Google had crawled on 2026-10-07.

## [0.37.0] — 2026-10-24 — Indexing history

Phase 3 of the grounded recommendations plan (ADR-042).

- **Visibility → Google indexing → History.** Each address gets six separate states, each with its evidence and date:
  - *public*: from the latest site audit;
  - *technically indexable*: no noindex, no canonical to another address, and not blocked by robots.txt;
  - *known to Google*;
  - *indexed*;
  - *request submitted*;
  - *recrawl observed*: Google crawled the page after a recorded request.

  "Unknown" means no evidence yet, not a problem.
- **Timeline.** It merges site audit fetches, the sitemap record, IndexNow submissions (marked as not reaching Google), Google checks with verdict and canonical changes, Google's reported crawls, and recorded requests. Google checks are now kept as history, up to 30 per address.
- **Recorded indexing requests.** A guided handoff opens the address in Search Console URL Inspection. You choose *Request indexing* there and record the day and Google's confirmation here.
  - SEOcc never submits indexing requests and does not use the Indexing API.
  - A recorded request never makes a page "indexed"; only a Google check can show that.
- **MCP.** `indexing_status` also returns the states and the timeline (still 63 tools, read-only).
- **Migration** `2026_10_24_000200`: `google_inspection_history` and `indexing_requests`.

## [0.36.3] — 2026-10-24 — Overlap review: archive words and subtopics

From the 0.36.2 live check (an internal audit):

- **Short and question words count.** The archive check keeps words such as "ph", "f1" and "when". Only joining words ("on", "to", "and") are left out. Before, "tomato soil ph" read as a part of "mulching tomato soil", and "f1 hybrid" as a part of "hybrid tomato".
- **Subtopics rank lower.** A pair whose archives differ only in words common on the site ("trellising" and "tomato trellising") is listed first. A pair where one archive adds words of its own ("yield" and "high yield indoor pepper") is listed after it, with a note that it may be a subtopic and that a parent and a child archive can both be useful.

## [0.36.2] — 2026-10-24 — Overlap review: tighter archive check

From the 0.36.1 live check (an internal audit):

- **Archives splitting a subject.** Two archives count only when the narrower one's words are all in the other ("mold on pepper" inside "mold resistant pepper cultivars"), or when they share most of their distinctive words. Words used in many of the site's archive addresses, such as "outdoor tomato", are not counted. Before, Web Shop got 111 pairs such as harvest and pruning.
- **Evidence.** It quotes the archives' own address words, not folded word stems ("cannabi").
- **Author archives.** The near-duplicate check skips `author`/`authors` pages, which list the same posts as the blog.

## [0.36.1] — 2026-10-24 — Overlap review without listing pages

From the 0.36.0 live check (an internal audit):

- **Listing pages.** The near-duplicate check skips them: paginated copies (`?page=5`, `?page=6`) and tag, topic and category archives. Before, Web Shop's paginated taste and effect listings filled the review as "100 % the same".
- **Caps per kind.** Each kind of overlap is capped on its own (the strongest 100), so near-duplicates no longer crowd out shared searches, synonym ideas and split archives.
- **Counts.** They include every candidate found, and the page says when only the strongest are listed.

## [0.36.0] — 2026-10-24 — Overlap review

Phase 2 of the grounded recommendations plan (ADR-042).

- **Research → Content inventory → Overlap review.** It finds near-duplicate page text (repeated template text excluded), several pages shown for the same searches, article ideas on the same subject in other words, and topic archives that split a subject. Each comes with its evidence and what is uncertain.
- **Not harmful by itself.** Several pages ranking for one search is said to be often fine. When the pages are of different kinds or purposes, the review says they may need to stay separate.
- **Before a merge.** Each page's state, 28-day clicks and impressions, redirect or canonical, and owned topics are shown, with a proposed address to keep and why.
- **Decisions.** People record *Keep separate*, *Merge planned* (with the page to keep) or *Undecided*, versioned. Nothing is merged automatically and SEOcc changes nothing on your site.
- **MCP.** New read-only `overlap_review` tool (63 tools).

## [0.35.1] — 2026-10-24 — Unclassified links say so

From the 0.35.0 live check (an internal audit):

- On *Links of one page*, body links of an audit crawled before 0.35.0 show as *Not classified (crawled before 0.35.0)*, with a note that the next site audit fills in the classes. Before, they read as *Other body link*, as if they had been classed.
- The repeated-template note reads "the same template links on N pages, counted once".

## [0.35.0] — 2026-10-23 — Kinds of internal links

Phase 2 of the grounded recommendations plan (ADR-042).

- **Classes.** Site audits class every internal link: in the text, related-article card, product or cross-sell card, menu/footer/sidebar (breadcrumbs included), or other body link. Each class has a confidence and the signal it came from. A related-article card is never shown as a paragraph link.
- **Links of one page.** Links are grouped by class. A footer or menu link repeated on every page counts once, with the number of pages that carry it.
- **Suggestions.** A card or menu link no longer stops a suggestion for a link in the text. Before suggesting a link, SEOcc checks that the paragraph and the target are about the same kind of thing: "free seeds" in a reward-points paragraph is no longer suggested as a link to the giveaway page. The number of matches left out is shown.
- **What the link evidence covers.** A new box on Internal links shows the crawl size and date, the build date and the limits suggestions are built under. No suggestion does not prove a page is well linked.
- **MCP.** `internal_links` adds `class`, `confidence`, counts by class, `left_out_other_intent` and `coverage`.
- **Run a new site audit to fill in the classes.** Audits crawled before 0.35.0 show links as "Not recorded" or by their old place.

## [0.34.0] — 2026-10-23 — Claims and sources on briefs

Phase 2 of the grounded recommendations plan (ADR-042).

- **Claims and sources** on every content brief. Record each factual claim with:
  - the content it appears in;
  - what it is about (breeder, cultivar, product version, market);
  - the source checked (address, publisher, date, supporting passage or location) and what the source itself is about;
  - your verdict: unreviewed, supported, partly supported, contradicted, outdated or unverifiable, with a note.
- **Automated checks**, shown apart from the verdict and never changing it:
  - a source about another breeder or cultivar than the claim (scope mismatch);
  - a competitor or your own site as the only source;
  - no source;
  - supported without a passage;
  - an unrecorded source scope;
  - a check older than a year.
- **History.** Edits are versioned and every earlier version is kept. Changing the evidence resets an old verdict to unreviewed. SEOcc never rewrites a claim or publishes anything.
- **MCP.** New read-only `brief_claims` tool (paged, filter by verdict; 62 tools).

## [0.33.1] — 2026-10-23 — Inventory checks count articles only

From the 0.33.0 live check (an internal audit):

- *Already in your content inventory* on an article idea or a new brief now needs written content on the topic: an article page, a CMS record, a brief or an article published from an idea. Before, seed product pages made ideas such as *Mortgage lifter cultivar* look covered.
- Shop, category and other pages on the topic are listed as *Other pages on this topic, not articles*, so they are still not mistaken for "no page exists".
- Pages kept in a topic plan or owning a topic no longer count as covering that plan's subject. Before, "Best Outdoor Tomato Cultivars" counted as covering *Mold on pepper* only because it is in that plan.
- Article ideas check the inventory in one pass for all ideas instead of once per idea.
- MCP: `article_ideas` inventory rows add `article` (written content or not).

## [0.33.0] — 2026-10-23 — Content inventory

Phase 1 of the grounded recommendations plan (ADR-042).

- **Research → Content inventory**: every page, draft and planned article in one list. There is one row per address, showing what each source says with its own status and date:
  - latest crawl (redirects, canonicals, noindex, errors);
  - sitemap;
  - Search Console;
  - content briefs and articles published from ideas;
  - topic-plan pages and topic owners;
  - your CMS records.
- **Sources box.** It shows how far each source reaches and when it was last read. A draft is never shown as a public page, and a canonical never as a redirect.
- **CMS records.** Add them by hand or import a CSV export: WordPress statuses are understood, and a known CMS ID updates its record. SEOcc has no CMS connection and stores no CMS credentials.
- **Matching.** Addresses match without tracking parameters, the trailing slash or the http/https difference. Records without an address get *Possible match* suggestions that a person confirms. Edits are versioned so concurrent changes are refused.
- **Article ideas and new briefs check the inventory first.** When a draft or an existing page already covers the main search, the idea says *Already in your content inventory* instead of presenting the article as missing.
- **MCP.** New read-only `content_inventory` tool (paged, filterable; 61 tools). `article_ideas` lists the inventory rows covering each new idea.

## [0.32.1] — 2026-10-23 — Business measurement on complete days

From the 0.32.0 live check (an internal audit):

- Business measurement's period now ends on the last imported GA4 day instead of yesterday. GA4 sessions and business events are judged over complete days, so the sessions figure no longer shows a dash when GA4 is one day behind.
- Search Console clicks next to GA4 sessions show the clicks over the days imported, marked *Partial: 26 of 28 days*. Search Console runs two to three days behind GA4, so before, this almost always showed a dash. A dash now means no day is imported.
- Quest Log: a quest finished before new steps were added shows as in progress again until those steps are done. Before, *Connect Google Analytics* stayed *Completed* and its two new business-event steps looked done. Steps that were already done stay done.

## [0.32.0] — 2026-10-23 — Business measurement

Phase 1 of the grounded recommendations plan (ADR-042).

- **Business events.** *Project → Analytics → Business measurement* lets editors name the GA4 events that count as success (purchase, qualified lead, subscription or sign-up, other; at most ten per project).
- **Daily import.** The daily GA4 import reads each event's count per day, site-wide and in Organic Search sessions. If that report fails, the events show *Import failed* and the landing-page import still completes.
- **Five states.** Each event is *Not configured*, *Unknown*, *Import failed*, *Not observed* or *Observed*, with the reason. Missing or partly imported days are never counted as zero. *Not observed* says it is measurement uncertainty, not zero business value.
- **Sessions next to clicks.** GA4 Organic Search sessions and Search Console clicks for the same dates are shown side by side, with why they never match.
- **Limitations.** A list explains consent, time zones, currency, attribution, privacy thresholds, and that SEOcc cannot see whether your site's tag fires.
- **Test-conversion check.** After you make a test conversion yourself, *Check GA4 now* reads GA4's realtime report (read-only, last 30 minutes) at the next cron run and shows which business events GA4 received. SEOcc never buys, submits or sends anything.
- **Opportunity value.** When GA4 recorded no key events or revenue, opportunity value estimates say "unknown" instead of "0.00 per month".
- **Data quality.** The Google Analytics section shows the business-measurement state.
- **Quest Log.** *Connect Google Analytics* gains two steps: name your business events, and see GA4 record one.
- **MCP.** New read-only tool `business_measurement` (60 tools).

## [0.31.2] — 2026-10-23 — MCP lists reachable page by page

- MCP: `article_ideas`, `topic_plans`, `outcomes`, `lab_tests`, `competitors_list`, `projects_list` and `search_segments` page with `offset` and `limit`, and return `total` and `next_offset`. When a response is cut to fit, `next_offset` counts what was actually returned, so every record stays reachable.
- MCP: when a tool cannot page a list that was cut, the result names that list and says to narrow the filters. Before, every cut response said "advance offset", including on tools without an `offset` parameter.
- Plan and ADR-042 for the next releases: conversion diagnostics, content inventory, claim verification, link classes, overlap review, indexing history, speed diagnostics, measurement plans and change bundles (the grounded recommendations plan).

## [0.31.1] — 2026-10-23 — Tables, button rows and box edges

Follow-up to 0.31.0 (an internal audit):

- Data tables work like GitHub's: click a column heading to sort (numbers high to low first, a third click restores the original order), filter the rows from one search field, and page through long tables with numbered pages. Side-by-side tables page by 10 so both cards stay the same height, and tables no longer scroll inside a fixed box. Grouped tables (Data quality) keep their group headings while filtering, tables with tick boxes never hide a ticked row, and on server-paged lists the filter says it searches this page. Dates and numbers no longer wrap mid-value.
- Every list page's *Previous / Next* pager has the same look, and long lists inside a disclosure (data coverage) are shown in columns.
- Charts share one style in every module: a hairline grid, the figures above the chart as a plain stat strip, and a tooltip that reads in both themes.
- Buttons in one row share their top edge again (for example *Mark done* and *Dismiss* on findings, *Refresh properties* and *Disconnect* on Integrations).
- Action rows on Indexing (*Check again*, *Watch weekly*, *Ask AI*) and Article ideas (*Hide*, *Not relevant*) are level again, and regular and small buttons in one row are the same size (*Look up again* beside *Rebuild ideas now*).
- Lists of saved items (indexing evidence, content briefs) are one box with divider rows instead of separately framed boxes whose edges doubled up.
- Clickable tiles and cards (for example *Owned* on Topics & owners) show a blue border on hover instead of underlining their text. Long words and URLs wrap inside their card instead of running past its edge.

## [0.31.0] — 2026-10-22 — GitHub-style interface

- Signed-in pages now look like GitHub, in dark (default) and light: GitHub's colours and system font, flat boxes with 6px corners and thin borders, green main buttons, blue links, and tabs with an orange underline. Cards that open with a header show it as a grey header strip. The sidebar marks the current page with a blue bar.
- Every page uses the same style: module navigation (Research, Improve, Visibility, Manage) is a tab row, section menus open as GitHub-style menus, filters and toolbars share one corner shape, and dates, inputs and progress bars match. Coloured side stripes on findings, briefs and timeline notes are gone (priority and kind stay on their labels), small labels above headings were removed, and overview section titles read as plain headings. Chart grids use the quiet row-line colour.
- No more empty holes between cards: on settings, account, reports, project settings and integration pages the cards pack into balanced columns, a card with nothing beside it takes the full width, and the overview's *Why it changed* row no longer reserves an empty third column. Short side panels (recent changes, *Record a change*) stay in view while you scroll.
- Setup guides for every integration are reorganized: the steps read as one numbered timeline with each screenshot under its step, and *Confirm the connection*, *Troubleshooting*, examples and official documentation follow as tiles (a sticky side rail on wide screens).
- Buttons, fields and selects share one height (32 px, or 40 px on touch screens), so filter rows line up and small labels no longer sit in tall boxes. Buttons are only as wide as their label (for example *Start investigation*), filter and export buttons are grey instead of green, and *Change market* stays on the same row as its fields.
- Every titled card shows its title in the same grey header strip, including cards with a help button.
- Accessibility and layout fixes from a full UI audit (an internal audit):
  - Text fields without a type (for example *Report brand name* in Reports) now look and size like every other field.
  - *Secondary* buttons are grey default buttons instead of green.
  - Better contrast for the skip link, danger buttons, the current item in section menus and the change-timeline labels.
  - A correct heading order in project cards and the setup guides, and small links and toggles are at least 24 px tall.
- The new look is one stylesheet, `public/assets/css/github-ui.css`, loaded after the existing ones; a white-label stylesheet still loads last and can override it.

## [0.30.2] — 2026-10-19 — Wording and speed-test layout

- The product is called **SEOcc** everywhere in the interface, the user guide and the MCP tool descriptions (before, many places said "SCC"). The licence key format and technical names such as `scc.alert` are unchanged.
- Speed tests: the setup guide, the missing-key notice and the HTTP 403 message now say to enable the PageSpeed Insights API **and allow it in the key's API restrictions** (0.26.0 live check). Before, the Core Web Vitals guide said to restrict the key to the Chrome UX Report API only.
- Speed tests: *Repeat weekly* sits on its own line in the new-profile form, and run dates stay on one line in the runs table.

## [0.30.1] — 2026-10-19 — Publish form layout

From the 0.30.0 live check (an internal audit):

- **Mark as published** sits on its own line below an idea's buttons, and its form opens below it with the labels above the fields. Before, the open form was squeezed into the button row and the labels wrapped.

## [0.30.0] — 2026-10-19 — Prediction check for article ideas

ADR-041, G3:

- **Mark as published** on an article idea, with the article's address (on your site) and date. A content brief whose target search is an idea's main search does the same when it is saved as *Published* with an address. SEOcc keeps the idea's searches and the prediction made at that moment.
- **Published articles** on *Research → Article ideas* compares predicted with measured. It shows:
  - clicks a month: predicted range against the last 4 weeks;
  - time to rank: predicted months against when the main search first reached the top 10 (an average position of 10 or better with at least 10 impressions in a week), or "not yet, N months since publishing";
  - the best weekly position.

  It is measured every day at 06:53 (`article_ideas.track`) from your stored Search Console data for the article's address and searches, for free.
- **Your own time to rank.** Once 3 tracked articles of the same kind (new or update) and difficulty reached the top 10, new estimates for that kind and difficulty use your site's middle-half range instead of the assumption, and say so. The page lists which levels use your results.
- **MCP:** `article_ideas` adds `published` (prediction and measurement per article).
- Migration `2026_10_19_000100` adds `article_idea_tracking`.

## [0.29.1] — 2026-10-19 — Topic plans stay on the subject

From the 0.29.0 live check (an internal audit, finding 16):

- **The subject is the pillar's rarest words.** Each word of the pillar's main search is counted over all the ideas' searches and your Search Console queries. The rarest word, and any other word at most twice as common, define the subject. A plan for "mold on pepper" is now about *mold*: it proposes "mold on chili", searches like "white mold on pepper" and your pages about mold. It no longer proposes bonsai pepper, npk brownies, "strawberry pepper" or your /tastes/ pages. Before, a word only had to be in fewer than a quarter of the ideas' main searches, and on a seed shop "pepper" passed.
- Plans made with 0.29.0 keep their proposals. To get new ones, delete the plan and choose **Plan a topic** again (free).

## [0.29.0] — 2026-10-19 — Topic plans

ADR-041, G2:

- **Plan a topic** on an article idea makes it the pillar of a topic plan. There is one plan per pillar, and creating it is free. The new page *Research → Topic plans* lists the plans. SEOcc proposes, and you keep or drop each one:
  - **supporting articles:** other article ideas on the pillar's subject. Words that appear in a quarter or more of the ideas' main searches (*cultivar*, *pepper*) don't define a subject.
  - **searches to consider:** Search Console queries on the subject that no idea covers. They aren't kept until you keep them.
  - **your pages:** pages that get impressions on the subject.
- **Link map (free, in the background):**
  - pillar ↔ supporting links, with the main searches as anchor text;
  - your pages → pillar;
  - links from your crawled pages to the planned articles, with the paragraph and the words to link (the link suggester on your newest site audit). Add those once the article is published.
- **Backlinks of the top competitor pages (paid, confirmed).** One DataForSEO backlinks summary per page for the pillar's top 2 competitor pages, about $0.05 per plan. It shows their referring domains, backlinks and page rank, and how many referring domains would put the pillar level with the strongest one. A repeat within 30 days needs a second tick.
- **Briefs:** *Create brief* for the pillar and each supporting article (prefilled as in G1), or a plain brief for a kept search. A brief prefilled from a planned idea lists the plan's internal links.
- **MCP:** new read tool `topic_plans` (keywords:read). 59 tools in total.
- Migration `2026_10_18_000500` adds `topic_plans`.

## [0.28.4] — 2026-10-18 — One competitor article, one idea

From the 0.28.3 live check (an internal audit, finding 15):

- **Shared words no longer join articles.** The topic rule is removed, along with its generic-word threshold from 0.28.2. It joined articles whose main searches clustered together and shared a word. On Web Shop it still built a "Cherry pepper plant" idea out of flushing, clones, topping and outdoor-growing posts, because they all share *pepper plant*.
- Articles are now joined only when:
  - they are language or country copies of one address;
  - each ranks in the top 10 for the other's main search;
  - or one wins none of its searches, and is listed with the article that wins its main search.
- Use **Rebuild ideas now** (free) to apply it. Ideas whose main search changes get a new key; read their outlines again if needed (free).

## [0.28.3] — 2026-10-18 — Article ideas join only articles that answer the same search

From the 0.28.2 live check (an internal audit, finding 14):

- **Mutual top-10 rankings.** Two competitor articles are joined into one idea only when each ranks in the top 10 for the other's main search. Before, one article ranking anywhere in the top 20 for the other's main search was enough. Broad posts of a strong site (flushing, topping, clones) reach page two for many grow searches and were joined to the *Cherry* idea.
- An article that wins none of its searches is still listed with the article that wins its main search. Copies in another language folder still count as one article, and the topic rule from 0.28.2 is unchanged.
- Use **Rebuild ideas now** (free) to apply it.

## [0.28.2] — 2026-10-18 — Article ideas stay on one topic

From the 0.28.1 live check (an internal audit, findings 12–13):

- **Generic words no longer merge articles.** Two competitor articles are joined by topic only when their main searches also share a distinctive word. Words that appear in at least 10 % of a lookup's main searches (and in at least 3) are generic for that lookup, such as *pepper* and *plant* on a seed shop. The cherry article no longer pulls in the flushing, topping and clones posts, or their searches.
- **Questions about the idea.** The questions shown on an idea, in its brief and in MCP are those that name a word of the idea's main search, or that two competitor sites ask. "What is flushing?" no longer appears for cherry.
- Use **Rebuild ideas now** (free) to rebuild the ideas with the new merge rule. Questions apply right away.

## [0.28.1] — 2026-10-18 — Outlines from the article itself

From the 0.28.0 live check (an internal audit, findings 8–11):

- **Only the article's own headings.** Outlines now skip:
  - the page header and footer, navigation, asides, dialogs and hidden blocks;
  - cookie, privacy, newsletter, modal, sidebar, comment and related-post containers.

  When an `<article>` or `<main>` holds the page's headings, only its headings count. An embedded privacy policy ("SECTION 1 – PERSONAL INFORMATION…", "1.2 Newsletters") or a "More helpful info" footer is no longer read as subtopics or questions. Page comparisons use the same reader, so their outlines improve too.
- **Subtopics need two sites.** Coverage now counts competitor sites, not pages. Headings a site repeats on its own articles are treated as its template and left out. With one readable site, its own headings are listed.
- **One article per site first.** Outlines read the best article of each site first, then the next best, so subtopics can be compared across sites.
- The brief evidence says "1 search" instead of "1 searches", and how many sites were read.
- Migration `2026_10_18_000400` marks outlines read with 0.28.0 as needing a new read. **Read outlines again** is offered right away (free), with the reason shown.

## [0.28.0] — 2026-10-18 — Competitor outlines and briefs from article ideas

ADR-041, G1 part 2:

- **Read competitor outlines** on each article idea (free). SEOcc reads the idea's top 3 competitor articles, one per article (language copies skipped), plus your page for an *Update*.
  - It uses the same reader as page comparisons: HTTPS only, robots.txt respected, at most two redirects.
  - Only headings, question headings and word counts are kept, never the text.
  - Reads run in the background: one per idea per week (a failed read can be repeated), at most 20 per project per hour.
- **Subtopics.** The idea shows the subtopics at least two competitor articles cover, most widely covered first ("Watering schedule (2 of 2)"). With only one readable article, its headings are shown. For an update, each subtopic is marked ✓ (on your page) or *missing on your page*. Questions answered in competitor headings are listed. The full outline of each article (H2/H3, word count) is under *Competitor outlines*, with the reason when a page couldn't be read.
- **Create brief** on each idea opens a new content brief, prefilled from the idea:
  - the target search and related searches;
  - an outline: H1 = the main search, H2s = the competitor subtopics;
  - topics from question headings, and subtopics missing on your page;
  - for an update, your page;
  - evidence: searches and volume, the estimates, competitor articles with positions, and when the outlines were read.
- **MCP:** `article_ideas` now includes each idea's outline: status, subtopics, questions and competitor headings.
- The content brief's evidence note now says that, at most, competitor headings are stored.
- Migration `2026_10_18_000300` adds `article_idea_outlines`.

## [0.27.3] — 2026-10-18 — Article ideas merge language copies

From the 0.27.2 live check (an internal audit, finding 7):

- **Language and country copies of one competitor article are one idea.** Some sites publish a post at /blog-… and again at /us/blog-…. Addresses that differ only by a leading language or country folder (/us/, /en/, /en-gb/ …) and `www.` now count as one article. Both copies are still listed under *Competitor articles*. "Npk brownies" and "Pepper brownies" are now one idea, and so are "Tomato emoji" and "Pepper emoji".
- Use **Rebuild ideas now** after updating to apply it without a new lookup.

## [0.27.2] — 2026-10-18 — Rebuild article ideas now

- **Rebuild ideas now** on *Research → Article ideas*. It rebuilds the ideas from the stored competitor lookup, against your current rankings and relevance rules, so you don't have to wait for the daily 05:47 rebuild. It is free: nothing is bought from DataForSEO.
  - For editors, once a lookup has finished, and not while one waits or runs.
  - The rebuild runs in the background within a few minutes (`article_ideas.rebuild` for that project only).
  - Repeat clicks within ten minutes queue it once; at most 6 rebuilds per project per hour.
  - Audit event `article_ideas.rebuild_requested`.

## [0.27.1] — 2026-10-18 — Article ideas built per competitor article

From the 0.27.0 live check (an internal audit, findings 1–6):

- **One competitor article, one idea.** Each search now belongs to the competitor article that ranks best for it. Articles are merged when one ranks for the other's main search, or their main searches are one topic. "What is npk" and "What is t h c" (the same article) are now one idea. So are the mortgage lifter pages of two sites. Separate posts of one site ("pepper leaf", "bonsai pepper") are no longer chained into one topic.
- **Close variants count once.** Google often gives close variants one shared search volume. A search with the same volume and the same words (reordered, pluralised, spelled apart or with filler words such as *vs* or *difference between*) no longer adds to the idea's demand and traffic. Every search is still listed, and the reasons say how many variants were counted once. "Slicer vs paste" went from 1,277,300 to about 225,000 a month.
- **Only page one and two count.** Competitor rankings beyond position 20 are ignored. A position-52 ranking no longer makes an idea.
- **Updates need an article.** *Update your article* is offered only when your ranking page is an article or guide. When a shop page ranks (a product or listing), the idea is a *New article*, and the reasons name the shop page, which the article can link to.
- **No second lookup offered while one waits or runs.**
- Ideas are rebuilt from the stored lookup at the next daily rebuild (05:47) or lookup; nothing needs to be bought again.

## [0.27.0] — 2026-10-18 — Article ideas

ADR-041, G1 part 1:

- **New page: Research → Article ideas** (`/article-ideas`). It suggests articles to write or update next, based on the searches your competitors' articles rank for.
  - **Competitors:** your tracked competitors, then the sites seen most often in your stored results pages (marked "found"). Generic platforms such as Wikipedia, YouTube and Reddit are left out. At most 5 per lookup.
  - **Paid lookup, confirmed each time:** up to 500 ranked keywords per competitor from DataForSEO, with the estimated cost shown. Ticking the confirmation is required, and a second tick if the last lookup is under 30 days old. At most 3 lookups per project per hour. The lookup runs in the background.
  - **Articles only:** only competitor pages whose address or type marks them as an article, guide or review count. Searches with fewer than 10 monthly searches, or ruled out by your business profile or keyword ratings, are skipped. Searches are grouped into topics, one idea per topic.
  - **New or update:** an idea is a *New article* when none of your pages ranks within 30. It is an *Update* when one ranks between 4 and 30. Topics where you already rank top 3 are left out.
- **Estimates with ranges and their basis:**
  - traffic (clicks a month at positions 9, 5 and 2, from your click-through curve);
  - difficulty (keyword difficulty, page-one referring domains and your domain's links, with reasons);
  - referring domains that would speed it up;
  - a rough time to rank, labeled as an assumption.
- **Hide or Not relevant.** *Not relevant* also rates the main search as not relevant for future research. Hidden ideas stay hidden when ideas are rebuilt.
- **Free daily rebuild** (`article_ideas.rebuild`, 05:47) from the stored lookup, against your current rankings and relevance rules.
- **Opportunities** shows the top 3 article ideas with a link to the page.
- **MCP:** new read tool `article_ideas` (keywords:read). 58 tools in total.
- Migration `2026_10_18_000200` adds `article_idea_runs`, `article_competitor_rows`, `article_ideas` and `article_idea_dismissals`.

## [0.26.0] — 2026-10-18 — Speed tests

ADR-040, F2:

- **New page: Improve → Speed tests** (`/lab-tests`). Save a test setup once and repeat it, so results stay comparable. A setup is a page, a device (mobile or desktop) and a source:
  - **PageSpeed Insights** (free): uses the workspace's Google API key, the same key as Chrome UX Report, once the PageSpeed Insights API is enabled for it.
  - **DataForSEO Lighthouse** (paid, about $0.005 per run): goes through the workspace budget, and every run needs a confirmation tick.

  Each run keeps Lighthouse's performance score, LCP, CLS, TBT, FCP and Speed Index. The latest 8 runs are listed, with the score change since the previous run. Up to 10 profiles per project and 50 runs per profile are kept.
- **Lab beside field.** Each profile shows the Chrome UX Report field data of the same page and device next to its lab runs. The two are never averaged or mixed.
- **Weekly repeats.** A profile can repeat weekly (`lab_tests.schedule`, hourly at :29). A new PageSpeed profile runs once right away; a paid profile only when you start it, or weekly if you switch that on. Runs happen in the background, never in the page request. A run that gets no result within two hours is marked failed.
- **MCP:** new read tool `lab_tests` (pages:read): profiles with their latest runs and field data, kept apart. 57 tools in total.
- **Link suggestions (finding 14 of the 0.25.4 check):** tag, topic, category, author and archive pages (for example /blog/topics/tomato-seeds) no longer count as the page for a search, so they don't block suggestions such as "free tomato seeds" → /giveaways.
- Migration `2026_10_18_000100` adds `lab_profiles` and `lab_runs`.

## [0.25.4] — 2026-10-18 — Link suggestions from real sentences

From the 0.25.3 live check (an internal audit, findings 11–13). Of 10 suggestions on Web Shop, 6 were wrong:

- **Longer searches go to their own page too.** A search containing every word of a crawled page's address (at least two) belongs to that page: "regular tomato seeds" contains /seeds/regular-seeds. It is no longer matched for the owner of a broader topic. That page can still receive the search itself.
- **Paginated copies are no source.** Pages like /brands?page=2 repeat their first page's text; only the first page gets suggestions.
- **Only sentence-like paragraphs.** Paragraphs need at least 8 words and no SKU-style product code, so product spec lines like "Regular tomato seeds by Valley Seed Company SKU HSC-…" give no suggestions.

## [0.25.3] — 2026-10-18 — Better link suggestions

From the live check after a new Web Shop audit (an internal audit, findings 6–9). Of 10 suggestions, 7 were wrong:

- **No matches across punctuation.** The words of a match must be separated by spaces or a hyphen. "Orchard Seeds (sunflower)" no longer matches the search "seeds sunflower".
- **Pages that compete for a topic get no suggestion for it.** A page that also gets the topic's clicks or impressions (for example /seeds/triploid-seeds in a topic owned by /giveaways) is no longer told to link its own subject to the owner. Which page should own a topic is a question for *Topics & owners*.
- **A search with its own page is left to that page.** When a crawled page's address ends in the search itself (/seeds/triploid-seeds for "triploid seeds", plurals folded), links on those words are not suggested for the owner of a broader topic.
- Source and target pages are shown as their path, so the first column no longer breaks addresses into fragments. The full address is the link and its tooltip.

## [0.25.2] — 2026-10-18 — Link suggestions despite menu links

From the check after a new Web Shop audit (an internal audit, findings 4 and 5):

- **Menu links no longer cancel a suggestion.** Only a link in the body text counts as "already linked". Links in the menu, header, footer or sidebar don't replace a link in the text. On Web Shop, the menu links every page to an owner page, which had removed every candidate.
- **When suggestions were built.** *Internal links* shows when the suggestions of the newest audit were built, or that the background job has not run yet, so "0 suggestions" is no longer ambiguous. MCP `internal_links` adds `suggestions_built_at`.
- Migration `2026_10_17_000300` adds `site_audits.links_built_at`.

## [0.25.1] — 2026-10-17 — Internal links with older audits

From the 0.25.0 live check (an internal audit):

- **Clear message for audits from before 0.25.0.** When the newest completed audit was crawled before anchors and paragraphs were recorded, *Internal links* now says so and offers a new (free) audit. It no longer suggests that your links, topic owners or wording are the reason there are no suggestions. While a new audit is running, the page says suggestions follow after it completes.
- **"Not recorded" instead of "Redirect".** In *Links of one page*, links of such an audit showed "Redirect" under *Where*. They now show "Not recorded", with a note that a new audit fills in anchors, places and paragraphs. "Redirect" is kept for real redirects in newer audits.
- MCP `internal_links` adds `link_text_recorded` (and `audit_running` for suggestions) and explains an old audit in its notice.

## [0.25.0] — 2026-10-17 — Internal links

ADR-040, F1:

- **New page: Improve → Internal links** (`/site-audit/links`). It lists **link suggestions**: a paragraph on one of your pages that mentions a topic (one of its searches, as whole words in order, plurals folded) whose owner page it does not link to yet. Each row shows the source page, the paragraph with the matched words highlighted as anchor, and the owner page with its topic. Stronger topics and pages that already get more internal links come first. There are at most 5 suggestions per topic and 3 per source page. Filter by topic or page address.
- **Links of one page.** Enter a crawled address to see the links into and out of it. Each link shows its anchor text, where it sits (text, menu, header, footer, sidebar) and, for links in the text, the surrounding paragraph.
- **Reword with AI** opens Ask AI with a prepared request to rewrite the paragraph around the link. Nothing is sent until you send it.
- The free crawler now keeps, per internal link, the anchor text and the place on the page; the first link in the text wins over a menu link to the same page. Links in the text keep up to 400 characters of their paragraph, and every crawled page keeps up to 25 text paragraphs. Paragraphs and suggestions are kept for the newest completed audit only. The menu entry appears under Improve.
- Suggestions are built in the background within the hour after an audit completes, and again daily at 04:17 so new topic owners show up without a new crawl. Topics need an owner page (see *Topics & owners*). Nothing is bought.
- **MCP:** new read tool `internal_links` (pages:read): the links of one page, or the suggestions. 56 tools in total.
- Migration `2026_10_17_000200` adds `anchor` and `place` to `site_audit_links`, plus `site_audit_link_contexts`, `site_audit_paragraphs` and `link_suggestions`.

## [0.24.3] — 2026-10-17 — Topics no longer joined by question wording

- **Question frames no longer link two subjects.** Topic grouping counted words like *how long*, *last*, *types* or *how much* as shared core words. On Web Shop, "how long does a mushroom harvest last" landed in the topic of "how long does a pepper harvest last", and from there mushroom keywords chained into the pepper topics. The overlap is now measured on subject words only. A keyword with a single subject word joins another only when subject and question frame both match: "types of pepper" joins "pepper types", but not "how long does pepper last". Shared top-10 results still join searches as before. Saved topics are regrouped at the next opportunity analysis (every 6 hours, or **Analyze now**); the mushroom exclusions in Web Shop's business profile are no longer needed for this, but can stay.

## [0.24.2] — 2026-10-17 — Readable position band

From the 0.24.1 live check (an internal audit):

- **The axis follows the recorded and projected values, not the error band.** On Web Shop the position band reached position 45, because ranking was around 50–65 in July and August. The axis stretched to 50 and squashed the line. The axis now ends just past the recorded and projected values, and a wider band is cut off at the chart edge. The method line then says it is wider than the chart and points to the metric's tile for the full range.
- **The position axis starts at 1**, the best possible position, and runs down in round steps (for example 1, 6, 11, 16, 21), instead of starting at 0.

## [0.24.1] — 2026-10-17 — Prognosis model loads with each release

- **The error band could be missing after an update.** `prognosis.js` loaded `forecast-model.js` without a version, so browsers kept the cached older model and the 0.24.0 band did not appear on the live site. The model is now loaded with the script's own version, like the assistant's modules, and a unit test rejects relative module imports without one.
- With one metric selected and too few backtests for a daily band (CTR on Web Shop), the method line now says so instead of showing nothing.

## [0.24.0] — 2026-10-17 — Clearer performance prognosis

- **Summary sentence.** Above the chart, one sentence says where each metric is heading at the chosen horizon, compared with the average of the last 7 recorded days. For example: "In 14 days, if recent patterns continue: clicks about 28 a day (rising from 25), … average position about 14.1 (worsening from 11.8)."
- **Error band.** With one metric selected, a shaded band around the projection shows how far past forecasts were off for each day ahead: 80 % of the backtests for that day fell inside it. It needs eight backtests per day, never narrows, and its last day is the range shown in the metric's tile. In *All metrics* the method line says to choose one metric to see it.
- **Daily / 7-day average.** A switch draws recorded and projected lines as trailing 7-day averages of the daily values (a day needs five of the seven days), which smooths weekday swings. Latest not-final days and the error band are left out of the averaged view, and the readout says *7-day average*.
- **Latest days now continue the lines.** Google's not-yet-final numbers for the days after the last final day were drawn as loose hollow circles one day past the end of the lines, apart from any line. Each metric's line now continues as a dotted segment with a small dot per day. The legend reads *Latest days, not final yet*, and the hover readout says *Not final yet* and "so far".

## [0.23.1] — 2026-10-17 — Verified outcomes and prognosis chart fixes

Fixes from the 0.23.0 live check (an internal audit):

- **Older outcomes can be verified.** Outcomes recorded before 0.23.0 had no page, so a detected page change could never verify them. Migration `2026_10_17_000100` fills the page from the opportunity's team task, or else from the page in its evidence.
- **Team tasks set the page.** An opportunity completed through its team task is checked against the task's page when one is set, otherwise against the finding's page.
- **Full watchlist.** When a project watches as many pages as its limit allows, *Add many pages* says so instead of offering pages that would be refused.
- *Predicted vs observed* shows **Confirm done** / **Not done** once per completion, on the day-28 row; both checkpoints share the state.
- The page-monitoring intro now says that a new watch is read within the hour.

**Performance prognosis chart:**

- **Today's partial totals were treated as final.** Google does not always name the first day it is still collecting. The day that is still today in Pacific time is now always *still collecting*, both when imported and for rows already stored. On Web Shop, 16 Oct (1 click, 238 impressions so far) had been drawn as a full-strength drop.
- **Days still being collected are no longer drawn** and no longer stretch the scale. They remain in the readout when you hover them. The *Preliminary* legend entry only appears when a final-looking preliminary day is drawn.
- **Position is drawn upside down** in the prognosis, so higher is better on every line. With a single metric selected, the position axis runs from the best value at the top.
- **Recorded changes on consecutive days form one shaded band** instead of a dashed line per day.
- **The hover cursor returns to the last recorded day** when the pointer leaves the chart, instead of staying where it was (for example on the first day, with its four dots on the axis).

## [0.23.0] — 2026-10-17 — Before/after watches and verified outcomes

ADR-039, E2:

- **Watch many pages at once.** *Improve → Page monitoring* gets **Add many pages**: paste up to 200 URLs, one per line, or tick pages from the 15 with the most Google clicks that are not watched yet. The page shows how many pages are watched out of the limit.
- **Before snapshot right away.** A new watch is read within the hour instead of waiting for the weekly pass.
- **Change notes get before and after checks.** Recording a change note for an exact page URL watches that page if it is not watched yet (and the limit allows), reads it now unless it was read in the last day, and reads it again 3 days later. Site-wide notes and addresses outside the project domain are not watched.
- **The watch limit is a workspace setting.** Workspace owners and administrators set it between 1 and 200 pages per project (default 50).
- **Verified outcomes.** *Predicted vs observed* shows whether each completed opportunity was done: *Verified* when page monitoring saw its page change within 14 days of completion, *Confirmed* or *Not done* when an editor says so (**Confirm done** / **Not done**), otherwise *Not verified*. Not-done results leave the estimate calibration.
- **Overlap flags.** When an outcome is measured, other change notes (site-wide or for the same page), other completed opportunities for the same search or page and later detected changes of the page within the window (7 days before completion up to the checkpoint) are listed beside it. The calibration uses only verified or confirmed outcomes without overlaps once a type has five of them.
- **MCP:** new read tool `opportunity_outcomes` (opportunities:read) and write tool `opportunity_outcome_confirm` (opportunities:write, with `expected_implementation`). 55 tools in total. `page_watch_set` reports the workspace's limit.
- Migration `2026_10_16_000600` adds `workspaces.page_watch_limit`, `page_watches.reason` and `after_due_at`, and `opportunity_outcomes.page_url`, `implementation`, `implementation_source`, `implementation_at` and `overlaps`.

## [0.22.0] — 2026-10-16 — Results-page changes

ADR-039, E1:

- **New page: Research → Results-page changes** (`/rankings/changes`). After every rank check, each tracked keyword is compared with its previous check on the same device (at most 14 days older) and the changes are kept for 400 days:
  - result features appearing or disappearing (AI Overview, featured snippet, People also ask, local pack…);
  - tracked competitors entering or leaving the top 10, and whether they came in above you;
  - new URLs of your competitors or your own site in the top 10;
  - you entering or leaving the top 10, or moving 5 places or more;
  - the dominant kind of page in the top 10 shifting (for example from articles to category pages).
  30-day counts, a table of new URLs and keywords gained and lost per competitor, and filters by change, keyword, domain or URL and dates.
- **New alert type: Results pages of tracked keywords changed.** After a rank check, when an AI Overview appears for a keyword where you rank in the top 10, you leave the top 10, or a tracked competitor enters the top 10 above you. It can be muted like the other alert types.
- **MCP:** new read tool `serp_changes` (rankings:read). 53 tools in total.
- A daily pass (`serp_history.catch_up`, 05:27) fills gaps and, the first time, looks back 90 days through the stored checks. Nothing is bought: everything comes from rank checks you already have.
- Migration `2026_10_16_000500` adds `serp_events`.

## [0.21.0] — 2026-10-16 — Investigations

ADR-038, D3 (second of two releases):

- **New: Research → Investigations.** Saved research notebooks per project: the question, dated sources, checks done, limitations, notes and the conclusion (open, concluded or archived). Add a finished *Why they outrank you* analysis with its sources, checks and limitations in one step, a lookup from *Research on file* as a dated source, or an entry by hand with an optional URL and observed date. Start one straight from an analysis with **Start an investigation with it**. Editors change them; everyone who sees the project reads them. Saving over someone else's change is refused.
- **MCP:** `investigations_list` and `investigation_get` (keywords:read); `investigation_save` and `investigation_add` under the new opt-in scope `investigations:write` (*Keep research notebooks*). 52 tools in total.
- **Smaller analysis estimates.** The click estimate of a *Why they outrank you* action now uses the main search's demand plus at most as much again from the topic's other searches, and halves it for a page that has to be created first. On the live site, *Create a category page for “veg seeds”* had claimed about 32,900 clicks a month from the whole topic's demand. Analyses made before this release count 15 % of the topic until they are run again.
- After a refused repeat lookup, keyword research reopens in the market the lookup was started in.
- Migration `2026_10_16_000400` adds `investigations` and `investigation_items`.

## [0.20.0] — 2026-10-16 — Research on file and score breakdown

ADR-038, D3 (first of two releases):

- **No accidental repeat purchases.** A paid lookup that repeats one still current with the same inputs (kind, competitor, market, seeds, prompts, domains; 30 days for research and backlinks, 14 days for the results page of a *Why they outrank you* analysis) is not started. A notice says when it ran, and **Run again and pay** sends the same request once more. Confirmed repeats are marked in the audit log.
- **New page: Research → Research on file.** Every paid lookup the project has (keyword research, gap, competitors, AI visibility, backlinks) with its market, inputs, date, state (*current* or *older*) and rows; the topics analyzed with *Why they outrank you*; and how many tracked searches have a stored results page.
- **One score breakdown** for every opportunity: demand, relevance, impact, confidence, effort, risk and evidence, each with the basis the analysis recorded, and the priority formula. Shown on the opportunity page, as one line per opportunity in reports (page and PDF), and as `score_breakdown` in MCP `opportunities_list` and `opportunity_details`.
- **MCP:** new read tool `research_on_file` (keywords:read), so assistants reuse stored research instead of suggesting the same paid lookup.

## [0.19.2] — 2026-10-16 — Page type and tracked-topic polish

Fixes from the 0.19.1 recheck (an internal audit):

- **Site roots are home pages** whatever their title says, also per country or language (`/us/`, `/en-gb/`).
- **Articles on shops are articles.** `Article` or `BlogPosting` schema without `Product` schema wins over a price and an add-to-cart button in the shop layout.
- Shopify `/blogs/` addresses count as articles, Discourse `/t/…/123` threads as forums.
- **Tracked keywords always take part in topics**, even when the project has much more keyword research than the 2,000 searches the grouping uses.
- **Big topics keep their tracked searches.** A topic stores its top 100 searches plus every tracked one, so its stored results page can still be used for a free analysis.
- Wording: "Create an article", "1 search".

## [0.19.1] — 2026-10-16 — Truer page types and topic links

Fixes from the 0.19.0 live check (an internal audit):

- **Store listings are category pages.** A page with `CollectionPage` schema, `ItemList` schema with prices, or a grid of products (many prices or add-to-cart buttons) is a category or listing page, not a product page. A shop's home page stays a home page. `/product-category/` and `/product-tag/` addresses count as categories. The format verdict and the recommended page type follow.
- **Links only go to the page that owns the topic** (or its main commercial page). Without an owner, the analysis recommends no links and says why, instead of linking to whichever page leads with too little data.
- **Link placement stays rule-based.** The AI no longer rewrites where a link goes; it cannot see the source pages.
- **Smaller, truer link estimates.** An internal-links finding's gain uses the target page's own impressions for the topic, not the whole topic's demand.
- **No invented addresses.** AI text that names an address that was not in the analysis, with or without `https://` (such as a suggested slug), is dropped and the rule-based text is shown.
- **Tracked head terms form topics.** Searches whose stored results pages share at least 3 of the top 10 URLs join one topic even when their words are too common to compare (*tomato seeds* and *organic seeds* on a seed shop), and a tracked keyword forms a topic even alone. Tracked topics can be analyzed for free from their stored results page.
- "1 recommended change" in the singular.

## [0.19.0] — 2026-10-16 — Why they outrank you

- **New: Why they outrank you** for each topic in *Keywords → Topics & owners* (ADR-038, D1). SEOcc looks at the results page for the topic's main search, reads up to five competing pages and yours directly (HTTPS only, robots.txt respected, structure only, never their text) and shows:
  - **What Google wants:** the type of each result (article, product, category, tool, forum, video…) with a confidence, and whether one format dominates, two are mixed or the page is fragmented.
  - **The main bottleneck:** technical, format, content gaps, freshness or unclear.
  - **Up to seven actions** with the competing pages they come from, such as creating a page of the type Google ranks, pairing two pages, adding subtopics the same-type competitors cover, schema, media or a refresh.
- **Free when stored.** A results page stored in the last 14 days is reused; otherwise one DataForSEO lookup runs after you confirm its estimated cost.
- **Opportunities:** the actions of each topic's latest analysis become *competitor gap* or *format mismatch* opportunities with their evidence; Data quality lists the new *competitor analysis* source.
- The analysis runs as the queued job `comparison.analyze`. Migration `2026_10_16_000300` adds `competitor_analyses`.
- **AI explanation (D2).** With the AI assistant set up, the analysis job makes one call to your assistant model with the computed facts (never raw HTML; competitor text is passed as quoted data). It writes the verdict in plain words, a page plan, page-specific instructions for each action, suggested outlines and things to avoid. Anything that names an unknown URL, an action that was not found, or turns a page into another type is dropped; an invalid answer is retried once. Cost is shown before you analyze and charged to the AI budget. Without the assistant, the rule-based result stands.
- **Internal links (D2).** Up to five links per topic from the latest site audit: supporting pages to the main page and back, the category hub, and strong related pages that do not link yet, each with anchors from Search Console queries and where to place it. Under-linked topic pages and pages that compete for the same searches are flagged. Each target page becomes one internal-links opportunity.
- **Client report (D2).** **Client report (PDF)** on the analysis exports a branded PDF (your report logo, colour and footer) with the verdict, results page, actions, page plan, links and subtopic table.

## [0.18.2] — 2026-10-16 — Focused topics

- **Topics are named after a search they still contain.** When an excluded search is left out of a topic, the topic takes its name from its top remaining search instead of the removed one.
- **Unrelated topics are hidden by default.** Topics & owners shows topics with at least one search that names what you offer, or with traffic, rankings or an owner you set. The rest (for example garden or food searches from keyword research) are counted in a note with **Show all topics**. MCP `clusters_list` returns `counts.hidden` and takes `include_unrelated`.
- **Up to 100 excluded terms** in the business profile (offerings stay at 30).
- Found in the 0.18.1 recheck. No migration.

## [0.18.1] — 2026-10-16 — Cleaner topics

- **Pagination variants are one page.** `?page=2`, `?p=3` and `/page/4/` of the same listing no longer compete for a topic or count as overlap; ownership and overlap use the listing without the page number.
- **No owner on too little data.** Under 5 clicks and 50 impressions for a topic, SEOcc names no owner and says so, with the leading page still listed.
- **Off-topic searches are left out of topics.** Searches your business profile rules out (excluded terms, job searches, keywords your team marked not relevant) no longer form or join topics, so food, garden or other unrelated research results stop leading the list.
- Found in the 0.18.0 live check. No migration.

## [0.18.0] — 2026-10-16 — Relevance before paid research, topics and page owners

- **New page: Keywords → Topics & owners.** The opportunity analysis now saves its topics (searches one page can target together) with the page that owns each:
  - **Owned, contested or unowned,** from Search Console clicks (then impressions) for the topic's searches, or from rank checks, with the reason and each candidate page's share.
  - **Set the owner yourself;** your choice stays when the analysis runs again, and SEOcc still shows where Search Console sends the traffic.
  - **Overlap without false alarms:** every search more than one of your pages appears for is labelled *switching pages* (likely harmful), *split* (check intent), *two listings* or *brand search* (both fine), with weekly leaders over 8 weeks.
  - Filter by status or text; topics that vanish keep their manual owner as *no longer found*.
- **MCP:** `clusters_list` and `cluster_get` (keywords:read); `cluster_set_owner` under the new opt-in scope `clusters:write` (Set which page owns a topic).
- Migration `2026_10_16_000200` adds `keyword_clusters`.

- **Markets in the business profile.** List the countries and languages you sell in. Paid keyword research in any other market asks you to confirm (**Run anyway**) before anything is spent.
- **Seeds that cannot fit are left out before paying.** Expand seeds and Keyword ideas drop seeds with an excluded term or a job search, and say which and why. Tick **Run anyway** to include them. If every seed clashes, nothing is queued.
- **Relevance on every research keyword.** Keyword tables show Relevant, Unsure or Not relevant with the reason, hide not relevant keywords by default (with a count and a link to show them) and have a Relevance filter. One click overrides a verdict; the override is the keyword's business-potential rating, so opportunities follow it.
- **MCP:** `keyword_research` returns `relevance` per keyword and takes a `relevance` filter. New write tool `keyword_set_relevance` under the new opt-in scope `keywords:write` (Keyword relevance).
- Migration `2026_10_16_000100` adds `project_business_profiles.markets`.

## [0.17.0] — 2026-10-16 — Data quality

- **New page: Manage → Data quality.** Before you compare periods or trust a number, see which data is current, sampled, stale or missing:
  - **Import completeness:** for the last 90 imported days of Search Console, which days were never imported (listed as dates and ranges), measured zero days, and how far behind the newest day is. Bing shows its span and lag.
  - **Google Analytics 4 coverage:** connection and import state, covered days, row limits and privacy thresholds, and whether key events and purchase revenue were recorded at all. Attribution limits are spelled out.
  - **Checks before comparing:** missing days, limited GA4 reports, organic sessions without key events, timezone differences (Search Console days are Pacific time), and Google versus Bing or GA4 versus Search Console measures.
  - **All datasets** the opportunity engine uses, with the same freshness rules.
- **MCP `data_quality`** returns the same report, including import gaps, GA4 coverage and the checks, so AI clients never mistake a missing day for zero.
- System health no longer calls the MCP endpoint read-only.
- No migration.

## [0.16.1] — 2026-10-16 — MCP polish

- **Clearer answers for AI clients.** A brief or task changed elsewhere now answers in neutral wording instead of the browser's "in another tab" text, and the 50-watch limit for page watches is no longer labelled a conflict.
- **`data_quality`:** a dataset without a date reports `last_date` null instead of an empty value, and the opportunity panel sources (query splits, Bing crawl, indexing, internal links, audit traffic, change notes, page monitoring, alerts, outcomes) have labels, groups and notes. A new `context` group covers the last four.
- **Audit log:** changes made through MCP name the token as the actor.
- No migration.

## [0.16.0] — 2026-10-16 — Writable MCP

- **AI clients can now make changes, if you allow it.** A token or ChatGPT/Claude.ai sign-in can be given write access, separately for each kind of change, in a new **Changes the client may make** section. Nothing is ticked by default, and existing tokens stay read-only.
  - **Change notes:** add and edit notes on the SEO change timeline.
  - **Team tasks:** create and update the work plan of an opportunity (status, assignee, due date, notes, page, linked brief or note).
  - **Content briefs:** create a brief (prefilled from stored data, like the form) and edit it.
  - **Opportunity status:** mark done, dismiss, reopen or snooze.
  - **Page watches:** start or stop weekly checks for up to 50 URLs at once.
- Every change uses the permissions of the user who created the token, is logged in the audit log, and shows "via <token name>" on notes, briefs and tasks. An AI client can never start paid lookups or delete anything.
- Changes made elsewhere in the meantime are never overwritten: the client has to pass the version it read and gets a conflict otherwise. Retries with the same request id never create duplicates. At most 20 changes per minute per token.
- **New read tool `data_quality`:** which datasets of a project are current, stale, sampled or missing, and how to read each, so an AI client checks its evidence before concluding something is zero or declining.
- Ask AI is unchanged: it still asks you to confirm each change in the chat.
- Migration `2026_10_15_000100` adds one table and four columns (ADR-036).

## [0.15.0] — 2026-10-15 — Extras in opportunities and forecasts

- **Opportunities use the new Search Console and Bing data.**
  - **Canonical mismatch:** when Google indexes another URL than the canonical a page declares, its indexing finding says so, shows both canonicals and gets an action plan for settling which URL should rank.
  - **Structured data errors:** pages with rich-result errors in a saved URL Inspection get a finding with the errors and a plan to fix and validate them.
  - **Sitemap problems:** sitemaps Search Console reports with errors or warnings.
  - **Bing crawl and index:** a clear drop in pages in Bing's index over four weeks, or a high share of Bing crawl errors.
  - **Traffic decline on other Google surfaces:** Discover, Google News, News tab, image or video clicks down by at least 30 % (from at least 20 clicks).
  - New filters cover these under *Indexing*, *Search Console* and *Bing*. Each source has its own freshness check, so stale data keeps a finding instead of resolving it.
- **Preliminary days on the Overview prognosis.** Google's early numbers for the days after the last final day appear as hollow markers over the projection; days still being collected are fainter. Hovering a day shows the estimate and the preliminary value side by side. Preliminary numbers never train the forecast.
- **Forecasts for other Google surfaces.** *Forecasts* adds a saved series for Discover, Google News, News tab, image and video clicks when the site had clicks there in the last 28 days. The usual history and coverage requirements apply.
- No migration.

## [0.14.0] — 2026-10-12 — More Search Console and Bing data

- **Latest days from Google before they are final.** Performance shows Google's early numbers for the last two or three days in their own table, with today marked *today so far*. They are replaced by the final figures as Google finalizes each day. Charts, comparisons and opportunities keep using final data only.
- **Discover, Google News, News tab, image and video traffic.** Performance has a new **Other Google surfaces** table with clicks, impressions, CTR and position (where Google reports one) for the selected period and the previous one. Only surfaces with impressions are shown. This traffic is separate from the web search figures.
- **Sitemaps on Google indexing.** Each sitemap Google knows for the linked property, with submitted URLs, errors, warnings and when Google last read it; sitemaps with problems first.
- **More from URL Inspection.** Saved checks now include the sitemaps that list the URL, a sample of pages linking to it, and detected rich results with their errors and warnings. Pages where Google chose another canonical, or whose rich results have errors, are flagged and have their own filters. Results saved before this update show these fields as not reported until the next check.
- **Bing crawl and index data.** The Bing view gains **Crawl & index**: pages in Bing's index and the change in the period, pages crawled, crawl errors and robots.txt blocks, a daily table with status codes, and **Most linked pages** with Bing's inbound link counts.
- All of this is read during the existing imports (seven extra Google requests per totals refresh, two to six extra Bing requests per import). A failure in the new data never stops the main import. Migration `2026_10_11_200000` adds five tables.

## [0.13.1] — 2026-10-11 — Ask AI message box fix

- **Fixed:** the Ask AI message box no longer grows by a line with every keystroke. It now fits the text, grows up to its maximum height, then scrolls, and shrinks again when text is deleted.

## [0.13.0] — 2026-10-11 — Quest Log

- **The Quest Log replaces Get started (all editions).** Learn SEOcc by finishing quests on your own website, in six chapters with a rank each: Foundations (Navigator), Reading the Signals (Analyst), Finding Wins (Strategist), Taking Action (Operator), Proving Impact (Specialist) and Mastery (SEO Lead).
  - **Foundations** is the former setup guide: license, cron, website, Search Console, DataForSEO and budget, keywords, rank tracking and first results, with the same live statuses, hints and paid-work warnings. A step that goes backwards shows **Needs attention** on the chapter and the Quest Log card; the earned rank is kept. Steps your role can't perform show **Handled by your administrator** and don't count against your progress.
  - **22 learning quests** complete when SEOcc can see the work in your data, for example a recorded change, a created brief, a scheduled report or a connected Bing site, and stay complete. Each has a story, what you will learn, steps with **Show me** (opens the page and highlights the place to act), pro tips, common mistakes, the guide chapter and, when the AI assistant is set up, **Ask AI about this quest**. Waiting steps finish on their own; SEOcc re-checks them every hour.
  - Quests only cover features your license includes and work your role can do; nothing is shown locked. When your license gains features, **New quests available** lists them.
  - Skip any learning quest; hide the Quest Log card on the dashboard and project overview (stored for you, no longer only in the browser). No tours, pop-ups, leaderboards or streaks; the only recurring quest is a Weekly Check-in.
  - **Get started** links and **Setup guide** open Foundations for the same website. Viewing the Quest Log never queues work, calls providers or spends money.
  - Guide chapters open inside SEOcc from each quest. New guide chapter: [The Quest Log](docs/guide/26-quest-log.md). Migration `2026_10_11_124355` adds three progress tables.

## [0.12.0] — 2026-10-11 — Ask AI formatting, search filters and change countdowns

- **Ask AI formats answers properly and lets you take them with you.**
  - Answers render headings, tables, numbered and nested lists, quotes and code blocks. Number columns are right-aligned, and long tables scroll with a fixed header.
  - Every answer has **Copy** (the Markdown text) and **Download .md**. Tables have **Copy table** (pastes into Sheets or Excel) and **Download CSV**. **Export** in the chat header downloads the whole conversation as Markdown.
  - The assistant can add a **chart** (line for dates, horizontal bars for categories, negative values for changes) from tool data only, with **Copy data** and **Download SVG**. The chart JSON is validated, so a malformed chart falls back to plain text.
  - A **wider panel** toggle for tables and charts, remembered per browser.
  - A **prompt library** with 47 prompts in eight categories: performance, CTR and snippets, queries and intent, pages and content, countries and devices, rankings and competitors, technical and indexing, plan and report. Selecting one puts it in the message box, so you can change dates or thresholds before sending. **Prompts** in the header opens the library during a conversation.
- **The AI tools can answer filtered search questions exactly.** `search_queries_list` and `search_pages_list` take filters: text the query or URL contains (any or all terms) or must not contain, minimum words, minimum impressions and clicks, CTR and position ranges, new or lost rows, and a sort by clicks, impressions, CTR, position or the change against the previous period. Filters run in the database over every stored Search Console row. Before, the tools only saw the top 500 rows by clicks, so a query with many impressions and no clicks was invisible. Filtered lists include `matching_totals` (for example non-brand totals). The unfiltered top list now reports its real 500-row limit and truncation. A new tool, `search_segments`, returns Search Console clicks, impressions, CTR and position per country or device, with shares and the previous period. Country data is site-wide; the tool says so, and the assistant is told never to present country figures for a single page.
- **The change timeline shows where each change stands.**
  - Counters at the top: recorded changes, waiting for recrawl, in the effect window, ready to review.
  - Each change has a countdown: the phase, the day counter and a track with the signal window typical for its type. For example, a title change usually shows in 7–21 days, internal links in 14–56 days. It also gives the date of the full 28-day comparison and the metric to watch. The windows are planning guidance, not a prediction for the site.
  - After 7 days of imported data, an **early read** compares equal complete Google periods before and after the change (up to 28 days each): clicks, impressions, CTR and position. Changes below 1 % (0.05 points of CTR, 0.1 positions) stay neutral. Incomplete periods are labelled, not compared.
  - Changes are grouped by month on a vertical timeline. The record form and a reference table of the signal windows sit beside the list.
- **Content briefs count the days.** Status counters (draft, in progress, published) sit at the top of the page. Briefs in the queue show how many days they have waited. Published briefs show the same countdown as the timeline: an updated page uses the content window (14–42 days), a new page without a URL 28–90 days. Briefs now store when they were first published; moving a brief back to draft clears the date. Migration `2026_10_11_000100` backfills existing published briefs with their last edit.
- **Priorities are colour-coded from green to red.** Opportunity cards and the overview's **Where to act next** use five fixed priority bands: very low (green), low, medium, high and very high (red). Each band has a legend with its score range. The bands are absolute, so a colour means the same on every page and filter. CTR candidates on the overview are coloured by the impact of their estimated click gap per 30 days.

## [0.11.0] — 2026-10-11 — Opportunity intelligence

- **Opportunities judge each keyword against your business and the results page (all editions).**
  - **Business profile:** under *Opportunities → Business profile*, describe the business: what kind it is, the main goal of search traffic, what it offers, terms that never fit, and your limits for keyword difficulty and competitor strength.
  - **BID check:** every keyword finding then shows **B**usiness potential (0–3, from fixed rules: goal, intent, wording and your offerings), **I**ntent and format fit, and **D**ifficulty you can beat, with a **Target / Consider / Reconsider** verdict. Business potential replaces the generic intent weight in impact. You can rate any keyword yourself on its finding; your rating wins over the rules. New filters: BID verdict and zero-click risk.
  - **Zero-click risk:** each keyword finding gets a high, medium or low estimate from AI Overviews, answer boxes, definition-style wording and CTR far below its position. Risk lowers confidence, never the click estimate, and never hides a finding.
  - **Results-page formats:** rank checks now also keep the latest top 10 of each tracked keyword with titles, at no extra cost. SEOcc classifies them as product, category, review, article, tool, forum and other page types, recommends the format to build, and the action plans start with it.
  - **New findings:**
    - **Clicks falling, ranking stable:** a query whose clicks drop significantly while its position and demand hold. It notes when an AI Overview appeared in between, as context and not as the cause.
    - **Format mismatch:** Google ranks category pages, and your ranking page is a blog post.
    - **Free tool idea:** calculator, checker, generator and similar searches with real demand, grouped into one concept, with a feasibility judgement and a tool brief that lists functional requirements.
    - **Prompt gap:** Gemini and Perplexity both recommend a competitor for one of your AI prompts and mention you in neither.
  - **Topic clusters:** keyword findings name the topic they belong to (shared top results or shared core words), and research tables gain a Topic column.
  - **Competitor strength:** a **Check competitor strength** button rates the domains in your stored results pages (one DataForSEO request, cost shown first). BID uses the result to spot beatable results.
  - **Seeds and modifiers:** a new section on *Keyword research*. The AI assistant can suggest seed keywords and modifiers from the business profile, as words to look up and not data. **Expand seeds** then finds the longer searches containing each seed, with real volume and difficulty. A filter shows the results containing your modifiers, and another shows tool searches. Seeds and modifiers are never multiplied into paid queries.
  - **MCP:** `opportunities_list` returns `bid_verdict`, `business_potential` and `zero_click_risk` and filters by verdict and zero-click risk. Migration `2026_10_10_000300`; decisions in ADR-034 and the ADR-032 amendment.
- **Opportunities:** when the AI assistant is connected, the opportunity cards show **Ask AI** in place of **Create content brief**, so work on a finding goes through the chat. Without the assistant (or without JavaScript) the cards keep **Create content brief**. Briefs can always be created under *Content briefs*, from a keyword or page, and on the opportunity's task page.

## [0.10.5] — 2026-10-10 — Wider pages and a clearer project overview

- **AI assistant → Claude keys that work in several Claude workspaces could not be connected.** Claude answered "This API key is not scoped to a workspace, so this request must include the anthropic-workspace-id header". *Integrations → AI assistant* now has an optional **Claude workspace ID** field (`wrkspc_…`, from Claude Console → Settings → Workspaces). When it is set, the key check, chat and weekly digest send it as `anthropic-workspace-id`. When it is left empty, nothing changes, and GPT keys never send it. If Claude asks for a workspace, the error now says where to find the ID.
- **The project overview is organized by the questions a client asks.** At the top is one row of KPI tiles: the four search metrics plus GA4 sessions, key events and revenue. The search tiles show a sparkline of the period. **Where to act next** now sits beside the performance chart instead of at the bottom of the page. It is a compact ranked list with each query's estimated click gap. Below are two labelled sections. **Why it changed** holds What moved, Recent SEO changes, rankings and landing pages. **Growth & risks** holds traffic risks, demand, competitive and AI visibility, and local. Sources without saved data are listed in one **Not set up yet** strip with a setup link, instead of showing empty cards. In **What moved**, both the Queries and the Pages tables are open by default. The method notes are unchanged but collapsed under **About this data** in each card, so every card leads with its result. In print they are always shown.
- **Every signed-in page now uses the full screen width.** The 1600 px cap is gone everywhere, with narrow side margins. The project header is more compact: the tabs and the Research/Improve/Visibility/Manage menus share one line, and the heading, date range, range picker and Reports share one row on the Overview. Settings, account, new-project and project management pages (Settings, Reports, Change timeline, Search Console, Bing, IndexNow) place short form cards side by side in readable columns. Forms no longer stretch across the whole screen; cards with tables keep the full width. Project subpages have a one-line section bar instead of the large Research/Improve/Visibility/Manage box, and smaller page titles, so content starts higher. On the Overview, the KPI tiles fill each row on laptops. The prognosis card groups its validation, model and method details under one **Forecast details** toggle.
- **The SEOcc WordPress notice is now a small hint in the project header** instead of a full-width card. It keeps the download and setup links, and it can still be hidden per user and project.

## [0.10.4] — 2026-10-10 — AI mention checks for sites with no mentions

- **AI visibility → Check mentions always failed for sites with no AI mentions** ("DataForSEO returned no valid domain mention metrics"). For a domain that no AI answer mentions, DataForSEO returns a complete, billed "Ok." result with empty breakdowns and no `total`. The check rejected that answer, paid about $0.10 and stopped at the first domain. That exact empty answer now counts as 0 mentions, so the check continues to the competitors. Brand-name checks use the same endpoint and get the same fix. Incomplete or malformed metrics are still never recorded as zero.

## [0.10.3] — 2026-10-10 — Fixes from the live DataForSEO test

Found by running every DataForSEO function on the live 0.10.2 installation.

- **Backlinks → Lost page links failed with "Invalid Field: 'order_by'".** Individual backlinks have no `lost_date` field; only referring domains do. Lost page links now sort and filter by `last_seen`, the last crawl that still found the link, and show that date as "Lost on".
- **A rendered page check could stop straight away after the 24-hour review window.** Retrying a failed check with the same settings copied its old progress, including the uncertain step. The check was accepted and then stopped without sending anything. A check whose last step is uncertain now starts over once the window has passed.
- **Rank checks:** a temporary failure no longer hides DataForSEO's reason behind "temporarily unavailable". The reason is shown together with the note that the check will be retried.

## [0.10.2] — 2026-10-10 — DataForSEO 409 fix and shared-hosting hardening

- **DataForSEO lookups stuck with “Error 409: A previous request has an uncertain outcome”:** DataForSEO can refuse a request with HTTP 402 *Payment Required* (or 401) while its body says “20000 Ok.”. The refusal was shown correctly, but the request was still marked “uncertain, may have been billed”. That blocked every repeat of the same lookup with a 409, and nothing in the app cleared it. HTTP refusals are now recognised as unbilled, so the lookup can simply be retried. Lookups already stuck, and genuinely uncertain ones (for example a timeout), can be started again 24 hours after the failed request. Until then the 409 says when. Rendered page checks follow the same rule.
- **Queue claiming:** a worker's claim used to sort every due job across all queues and, with `FOR UPDATE`, lock all of them while it claimed one. When two worker slots polled at the same moment, the second could find nothing and end its run early. A claim now locks only the job it takes, using the new `jobs_claim_order` index. A lock-free check runs first, so a poll of an empty queue is a single index read.
- **Worker slots:** the worker renews its slot lease between jobs, as documented, and stops after the current job if the lease was lost.
- **Scheduler:** recording a slot and queuing its job now happen in one transaction. A failed dispatch no longer uses up the slot silently: the next cron run retries it. One failing task no longer keeps the others from being scheduled.
- **Hourly cleanup:** deletes repeat in batches of 2,000 until the backlog is gone, for up to 60 seconds per run. Before, at most 5,000 rows per table were deleted each hour, so a login flood's rate-limit buckets could pile up. Idle sessions are deleted through an index instead of a table scan. Expired password-reset tokens are now removed one day after expiry.
- **Database connections:** session setup takes one round trip instead of three on every page load and cron run. Connecting gives up after 5 seconds. Cron runs keep their connection alive for at least 10 minutes, so hosts with a short `wait_timeout` cannot drop it during a slow provider call.
- **Outbound HTTP:** requests in one cron run reuse provider connections and TLS sessions instead of a new handshake each time, accept compressed responses, and cache verified DNS answers for 60 seconds. For example, a rank check makes one SERP request per keyword. `Retry-After` in HTTP-date form is now honored (capped at one day); before, only seconds were.

## [0.10.1] — 2026-10-10 — Colour on every page and overview fixes

- **Colour on every page:** one palette shared by all modules, previewed on the live site and contrast-checked on 20 pages in dark and light theme.
  - **Module colours:** each module has its own colour on card top edges, a soft header tint, section labels with a dot, table header underlines, and the module switcher above it. Performance and opportunities are blue, rankings amber, keywords and keyword research green, competitors, backlinks and AI visibility violet, analytics and GA4 teal, site audit coral, page monitoring, indexing, local and the change timeline sky, project settings slate.
  - **KPI tiles:** tiles on every page rotate blue, green, amber and violet, with matching icon badges. Changes against an earlier period show as green or red pills.
  - **Overview:** the evidence cards follow the same colours. The search tiles match the forecast chart's metric colours, and the forecast card has a four-colour top strip.
  - **Readability:** light-theme and print colours keep 4.5:1 text contrast. CSS only; no template changes.
- **Project overview:** with Bing connected, every search tile said "Comparison unavailable", while the Performance page showed the change. Bing reports no row on days without data, so its earlier period was never "complete". The overview now compares on Google's figures in that case and labels the change "Google only".
- **DataForSEO errors:** a failed request could read "…check the account balance): Ok." because the provider's top-level success text was appended. The HTTP status is shown instead. Errors already shown keep their old text until the next lookup.
- **Opportunities → data coverage:** search samples larger than the analysed top 500 rows read "missing" next to "Analysis covers top 500 of 2,416". They now read "partial". Scoring is unchanged.
- **Phones:** project tabs and System tabs that run off the screen now fade at the edge with more tabs, so the row reads as swipeable. This needs a browser with CSS scroll timelines; others are unchanged.
- The **Research →** link on the project overview's *Demand to pursue* card opened a “page not found” error. It now opens keyword research. A new test follows every link on a fully populated overview and checks that it has a route; a scan of all 91 project links built in templates and controllers found no other broken route.

## [0.10.0] — 2026-10-09 — Complete AI access (MCP)

AI clients connected through MCP, and Ask AI, can now read every data family in the dashboard. New read-only tools:

- **`seo_report`:** the weekly or monthly report as data.
- **`forecasts`:** traffic forecasts.
- **`web_vitals`:** Core Web Vitals field data with history.
- **`page_changes`:** watched pages and detected title, description, H1, canonical, noindex and status changes.
- **`browser_audit`:** saved browser and Lighthouse diagnostics.

`ai_visibility` now includes the Gemini/Perplexity answer checks, and `project_monitoring` shows the IndexNow setup and recent submissions (never the key). Existing scopes apply; there is no new scope, no write tool and no provider call. The AI access guide lists all 34 tools with practical questions.

## [0.9.99] — 2026-10-09 — PDF reports

Reports are now available as real PDF files. The report page has a **Download PDF** button, and every report e-mail (scheduled and test) carries the PDF as an attachment next to the existing text and HTML versions. The PDF contains the same sections as the printable report, with the same section choices and the workspace's or installation's branding (name, accent colour, footer, and a JPEG or opaque PNG logo): search KPIs with changes, a daily clicks chart, top pages and queries, rankings, GA4 conversions, site and browser audit, and open opportunities, with page numbers and a link to the online report. SCC writes the PDF itself with the bundled IBM Plex Sans fonts (SIL Open Font License), so characters such as č, ć, ž and đ display correctly. No new dependency or PHP extension is needed, and no migration.

Interactive rendered checks (scrolling, clicking, consent journeys) stay at DataForSEO's documented limits. Its On-Page API offers no scroll, click or step fields, only `custom_js` (700 ms, 2,000 characters) and `disable_cookie_popup`, which SCC already uses. The owner decided on 2026-10-09 not to add a second rendering provider.

## [0.9.98] — 2026-10-09 — Clearer installation access and consistent branding

The launcher is now installer.php, with an inline explanation of where to copy its separate setup code. Default cPanel cgi-bin and .well-known folders are preserved during preparation; existing sites remain protected. The installer uses the default SEO Control Center logo. The public illustrated guide moves to seocc.net/install/, with a redirect from /downloads/ and refreshed customer-portal guidance.

## [0.9.97] — 2026-10-09 — Guided subdomain installation

New installations keep the default subdomain folder created by cPanel. The one-file launcher checks HTTPS routing and private-file protection, verifies the signed package, preserves recognized cPanel PHP handlers and SSL challenges, and prepares the application automatically. Setup access carries into the same browser without another code-copying step. The five-step wizard guides hosting checks, database details, administrator creation and review; its completion page supplies the actual cron path and chosen schedule, which is saved automatically. Non-empty databases are refused before configuration is written. Existing installations keep their current layout and use System → Updates. The downloads website adds a six-step cPanel tutorial with five clearly labeled generated illustrations.

## [0.9.96] — 2026-10-09 — AI answer checks for Gemini and Perplexity

*AI visibility → AI assistants* has a new **Answers to your prompts** section. Enter up to 10 questions your customers might ask and your brand name, and SCC asks Gemini and Perplexity (with web search) through DataForSEO. For each answer it shows whether your brand is named, whether your site is cited, which competitors are named or cited, and the full answer with its sources. Each prompt is answered once per platform; AI answers change between runs, so the result is a sample of today's answers, not a measure of how often you are mentioned. Costs are at most $0.06 per answer (usually much less: DataForSEO charges $0.0006 plus the model's token cost) and are settled to what DataForSEO reports; a retried check never pays again for answers already received. Requires a connected DataForSEO account and permission to run paid analyses. New migration adds the research kind `llm_answers`; no dependency added.

## [0.9.95] — 2026-10-09 — Sharper action plans and growth checks

Action plans for page-level findings (no single query) now refer to “the page’s main search queries” instead of a generic target query.

The site audit's growth checks no longer report later pages of a paginated listing (`?page=2` onwards): they are deep and carry no page-level markup by design. On a real site this removed every "No structured data" report (all were listing pages 2+) and a third of the deep-page reports, leaving only the pages that need links.

## [0.9.94] — 2026-10-09 — Action plans, SEO guardrails and growth checks

**Action plans.** Every opportunity now has an **Action plan**: what to do about it, filled in with the finding's query and page. Each plan says when it applies, what to prepare, what to change (numbered steps), what to keep unchanged, what to measure and when to revert, plus what to avoid and what to consider next. Plans cover every kind of finding, with separate plans for lost links, spammy links, AI Overview citations, AI assistant mentions and Core Web Vitals, and a specific plan for each site audit issue type. They appear on the finding (before the scoring explanation), as the next step on page action plans, on the task page, and in the finding JSON, page-plans JSON and the MCP detail call. Plans never change a finding's impact, value, confidence or order.

**SEO guardrails.** Content briefs now include writing rules for titles and descriptions, and the AI assistant follows the same rules when it suggests titles, descriptions or page changes: keep intent words and useful synonyms, no fixed prices in titles, no keyword stuffing or emoji digits, keep breadcrumbs and location maps, do not change URLs for keywords, use AI text only on specific edited pages, and disavow only after a manual action.

**Growth checks in the site audit.** The audit now records structured data, the Open Graph type and author signals per page and reports three new notices: **No structured data** (with a hint for product and article pages), **Article without an author**, and **Deep in the site structure** (more than three clicks from the start page). They do not lower the health score and appear as opportunities with their own action plans. Pages crawled before the update are not reported. New migration adds three nullable columns to `site_audit_pages`; no dependency or provider call added.

## [0.9.93] — 2026-10-09 — IndexNow key check for subdirectory sites

Settings → IndexNow has an optional **Site path** next to **Check key file**. For a website whose public address is in a subdirectory, such as a WordPress site at `https://example.com/blog/` using SEOcc, SCC now verifies `https://example.com/blog/{key}.txt`. SCC's own manual and sitemap submissions then send that path as `keyLocation` and submit only URLs under it, as IndexNow requires; the sitemap itself may still be anywhere on the host. Paths with dot segments, encoded characters or a query are refused. Root setups are unchanged, and re-checking without a path returns to the root. Queued URLs no longer covered after a re-check are logged, not sent. SEOcc 0.3.1 updates its settings text to match; the download is now `seocc-0.3.1.zip`. New migration adds `indexnow_settings.key_path` (default `/`). No dependency or provider cost added.

## [0.9.92] — 2026-10-09 — Queued Pages exports

Research → Pages now exports more than 10,000 matching URLs. **Prepare CSV export** queues a background export of up to 200,000 URLs in the selected sort order; **Prepared exports** shows progress and offers **Download CSV** when ready. Up to 10,000 URLs still download immediately with the same columns. Jobs write 10,000 rows per chunk, page by sort value and binary URL so rows are never repeated or skipped, resume after a failure from the last recorded chunk, and continue in a new job before the run's time limit. A project prepares one export at a time (six requests per hour). Files are stored under `storage/tmp/page-exports`, outside the web root, available to anyone who can view the project, and deleted after 24 hours by an hourly cleanup task. Requests are audited (`project.pages_export_requested`). New migration `page_exports`; no dependency or provider call added.

## [0.9.91] — 2026-10-08 — SEOcc multisite networks

The SEOcc WordPress companion (0.3.0) now runs in WordPress multisite networks, network-activated or per site. Each site keeps its own key, opt-in, queue and last result, serves its key under its own Home address (subdomain, subdirectory or mapped domain) and notifies only its own URLs. Archived, spam and deleted sites serve no key and send nothing. Network deactivation clears pending notifications on every site except those that activated SEOcc themselves; uninstall removes plugin options and events from every site. Download filename is now `seocc-0.3.0.zip`. No migration, dependency or provider call added.

## [0.9.90] — 2026-10-08 — SEOcc subdirectory Home addresses

The SEOcc WordPress companion (0.2.0) now supports public Home addresses in a subdirectory, such as `https://example.com/blog/`. The key file is served at `{Home}/{key}.txt`, IndexNow `keyLocation` follows that path, and, as the protocol requires, only URLs under the Home path are submitted. Home paths with encoded characters, dot segments, a port or a query are refused. Root-domain sites behave as before. SCC's own Check key file remains root-only; the plugin and the IndexNow setup screen explain that subdirectory sites confirm the key through the plugin's key-file link. Download filename is now `seocc-0.2.0.zip`; the Overview notice dismissal is unchanged. Multisite remains unsupported. No migration, dependency or provider call added.

## [0.9.89] — 2026-10-08 — Page-change history filters

Page-monitoring history now filters saved changes by page URL and significant SEO-setting or page-text category. Search treats percent, underscore and exclamation marks literally. Pagination and CSV downloads preserve filters; selected-change links still open and export that change independently. Counts and empty states describe the filtered results. No migration, dependency or provider call added.

## [0.9.88] — 2026-10-08 — Page-change CSV export

Page monitoring now exports retained change history or one selected change as CSV. One row per changed field includes before/after values, UTC observation times, significance and linked annotation ID. Export includes up to 500 retained changes across history pages, uses existing project-read authorization and spreadsheet formula protection, and makes no provider calls. No migration or dependency added.

## [0.9.87] — 2026-10-08 — Browser image evidence

Browser-rendered audits now retain and display a separate bounded lazy-image declaration sample, including images using placeholder sources. Candidate URLs are distinct from loaded resources and HTTP status evidence; older snapshots show the sample as unavailable. No additional provider calls, dependency or migration added.

Browser resource samples now include CSS backgrounds on ::before and ::after, with distinct evidence kinds. Quoted URLs containing parentheses are preserved. Computed-style reads and resource output remain bounded; references alone do not create broken-resource findings.

Browser audits now show bounded responsive-image candidates from srcset/data-srcset on images and picture sources. URL tokens preserve embedded commas; unsupported schemes and oversized declarations are omitted. This passive evidence does not evaluate descriptors or browser selection, add provider calls or create broken-resource findings.

Relative browser declarations now resolve against the document base URL, including HTML base references to CDN paths. The saved diagnostic displays its reference base. Invalid supplied bases omit relative references rather than inventing addresses; audited page identity and crawl scope remain unchanged.

## [0.9.86] — 2026-10-08 — SEOcc WordPress companion

Project Overview now shows integration managers an optional WordPress notice with the SEOcc download and setup link. Dismissal is remembered per user/project in the browser. Settings → IndexNow retains a permanent description, five installation steps, requirements, opt-in/privacy explanation and download. Viewing/dismissing the notice starts no installation or provider call. No database migration or dependency added.

### Plugin behavior

The companion is named **SEOcc** in WordPress, settings, documentation and download filenames. The plugin ZIP uses the `seocc/` folder and `seocc.php` entry point; existing option/cron identifiers remain compatible.

Added a single-site WordPress companion plugin (0.1.0) that serves SCC's public key file and queues opt-in notifications for public content changes, including previous URLs after permalink changes, unpublishing and deletion. Private/password-protected content, drafts, revisions, autosaves and attachments are excluded. WordPress settings show the last result; transient failures have at most three retries. SCC's IndexNow page provides a permission-checked plugin download. No SCC database migration, provider cost or application runtime dependency added. Plugin requires PHP 8.1+, WordPress 6.5+, root HTTPS Home address and working WordPress routing/cron; target-host verification remains necessary.

## [0.9.85] — 2026-10-08 — Pages CSV exports

Pages now offers **Export all matching URLs**, preserving the applied URL search and sort order across pagination. Downloads include up to 10,000 URLs; larger selections are rejected with guidance to narrow the search, without silently truncating the file. Current-page export remains available. No dependencies, migrations or provider calls added.

### Current-page CSV export

The Pages library can export the current filtered page as CSV in its displayed order, with Google clicks/impressions, Bing clicks and period dates. Missing imported metrics remain empty. Downloads use existing project permissions, spreadsheet-safe encoding and no-store headers. No migration or dependency added.

## [0.9.84] — 2026-10-08 — Setup tutorial audit fixes

Fixed first-result links choosing an empty source, enabled tracking appearing ready without prerequisites, and zero/exhausted budgets appearing usable. Provider credit and sandbox checks now explain blockers. Search setup starts with account connection before property selection; import errors, unreadable properties, missing Google app settings and excluded license features receive appropriate guidance. Saved data from a previously linked property and ranking aggregates without current keywords no longer complete first-result setup. Stopped cron does not imply work is running, and heartbeat timestamps are parsed strictly. The progress card highlights the next required step. No dependencies or migrations added.

## [0.9.83] — 2026-10-08 — First-time setup tutorial

Added a native Get started checklist covering licensing, background tasks, website creation, optional Search Console, DataForSEO and spending limits, keywords, rank tracking and first results. Progress reads current settings, with waiting/error states and permission-aware actions. Dashboard reminders can be dismissed per user/workspace in the browser; the guide stays available in navigation and project headers, and the selected project resumes within the session. Responsive dark/light layouts and mobile overflow verified. Viewing the guide starts no paid work. No dependencies or migrations added.

## [0.9.80] — 2026-10-08 — Research family UI

Pages, page details, Keywords, Keyword Research, Rankings, keyword history, Competitors and competitor comparison now share consistent Research surfaces and category accents. Main lists have clear introductions and section shortcuts; page details use two columns on desktop and one on smaller screens. Cards, filter labels, evidence panels, score tiles and history charts have clearer visual hierarchy. Existing sorting, selection, lookup pricing, tracking/settings forms and permissions are preserved. No dependencies or migrations added.

## [0.9.79] — 2026-10-08 — Opportunities UI and color

Opportunities now separates workflow overview from finding review, with colored status cards and a highlighted current state. Finding cards use type-specific border/tint/badge accents, larger priority scores, distinct impact/confidence bars, and readable evidence disclosures. Report shortcuts are grouped in the header and filters keep labels with their fields. Mobile scores and evidence stack for readability. Dark/light themes checked with no page overflow. Priority calculations, workflow forms, filters, exports and permissions are preserved.

## [0.9.78] — 2026-10-08 — Analytics page organization

Analytics now separates period controls, result cards, landing-page rankings, detailed page results, data quality and property setup. Ranked charts switch between sessions, conversions and purchase revenue with matching metric colors and reported currency. Charts rank each metric independently within the listed top 100 pages; summary and incomplete-comparison safeguards remain visible. Import coverage and attribution context follow the results, and the setup guide stays available below property/history controls. Responsive cards and contained table scrolling verified. No dependencies, provider requests or migrations added.

## [0.9.77] — 2026-10-08 — Performance page organization

Google and Bing performance pages now group period metrics, daily trends, search queries and landing pages into clear sections. Google audience charts have a dedicated section with detailed device/country tables behind a disclosure. Metric colors match KPI cards and chart tabs; wider charts preserve gaps and keyboard inspection, CTR axes show percentages, and SEO change context appears once. Source/date controls share a toolbar, daily details sit below the insights, and the daily-data shortcut opens its table. Responsive cards and contained table/chart scrolling preserve narrow-screen access. No new dependencies or migrations.

## [0.9.75] — 2026-10-08 — Continuous Overview columns

Removed the shared evidence-group boundary. Movers, visibility, outcomes and traffic risks flow down the left column; rankings, research, actions and changes flow down the right. Every card follows its own column's previous card at the existing gap, including after disclosures expand. Tablet and phone retain a single column. No forecast, data, dependency or migration changes.

## [0.9.74] — 2026-10-08 — Remove Overview row gaps

Each Overview group now uses independent vertical stacks inside its two-column grid. Visibility follows movers and research follows rankings without waiting for the adjacent card's height. Outcomes/risks and actions/changes use the same layout, with that entire group below the upper evidence group. Single-column tablet/phone layout retained; no data, forecast, dependency or migration changes.

## [0.9.73] — 2026-10-08 — Overview group order

Performance, rankings, visibility and research now form a separate two-column grid above the outcomes, actions, traffic risks and changes grid. Each group uses row-by-row card order rather than vertical column stacks. Both grids become single columns on tablet and phone. Collapsed prognosis evidence retained; no data or migration changes.

## [0.9.72] — 2026-10-08 — Compact prognosis evidence

Forecast validation and additional-input test explanations now sit in a native, keyboard-accessible disclosure, closed by default. Chart, projected values and brief coverage notes remain visible. Forecast computations and horizon updates are unchanged; no new JavaScript, dependencies or migrations.

## [0.9.71] — 2026-10-08 — Overview evidence grouping

Two independent columns put rankings, competitive/AI visibility and research demand below What moved, with landing-page outcomes above actions and traffic risks in the adjacent column. Short cards and collapsed details do not reserve another card's height. Tablet and phone layouts follow the same groups in a single column; wide movers tables scroll inside their card. Existing data, colour tokens and prognosis remain unchanged. No dependencies or migrations.

## Forecast review and forward validation — 0.9.70 (2026-10-08)

Added a persisted-data forecast review linked from Overview: up to 486 dates, observed query/page samples, GA4 organic sessions/key events, annual patterns and a dated event calendar. Server-generated issue-date snapshots preserve the first prediction and score later imported actuals under unchanged source bindings. Pausable weekly tracking records outcomes daily through bounded cron jobs, preserving accepted actuals beyond source retention. Forward MAE and empirical 80% ranges use completed disjoint forecasts; ranges require 20 observations. Seasonal/event candidates need shared tests and at least 5% lower error; newly entered dates cannot leak into earlier tests. Missing/restricted data stays unavailable. Three additive tables, existing project authorization/CSRF/audit, no provider calls or runtime dependencies. The Overview chart retains its separately labelled prognosis models.

## Improved Performance prognosis — 0.9.69 (2026-10-08)

Forecasting now uses up to 180 persisted Google daily rows independently of the displayed period. Recent averages, linear and weekday models compete on shared rolling historical origins at 7/14/28 days; validation exposes MAE, samples and candidate errors. Position- and impression-assisted CTR inputs are tested using predicted future inputs and adopted only with at least 5% lower error on four shared origins. The impressions × CTR click pipeline is evaluated against the old linear benchmark on shared held-out dates and used only with at least 5% lower error; otherwise the linear-click baseline is retained. Historical endpoint-error envelopes require eight origins; unvalidated short history and sparse/stale/missing data are explicit. Google-only prognosis prevents incomplete Bing histories from distorting forecasts; combined headline totals are unchanged. Existing chart layout/controls retained, with no new dependencies, migrations or provider calls.

## [0.9.68] — 2026-10-08 — Overview colour accents

Restrained existing blue accents identify section headings and the action card. Observed gaining/losing groups use subtle green/warm-orange backgrounds with text labels retained. Top-three/top-ten ranking labels use green/blue chips; lower buckets and unavailable states stay neutral. Estimated click gaps use blue, not success green. Scoped to the Overview decision cards; prognosis and metric computations unchanged.

## [0.9.67] — 2026-10-08 — Overview spacing

What moved now spans the dashboard above actions, rankings and research demand. Independent card stacks remove the blank space left by shared rows with unequal heights, including expanded evidence and short empty states. Mobile layout explicitly constrains its grid and flex children, keeping wide tables inside scrollable cards. Prognosis, data queries and computations are unchanged; no new dependencies or migrations.

## [0.9.66] — 2026-10-08 — Overview dashboard and integration tutorials

### Project Overview evidence dashboard

Recorded zero-impression dates count as covered without a redundant query/page fetch, matching the importer's quiet-day behavior. Positive-impression dates still require completed breakdown imports; absent query/page baselines remain excluded.

Project Overview now connects search movement, observed CTR opportunities, ranking buckets, GA4 organic outcomes, keyword demand, competitors/AI visibility, backlinks, audit/indexing/CrUX evidence and recent SEO changes. Cards disclose periods, coverage, samples and computation assumptions; unavailable sources get honest empty states. Comparisons exclude absent baselines and incomplete imports, and ranking deltas use a shared checked cohort. Balanced mover sampling preserves losses even when hundreds of queries gain. GA4 engagement is not inferred and annotations do not claim causation. All reads use persisted project data; no provider calls, schema changes or new libraries. Trends & outlook remains unchanged. See `docs/overview-dashboard.md` for bounds and formulas.

### Integration tutorial improvements

Search Console, GA4, CrUX and administrator OAuth tutorials add prerequisite checklists, per-step Google setup links and original Google documentation screenshots with source/licence credits. Search Console, GA4 and CrUX guides are available together on Integrations. Shared layouts improve step spacing, image enlargement, keyboard focus and mobile reading. Official demo/training links distinguish sample reports from API connections. GA4 explains service-account property Viewer access and numeric property IDs; CrUX explains outgoing server IP restrictions, mobile/desktop checks and missing field coverage. Four public screenshots are bundled locally; no runtime dependency, schema or credential workflow changes.

## [0.9.65] — 2026-10-07 — GA4 rejection diagnostics

GA4 errors distinguish service-account authentication from report requests and show fixed explanations for recognized OAuth/Google ErrorInfo reasons and statuses, excluding raw provider messages, descriptions and metadata. Retry behavior is unchanged. Updated setup guidance emphasizes exact saved-credential/property matching. Signed installer/updater prepared from verified 0.9.64; no new migrations/dependencies. This diagnoses access failures; it does not resolve the live Google 403. No publication or installation.

## [0.9.64] — 2026-10-07 — CrUX rejection diagnostics

CrUX failures show recognized Google reasons for IP/referrer/API restrictions, disabled APIs and invalid keys through fixed explanations, without raw provider messages or metadata. Updated setup guidance explains server outbound-IP restrictions. Retry and missing-coverage behavior remain unchanged. Signed installer/updater prepared from verified 0.9.63; no new migration/dependency. No publication or live installation.

## Browser crawl and CrUX expansion — 0.9.63 (2026-10-07)

Explicit browser crawls now support 1/10/50/100 pages with resumable resource statuses/waterfalls, five archived diagnostics and browser findings in Opportunities and printable/email reports. Separate mobile Lighthouse tasks use saved IDs and free retrieval. CrUX adds independent desktop history and opt-in weekly page/device watches (52 snapshots; 50 watches/project; 50 due dispatches/hour). Persisted sitemap startup and robots cache perform one HTTP request per step; robots redirects use guarded public HTTPS hops. New forward-only migrations 000059–000061 preserve mobile data and correct the missing rendered_audit ENUM value that caused a live 0.9.62 HTTP 500 before task submission.

Stale-worker failure, cancellation writes, concurrent watch limits, old-domain jobs, malformed progress, empty Lighthouse diagnostics and expired queue leases are guarded. No new runtime dependencies or automatic paid weekly rendering. General scroll/click/consent journeys, complete unloaded CSS traversal and a hard OS-DNS timing bound remain open; live provider rendering remains unverified until this build is installed. This section supersedes the earlier one-page/mobile-only descriptions. Audit: an internal audit


All notable changes to SEO Control Center will be documented in this file.

The format is based on Keep a Changelog, and the project follows semantic versioning.

## [0.9.96] — 2026-10-09 — AI answer checks for Gemini and Perplexity

### Added

- AI answer checks (ADR-032): up to 10 prompts sent on request to Gemini and Perplexity through DataForSEO LLM Responses, using only web-search models from the provider's free model lists; each answer shows brand naming, own-site citation and competitors. Budgeted, settled to the reported cost, resumable per answer. Migration `2026_10_09_000300` adds research kind `llm_answers`.

## [0.9.95] — 2026-10-09 — Sharper action plans and growth checks

### Fixed

- GA4 errors distinguish service-account authentication from report requests and show fixed explanations for recognized OAuth/Google ErrorInfo reasons and statuses. Raw descriptions/messages/metadata remain private; retry policy is unchanged. Local diagnostic patch, not yet packaged or deployed.
- CrUX failures now show allowlisted Google ErrorInfo reasons for blocked IP/referrer/API restrictions, disabled APIs and invalid keys. Raw provider messages, metadata and unknown reasons remain excluded from logs; retry and no-data behavior are preserved. Local diagnostic patch only; the signed 0.9.63 packages are unchanged.
- Site audit: distinguish HTTP 401/403/429 refusals from missing pages; reject late writes after cancellation and recheck deleted projects/current domain/entitlement. Pause on unreachable robots.txt, honor empty bot-specific groups and encoded paths, and avoid cyclic sitemap reads. Fix crawl starvation under the default 50-second cron budget.
- Google CrUX: reject mismatched URL/device/origin records, invalid/future collection dates and entirely missing metrics; preserve explicit URL-normalization evidence and reject future-dated opportunity signals.

- Preserve partial DataForSEO progress across weekly/monthly scheduling, stop unresolved requests from being automatically purchased again, resume when competitor order changes, and reject stale workers through unique queued-request identities. Reject malformed checkpoint rows without sending provider requests.
- Client installation only offers Production and always writes production mode, including altered requests that submit staging.

### Added

- Explicit paid browser-rendered checks for one known project page under Site audit. Submit an asynchronous mobile DataForSEO OnPage task, checkpoint its ID, then retrieve results without another charge. Show rendered title/description/headings, sampled JavaScript links, loaded resources/CSS backgrounds, provider findings and separate lab LCP/CLS. Existing budgets, project permissions, robots/public-host checks and uncertain-charge protection apply. No automatic weekly rendering, schema change or runtime dependency.

- Resume partial DataForSEO domain overviews, backlink profiles, domain/platform mention metrics and AI answer samples from durable per-request checkpoints. Safe queue retries and failed lookups requested again with identical settings skip completed requests, including empty results; retain previous published data until the batch completes. Budget stops retain progress, changed competitors invalidate queued work, and uncertain charges/crashed requests block resumption rather than silently purchasing them again. No migration or dependency change.
- Opt-in weekly monitoring for 50 important pages per project, with complete server-HTML baselines, dated before/after title, description, H1, canonical, noindex and HTTP status evidence, and a bounded paginated change history. Reuse the Site audit crawler, entitlement and background queue; failed or incomplete checks retain successful evidence, stopped/re-added watches invalidate queued work, and domain changes are rechecked.
- Significant HTTP status, canonical and noindex changes feed existing deduplicated personal/team alerts. Explicit annotation creation accepts a reviewed edit date, retains the observation interval and opens the existing impact comparison; repeated actions reuse the linked annotation. Additive migration `000058`; no dependency, paid lookup or automatic website edit.

## [0.9.55] — 2026-10-07 — Performance outlook chart

### Changed

- Added inspection guides, metric value markers and a structured daily readout.
- Clarified recorded history and fitted projections with a boundary, legend and shorter dates; rounded individual metric axis limits.
- Made the entire history and projection fit on phones, with responsive chart geometry and reduced-motion support.

## [0.9.54] — 2026-10-07 — Workspace and administration polish

### Changed

- Unified Integrations, Members, Audit log, Health, Jobs, Backups, Users, License, E-mail, Branding, Updates and Google app with responsive cards, native form controls and contained tables.
- Added permission-aware workspace and system tabs, with the current tab visible on narrow screens, and section navigation for long settings pages.
- Added health-check and queue status cards using existing data.

## [0.9.53] — 2026-10-07 — Research, Improve and Visibility

### Changed

- Unified module pages and subpages with Apple-style cards, readable typography, native controls and responsive tables.
- Added contextual section tabs and an accessible page outline for longer workflows; reduced duplicate navigation on phones.
- Added stored-data diagrams for domain traffic, ranking coverage, audit findings, referring domains, AI Overview citations and local rankings.
- Improved brief editors, review cards and competitor/keyword detail navigation.

## [0.9.52] — 2026-10-06 — Performance, Analytics and Opportunities

### Changed

- Organized Google and Bing performance into selectable metric charts with keyboard-accessible tabs, audience breakdowns, larger metric cards and contained data tables.
- Added scoped GA4 landing-page summaries, imported-date coverage diagrams and leading-page session comparisons. Grouped property and history controls behind a disclosure.
- Redesigned opportunity status summaries, type filters and finding cards with impact/confidence meters, clearer priority and effort, and expandable evidence.
- Kept phone layouts within the viewport, with readable charts that scroll inside their cards, native date controls and touch-sized tabs/actions.

### Fixed

- Preserve missing search imports as chart gaps and unavailable daily table values; keep CTR and position unavailable when impressions are zero.

## [0.9.51] — 2026-10-06 — Dashboard and portfolio design

### Changed

- Refined the shared application shell with system typography, calmer cards, translucent chrome, clearer navigation and a compact Administration disclosure.
- Added a portfolio summary and redesigned project cards with traffic trends, comparable click changes, impressions, CTR, Google position and useful shortcuts.
- Added project search and sorting with responsive layouts, empty states and progressive enhancement.
- Improved the mobile navigation drawer with a dismissal backdrop, keyboard focus handling and Escape support; preserved reduced-motion and transparency preferences.

### Fixed

- Restored recorded change markers and SEO change context in the project prognosis chart.

## [0.9.50] — 2026-10-06 — Project overview and navigation

### Changed

- Reorganized the project overview with clearer metric cards, monitoring summaries and first-page ranking coverage.
- Replaced expanded section groups with a compact toolbar, responsive touch targets, current-page indicators and keyboard navigation.
- Added an animated prognosis for daily clicks, impressions, CTR and Google position, with selectable horizons, individual scales and reduced-motion support. Estimates require recent recorded history and preserve missing days.

## [0.9.49] — 2026-10-06 — Migration and update recovery

### Fixed

- Resume interrupted schema migrations without duplicate tables, columns, indexes, roles or installation identifiers. Recognize exact released-to-repaired migration checksums and reject unexpected changes to applied history.
- Allow recovery with the original signed package after the copied version file changes; preserve maintenance mode and the original backup through failed retries.
- Serialize installation and package publication/discard, and block page bootstrap while update files are being replaced. Invalid uploads retain the previous pending package.
- Create consistent InnoDB backups with collision-safe filenames, protect recovery backups from retention cleanup, and validate gzip integrity before destructive restore statements.
- Reject oversized update metadata, duplicate/conflicting archive paths and symlink destinations; report failed obsolete-file removal and retain the original version in recovery history.

No new database migrations, dependencies or configuration fields. Schema updates remain forward-only; rollback requires matching application-file and database backups.

## [0.9.48] — 2026-10-06 — DataForSEO hardening

### Fixed

- Validate provider tasks, result rows, numeric metrics, response sizes and target identities before saving data; retain previous results when paid responses are malformed or incomplete.
- Harden budget and usage accounting, authentication failures, rate-limit backoff and settlement failures; stop paid retries after partial batch progress or uncertain accounting.
- Recheck queued project domains, competitors and scheduled settings before paid lookups, and prevent overlapping research jobs with a lease.
- Preserve reviews for the same business, expire stale imports before submission and validate free polling results.
- Match provider-decoded keyword text safely and retain unavailable domain figures as unavailable.

### Changed

- AI visibility MCP responses default to ten answers and omit excerpts and sources unless requested, reducing unnecessary response tokens.

No new database migrations or dependency changes.

## [0.9.47] — 2026-10-05 — AI and MCP hardening

### Added

- Add six scoped read-only AI/MCP tools: public-guide listing/search/chunks, project overview, stored GA4 totals/landing pages/daily coverage, and alerts/report/budget monitoring. Package the 24 public guide chapters; never expose internal developer docs or secrets. Extend existing reads with bounded pagination, date ranges, saved audit history, exact backlink targets and optional branch reviews/grids. No new scopes, dependencies, migrations or paid data lookups.

### Fixed

- Correct Claude 5.5 list/cache-read rates and GPT cache-write token accounting. Reserve conservative cache-write costs before chat/digest calls, settle actual usage and preserve historical ledger entries.
- Preserve GPT encrypted reasoning and ordered response items between stateless tool calls; keep provider-specific items out of Claude requests. Reject unreadable successful responses and invalid/missing token usage without silently refunding the local budget. Clearly mark incomplete answers without executing unfinished tool calls.
- Bound large tool results as valid JSON with explicit truncation and actual root/nested/Unicode chapter continuation offsets. Recheck current project and privileged-budget access before replaying history and refresh authorized project lists. Dates outside stored search spans return unavailable data instead of zero totals.

### Changed

- Share provider cooldowns across chat/digest workers, honor Retry-After, add jittered backoff and cap identical failed steps at three attempts/hour. Retries remain explicit. Serialize each user's conversation, validate browser response shapes, refresh after uncertain requests, explicitly resume paused questions and conservatively account for uncertain provider charges.
- Reduce replay to three recent question turns and 90 KB, bound tool results to 16 KB, suppress duplicate reads and cap each response at six tools/eight model steps. Keep existing budget checks before calls and existing write confirmations.

### Documentation

- Record the original post-release AI/MCP audit and subsequent remediation in AUDIT_AI_MCP.md. Actual configured GPT/Claude and external-client live tests remain unverified.

## [0.9.46] — 2026-10-05 — SEO team tasks

### Added

- Add opportunity team tasks with active project-editor assignment, due dates in project timezone, work notes and an exact page. Separate To do/In progress/Done progress survives finding re-analysis. Filter a 25-row queue by status, assignee, overdue work, exact page, title/notes/URL and priority. Atomic revision protection retains unsaved conflict text. Link project briefs and SEO changes, with explicit creation handoffs and impact report links; no automatic site edits or reminders.
- Add read-only Ask AI/MCP seo_tasks_list under opportunities:read. Assignment does not grant access; unavailable members and removed linked records are handled explicitly. Additive migration 000057; no runtime dependency, provider call, new scope or ADR policy change.

## [0.9.45] — 2026-10-05 — Google indexing monitoring

### Added

- Add Google indexing monitoring using the existing read-only Search Console connection: queued manual URL inspections, saved verdict/coverage/crawl/canonical evidence, an opt-in weekly priority watchlist, search/status filters and 25-row pagination. Pages and Ask AI/MCP `indexing_status` read saved evidence only. Failure, unchecked and stale states remain distinct from Google's reported verdict; no live test, indexing submission or automatic site edit.
- Add deduplicated indexing/canonical-change alerts through existing personal and team alert preferences after two successful checks. Bound storage to 500 URLs and 50 watched URLs per project, schedule up to 50 due URLs/hour, and enforce conservative local API budgets. Exact connection/property bindings invalidate old queued work and hide prior evidence after relinking. Additive migration `000056`; no dependency, OAuth scope or ADR policy change.

## [0.9.44] — 2026-10-05 — SEO change impact

### Added

- Add SEO change impact from annotation/page-context links: equal 14/28/90-day windows excluding the edit day, Google clicks/impressions/CTR/average position, exact-page GA4 outcomes, coverage/quality guards, observed direction and other-change context. Missing search rows are never inferred zero. Read-only Ask AI/MCP `seo_change_impact` uses pages:read (plus search:read for site-wide results). No migration, provider call, dependency or causal claim.

## [0.9.43] — 2026-10-05 — Content briefs

### Added

- Add project content briefs: create from an exact page, tracked keyword or opportunity, edit the target/intent/snippets/outline/topics/link plan, filter/search a paginated writing queue, and move work through Draft/In progress/Published. Reuse dated local evidence with visible limits; no paid lookup or automatic website publishing. Optimistic revision checks preserve newer edits and stale submitted text. Private text export, printable view and explicit prefilled SEO annotation handoff complete the workflow.
- Add read-only `content_briefs_list` and `content_brief_details` to MCP/Ask AI under existing `pages:read`; suggested edits are copied and saved manually. Additive migration `000055`; no new dependency, scope, ADR-policy or licensing change. Scheduled PDFs remain deferred.

## [0.9.42] — 2026-10-05 — Brand-name AI mentions

### Added

- Brand-name tracking in AI visibility: ChatGPT and Google AI answer-text matches, up to 25 answer examples per platform, distinct project-domain citation evidence and charts for completed checks. Namesakes, sampled coverage, missing measurements and overlapping domain counts are explicitly explained.
- Manual paid checks and a separate, disabled-by-default weekly opt-in. Four persistent single-request steps use the existing DataForSEO budgets and ledger; duplicate or interrupted deliveries do not silently repeat paid calls. Settings and current project permissions are rechecked before every step.
- Existing Ask AI/MCP `ai_visibility` exposes saved brand context under `rankings:read`, without paid calls or a new scope. One additive migration (`000054`) stores settings and scoped check history. Guide chapter 20 documents setup, costs and failures; scheduled PDF attachments remain deferred.

## [0.9.41] — 2026-10-05 — SEO change annotations

### Added

- SEO change annotations: a responsive project timeline with date and exact-URL filters, pagination, editable dated notes and site-wide changes. Project permissions, CSRF protection and mutation audit logs apply.
- Recorded-date markers and expandable context on overview, Google/Bing performance and keyword-history charts, plus exact-page and site-wide context in Pages. Notes are explicitly context, not proof of causation; sparse checks do not create inferred measurements.
- Read-only `annotations_list` for Ask AI and MCP under the existing `projects:read` scope, with scoped pagination and untrusted-note guidance. One additive migration (`000053`) stores project-owned notes and soft deletion; no provider calls or automated website edits.

## [0.9.40] — 2026-10-05 — Analytics history and client workflows

### Added

- The companion customer center now offers a secure, responsive single-file PHP launcher for fresh installs, with one-hour authorization, separate setup code, verified download/extraction, backup acknowledgment and cPanel handoff to the existing setup wizard. Manual ZIP installation remains available; app runtime, setup locks and licensing policy are unchanged.

- Per-project alert thresholds for traffic drops, minimum previous clicks, ranking cutoff and lost-link domain rank, with the existing defaults and event deduplication preserved.
- Retained GA4 daily history, 28/90/180/365-day imports, date filters and equal-period landing-page comparisons. Imported-day coverage includes empty days; missing dates and limited data never become a fabricated comparison.
- Bounded audit checks for referenced images, scripts, stylesheets and external links: missing image alt attributes, mixed content, 404/410 resources and temporary resource failures, with page evidence in existing audit/opportunity/MCP paths.
- Exact-CID ranking grids for additional local branches (3×3, 5×5 or 7×7), paid cost estimates, resumable point storage, budget enforcement and run-bound jobs.
- Workspace report name, accent, footer and image logo, plus project report-section selection shared by printable, HTML email and plain-text reports.
- Opt-in project alert delivery to Slack incoming webhooks or generic HTTPS JSON endpoints, encrypted URL/token storage, permission rechecks, redacted delivery history and one attempt per event.

### Changed

- Shared outbound HTTP now rejects mixed/private DNS results and pins a verified public address for the connection, with proxy and redirect bypasses disabled. Audits respect cancellation during resource checks. Page GA4 figures use the same reporting period as the page's other metrics.


## [0.9.39] — 2026-10-04 — Native installation experience

### Changed

- Reorganized fresh installation into a dedicated responsive layout: a native section index, readable server-check rows, semantic fieldsets and grouped database/administrator fields. Mobile controls stack without horizontal overflow; no JavaScript is required.
- Refined the completion page with hosting, cron, key-backup and license-activation guidance. Setup-key protection, validation, blank password redisplay and server requirement gates remain unchanged.


## [0.9.38] — 2026-10-04 — Customer center and verified update staging

### Added

- Daily background vendor release checks and signed-package staging for eligible activated installations. Installation admins get a compact cached update badge and a manual Check for updates action; dashboard rendering never contacts the vendor. Packages are size-limited, downloaded over allowlisted HTTPS and verified before staging, without replacing a manual upload.
- Backup notice and required acknowledgment before installing any staged or uploaded release. Installation still uses the existing signed updater, database backup, maintenance mode and recovery journal; cron never installs updates.
- The companion license-server customer center now includes a responsive download dashboard, release notices and a cPanel installation tutorial. Its authenticated release feed respects existing license and release-withdrawal rules.

## [0.9.37] — 2026-10-04 — UI audit polish

### Fixed

- Restored readable insets on the first and last columns of bordered tables, retained dense row spacing and URL ellipsis, and prevented populated Performance reports and 7×7 local ranking grids from overflowing narrow screens.
- Section help now recognizes headings with provider badges and daily impressions charts. Keyboard-scrollable table regions use their section names.

### Changed

- Keyword entry and Search Console suggestions are expandable sections so tracked keywords remain easy to reach. Entry opens automatically for first use and validation errors; bulk selection remains unchanged.

## [0.9.36] — 2026-10-04 — Shared UI and integration guides

### Changed

- Refreshed the shared application UI with consistent cards, statistics, table surfaces and spacing across dark, light and white-label themes. Project navigation groups tools into Traffic, Research, Improve, Visibility and Manage; narrow screens use expandable groups and a compact workspace menu.
- Pages now searches the entire known-URL catalog, sorts by URL or imported Google/Bing traffic, and offers 25/50/100 URLs per page. Search, sort and page size persist in pagination links; literal URL punctuation is escaped in database searches.
- Read-only tables with at least eight loaded rows gain clearly labeled local filtering, sorting and row pagination. Existing server filters remain authoritative; bulk-selection tables retain their existing selection behavior. Tables support horizontal keyboard scrolling on narrow screens.
- Line charts show first/latest/highest displayed values (best position for inverted ranking charts), an expandable underlying data table, clearer empty states and a CSP-compatible value tooltip.

### Added

- Keyboard-accessible help icons explain key statistics, SEO evidence, reporting coverage, settings and background jobs without an AI or provider request.
- Expandable setup tutorials on integration settings cover Google Search Console/OAuth, Bing, DataForSEO, GA4, CrUX, Ask AI, MCP and IndexNow, with verification steps, troubleshooting and official links.


## [0.9.35] — 2026-10-04 — Page improvement recommendations

### Added

- Pages: a prioritized “What to improve” summary for each URL, with stored evidence, next actions and links to the relevant sections. Covers audit issues, significant Google click declines, reachable ranking gains, internal links, possible cannibalization and matched open opportunities. Ask AI and MCP `page_details` return the same recommendations.
- Google page-click comparisons use consecutive 28-day windows only when every day has a completed page import and the exact URL has stored metrics in both windows. Decline candidates require at least 100 previous clicks and a drop of 30% or more; missing data is not treated as zero.

## [0.9.34] — 2026-10-04 — Pages charset compatibility

### Fixed

- Pages: declare UTF-8 explicitly for backlink URLs extracted by `JSON_TABLE`, preventing HTTP 500 when MariaDB uses a Latin-1 database default. Unicode and case-sensitive URLs remain distinct.

### Documentation

- Public website refresh covers the shipped 0.9.33 feature set, with new user-guide chapters for Pages, GA4/CrUX, local branches/reviews and alerts/digest. Website publication is pending.

## [0.9.33] — 2026-10-04 — Pages and SEO integrations

### Added

- **Pages:** a project tab listing every stored URL, with Google and Bing clicks. Each URL brings together its search queries, tracked rankings, latest audit issues, AI Overview citations, open opportunities and sampled backlinks. Ask AI and MCP can read the same evidence with `pages_list` and `page_details` (`pages:read`).
- **Possible cannibalization:** identifies queries shared by several pages in the top 20, with impression thresholds and an explanation of the evidence.
- **Internal link suggestions:** relevant, indexable pages with incoming crawl links that could link to a URL ranking at positions 4–20; existing links and alternate canonicals are excluded.
- **Backlinks by page:** fetch up to 1,000 live links or links lost in the last 30 days. Optional weekly paid lost-link monitoring is off by default, shows its estimated cost, and feeds the existing strong-lost-domain alerts.
- **Core Web Vitals:** fetch Google CrUX mobile field data for a known URL. LCP, INP and CLS, collection dates and no-data status appear in Pages and Site audit; fresh results needing improvement produce opportunities. A free Google Cloud CrUX API key is configured per workspace.
- **Google Analytics 4:** a separate service-account connection imports the latest 28 days of Organic Search landing-page sessions, conversions (key events) and purchase revenue. Results appear in Analytics, page details and reports, with currency, property timezone, privacy and sampling-limit notices.
- **Local branches and review texts:** additional locations retain their own keywords, exact Google Maps CID, check history and optional weekly checks. Paid review imports retrieve up to 100 newest reviews and owner replies for a branch or the primary location; free asynchronous polling never resubmits the paid task.

### Changed

- Search Console imports also collect joint query/page rows for the latest 28 days, including a resumable backfill for existing projects. Page totals remain separate because Google omits anonymized queries from joint results.
- Local MCP reads include additional locations and stored review samples. New integration credentials use row-bound encryption and participate in key rotation.

## [0.9.32] — 2026-10-04 — Alerts and weekly digest

### Added

- **Alerts by e-mail:** checked every hour, each event once. You get an e-mail when keywords drop out of the top 10, when clicks fall by 30% or more in a week, when a site audit finds new errors, when strong backlinks are lost, or when the DataForSEO budget or balance runs low (owners and admins only). Each member only gets alerts for the projects they can see.
- **Weekly digest:** every Monday morning, one e-mail per workspace with each project's week: clicks, top 10 keywords, site health, winners and losers, the top opportunities, the week's alerts and a link to the weekly report. Users can add a short AI summary written by the workspace's AI assistant, which counts against its budget.
- **Notifications page** (*Your account → Notifications*): turn alerts and the digest on or off, choose which alerts you get, mute projects, and see the alerts of the last 30 days.

## [0.9.31] — 2026-10-04 — Report a bug and Discord links

### Added

- **Report a bug and Discord** at the bottom of the sidebar, also on phones. *Report a bug* opens an e-mail with the version, the current page and a short template. *Discord community* opens the SEO Control Center Discord. Both are hidden on white-label installations and can be changed or switched off with `SUPPORT_EMAIL` and `COMMUNITY_URL`.

## [0.9.30] — 2026-10-03 — Ask AI fixes, fix plans and re-audits

### Added

- **Ask AI on every opportunity:** a button on each opportunity opens the chat with that item and asks for the cause and the exact changes, page by page.
- **Exact fixes for technical issues:** for each affected page the AI now sees the current status, redirect target, title, meta description, H1, canonical, noindex and word count from the site audit. It can therefore write replacements per URL (old title → new title) instead of general advice.
- **Fix plans as CSV:** when the AI suggests changes for several pages, it saves them as a fix plan and shows a download link in the chat. The plan lists URL, issue, current value, suggested change and a note, for whoever edits the website. Fix plans are private to the user who asked for them.
- **"I fixed it, check again":** the AI can propose a new site audit, which starts when you click *Confirm*. Audits are free and run in the background.
- The `site_audit` MCP tool returns the same page details for ChatGPT, Claude and other clients.

## [0.9.29] — 2026-10-03 — GPT assistant and ChatGPT sign-in

### Added

- **GPT in the AI assistant:** under *Integrations → AI assistant* a workspace can now choose GPT (OpenAI) instead of Claude: GPT-6.1 Sol (balanced), GPT-6 Astra (most capable) or GPT-6 Luna (fastest, cheapest), with an OpenAI API key. The chat, its tools, the confirmation of changes and the budget work the same; one budget covers either provider.
- **ChatGPT can read your data (AI access):**
  - **Setup:** in ChatGPT's developer mode, add a connector with the server URL from *Integrations → AI access* and choose OAuth.
  - **Sign-in:** ChatGPT opens a sign-in page here; you choose the workspace and the data, then click *Allow*. ChatGPT gets its own token, listed under *AI access* as "ChatGPT (sign-in)" and revocable there.
  - **Claude.ai:** custom connectors work the same way.
- **Back to the page after signing in:** a page that needs a login (for example a ChatGPT sign-in) opens again after you sign in.

### Changed

- AI assistant budgets and spending are kept under one assistant budget, whichever provider is used. Budgets set in 0.9.28 need to be entered again.

## [0.9.28] — 2026-10-03 — AI assistant, spending forecast, smarter opportunities

### Added

- **AI assistant** (*Ask AI*, bottom right of every page): a chat with Claude that answers questions about the workspace's data. It covers performance, keywords, rankings, competitors, opportunities, the site audit, keyword research, backlinks, AI visibility and local SEO, and it knows which project you are looking at.
  - **Reading data:** it uses the same read-only tools as MCP clients.
  - **Opportunities:** it can mark opportunities as done, dismissed or open again, but only after you click *Confirm* in the chat.
  - **Setup:** under *Integrations → AI assistant*, with your own Claude API key (stored encrypted), a choice of model (Sonnet 5.5, Opus 5.5, Haiku 4.5) and daily and monthly budgets.
  - **Persistence:** conversations are kept per user, so the chat continues across pages.
  - **Requirement:** it needs the licence feature `mcp` (AI access).
- **DataForSEO spending in the top bar:**
  - **The chip:** this month's spending and the forecast for the end of the month, with a bar against the monthly budget.
  - **The panel:** today, this month (with requests), the forecast, last month, monthly and daily budgets with what is left, the account balance with how many days it lasts at the current pace, a 14-day chart and the spending per feature (rank tracking, keyword research, AI mentions, local SEO, backlinks).
  - **Who sees it:** members who can start paid analyses.
- **Opportunities from every part of a project:** the analysis now also uses keyword research, backlinks, AI visibility and local SEO, and combines sources:
  - **Quick wins from keyword research:** keywords the site ranks for at positions 4–20 that are not tracked yet, with the effort set by keyword difficulty.
  - **Keyword gaps** (new type): keywords competitors rank for on page one and the site does not rank for at all; the more competitors, the higher the confidence.
  - **Link building** (new type):
    - the referring-domain gap to the competitors;
    - link prospects that link to several competitors but not to you;
    - strong links lost in the last 30 days, to win back;
    - a high spam score.
  - **AI visibility** (new type): AI Overviews that do not cite you although you rank on page one, and AI assistants that mention a competitor far more often.
  - **Local SEO** (new type):
    - local keywords outside the local pack, compared with the top 3 on rating and reviews;
    - an unclaimed Business Profile;
    - too few reviews or a lower rating than the local pack;
    - an incomplete profile.
  - **Smarter existing rules:** site audit issues weigh the Search Console clicks of the affected pages, and quick wins take their effort from the keyword difficulty.

### Changed

- Long values in opportunity evidence (such as URLs) wrap instead of widening the page on phones.

## [0.9.27] — 2026-10-03 — Backlinks

### Added

- **Backlinks** (*Project → Backlinks*), from the DataForSEO link index:
  - **Link profiles** of your domain and your competitors: domain rank (0–100), referring domains, backlinks, the share of nofollow domains and the spam score. *Check every month* keeps a history with the change since the previous check.
  - **Referring domains** (up to 500, sortable by rank, backlinks, spam score or first seen), **new and lost referring domains** of the last 30 days and the most used **anchor texts**.
  - **Link gap:** domains that link to your competitors but not to you, those linking to several competitors first.

  Each lookup is one request (about $0.024 plus $0.000036 per returned row) from the workspace budget and needs the licence feature `backlinks` and the Backlinks API activated in the DataForSEO dashboard.
- **MCP tool** `backlinks` (scope *competitors*): the stored link profiles with history, referring domains, new and lost domains, anchors and the link gap.

### Changed

- The project tabs wrap onto a second row on narrower desktop screens instead of being cut off.

## [0.9.26] — 2026-10-03 — AI history, technical opportunities, MCP tools, ranking grid

### Added

- **Weekly AI mention check with history** (*AI visibility → AI assistants*): *Check every week* runs the mention check for your domain and competitors every 7 days. Each check is kept, and *Your mentions over time* shows ChatGPT and Google AI mentions per check with the change.
- **Site audit issues in Opportunities:** the errors and warnings of the latest completed site audit become opportunities of the new type *Technical issue*, one per issue type, with the number of pages, examples and the advice. They are resolved automatically when a later audit no longer finds them.
- **Site audit in reports:** the report page, the report e-mail and its text version include the latest audit's health score (with the change), pages, errors, warnings and the main issue types.
- **MCP tools** for Claude and other AI clients: `site_audit` (health score, issue types and pages per issue), `ai_visibility` (AI Overviews, AI assistant mentions with history, the questions that mention you), `keyword_research` (stored ranking keywords, gaps, ideas, competitors, domain overview) and `local_rankings` (Maps positions, ranking grids, Business Profile). They read stored data only and work with existing tokens (*rankings*, *keywords* and *opportunities* scopes).
- **Local ranking grid** (*Local → Ranking grid*): your Google Maps position for one keyword at 3 × 3, 5 × 5 or 7 × 7 points around your location, 250 m to 5 km apart, shown as a coloured heatmap with the average position. It costs about $0.002 per point (a 7 × 7 grid about $0.10).

## [0.9.25] — 2026-10-03 — Local SEO

### Added

- **Local SEO** (*Project → Local*):
  - **Setup:** the business name as on Google Maps, a point on the map (coordinates copied from Google Maps) with the size of the area, and up to 50 local keywords such as "plumber" or "pizza near me".
  - **Google Maps positions:** your place in the top 20 Maps results for each keyword at that location, the top 3 businesses with rating and review count, and the change since the previous check.
  - **Local competitors:** the businesses that appear most often in the top 3.
  - **Business Profile:** category, rating, review count, whether the profile is claimed, address, phone, website, photos, and the rating history.

  Checks run in the background, by hand or weekly. They cost about $0.002 per keyword plus $0.005 for the profile from the workspace budget and need the licence feature `rank_tracking`.

### Changed

- The project tabs are slightly more compact, so that all twelve fit next to each other.

## [0.9.24] — 2026-10-03 — AI assistant mentions

### Added

- **AI assistants** on the AI visibility page, from DataForSEO's LLM Mentions data:
  - **Mentions:** how often ChatGPT and Google's AI answers (AI Overviews, AI Mode) mention or cite your domain and each competitor, with the AI search volume behind them;
  - **Questions that mention you:** the most searched questions whose AI answers mention or cite your domain, with an excerpt of the answer, your place among the sources and the other cited sites.

  Checks run in the background and are stored; they cost $0.10 per request plus $0.001 per row (two requests per domain for the mentions, about $0.25 for the questions). ChatGPT data covers the United States in English.

## [0.9.23] — 2026-10-03 — Sortable keyword research

### Added

- **Sorting and filters in keyword research.** Click a column header to sort a table by keyword, volume, difficulty, intent, position, estimated traffic or CPC (click again to reverse); competitor discovery and the domain overview sort by shared keywords, average position, keywords or traffic. Keyword tables filter by text, volume range, maximum difficulty and position range. Sorting and filtering apply to the whole stored result (up to 1,000 rows), not only to the 200 rows shown.

## [0.9.22] — 2026-10-03 — Domain overview fix

### Fixed

- **Domain overview** in keyword research showed only your own domain: DataForSEO's live endpoints take one task per request, so each domain is now looked up in its own request.

## [0.9.21] — 2026-10-03 — Keyword research

### Added

- **Keyword research** (*Keywords → Keyword research*), from the DataForSEO keyword database (DataForSEO Labs):
  - **Domain overview:** organic keywords, top 3/top 10 and estimated traffic of your domain and your competitors;
  - **Your ranking keywords:** up to 1,000 keywords your domain ranks for, including untracked ones;
  - **Keyword gap:** per competitor, keywords they rank for and you do not;
  - **Keyword ideas** from up to 20 seed keywords;
  - **Competitor discovery** with one-click *Add*.

  Tick keywords and click *Track selected keywords* to track them. Lookups run in the background, cost $0.012 plus $0.00012 per returned row from the workspace budget, and are stored, so viewing them again is free. A country and language can be chosen per lookup.

## [0.9.20] — 2026-10-03 — AI visibility

### Added

- **AI visibility** (*Project → AI visibility*): which tracked keywords show a Google **AI Overview**, whether your site is cited as a source (and at which place), which competitors are cited, and the most cited sources, with the change since about a week earlier. The data comes from the rank checks at no extra request; rank checks now ask DataForSEO for the AI Overview's sources, which DataForSEO may bill with a small surcharge.

## [0.9.19] — 2026-10-03 — Clearer duplicate issues

### Changed

- **Duplicate titles and descriptions name the other pages** in the site audit ("Same title as /a, /b and 3 more") instead of only counting them.

## [0.9.18] — 2026-10-03 — Site audit: sitemap and pagination

### Added

- **Site audit reads the sitemap.** At the start, the URLs from the sitemaps listed in robots.txt (or `/sitemap.xml`, including sitemap indexes and `.gz` files) join the crawl, up to 80 % of the page limit. Pages that are only reachable through long listings are now checked too.
- **New notice: *Not linked internally*.** A page from the sitemap that no checked page links to. It is only reported when the audit did not hit its page limit. In *All checked pages*, such pages show "—" as their depth.

### Changed

- **Paginated listings in the site audit:**
  - links beyond page 10 of a series (`?page=`, `?p=`, `?paged=`, …) are no longer followed, so long listings do not use up the page limit;
  - the pages of one series count as one page for duplicate titles and descriptions, reported on its first page.

### Fixed

- A page queued before another page linked to it kept its old click depth (site audit).

## [0.9.17] — 2026-10-03 — Site audit

### Added

- **Site audit** (*Project → Site audit*). It crawls the project's own site in the background and reports:
  - broken pages, server errors and pages that do not answer, with the pages that link to them;
  - redirects and redirect chains, internal `http://` links and pages blocked by robots.txt;
  - missing, duplicate, too long or too short titles; missing, duplicate or too long meta descriptions; missing or several H1 headings;
  - noindex pages, canonicals pointing elsewhere, slow responses (over 2 s), very large pages and pages with little text.

  Each audit gets a **health score** and is compared with the previous one. The page also lists all checked pages and exports the issues as CSV.
- **Crawl behaviour:** the audit stays on the project's domain (with and without `www.`), uses HTTPS, respects robots.txt, refuses private addresses and fetches one page at a time, a batch per cron run.
- **Settings:** 100 to 2,000 pages per audit (default 500) and an optional weekly audit.
- **Licence feature `site_audit`:** included in all editions. Existing installations get it with their next licence refresh (*System → License → Refresh*).

### Changed

- The outbound HTTP client returns response headers and the response time and can stop reading after a byte limit.

## [0.9.16] — 2026-10-03 — White label

### Added

- **White label** (*System → Branding*, installation administrators). The installation can show its own:
  - **product name**, in the sidebar, the browser tab, the sign-in page and reports;
  - **logo**: PNG, JPEG or WebP up to 512 KB (SVG is refused because it can carry scripts);
  - **accent colour**, in light and dark mode and for the button in report e-mails;
  - **report footer** at the end of report pages and e-mails.

  App texts that named the product now use the brand name.

## [0.9.15] — 2026-10-03 — Formatted report e-mails

### Changed

- **Report e-mails are formatted:**
  - **HTML version:** tiles, top pages and queries, rankings and opportunities as tables, with an *Open the full report* button. The plain-text version stays alongside (`multipart/alternative`).
  - **Content:** no images or remote resources, and every value is escaped.
  - **Scope:** password-reset and invitation e-mails stay plain text (ADR-027 amendment).

## [0.9.14] — 2026-10-02 — Scheduled reports

### Added

- **Reports:** a new **Reports** tab per project.
  - **Content:** a week (Monday to Sunday) or a calendar month compared with the period before:
    - Google + Bing search figures with the split;
    - clicks per day;
    - the top 10 pages and queries;
    - rankings (visibility, top 3 and top 10, winners and losers);
    - the open opportunities.
  - **Print / Save as PDF** from the browser, and earlier periods one click away.
  - **E-mail schedule:** weekly (Wednesday 07:00) or monthly (the 3rd, 07:00) in the project's time zone, to up to 10 recipients. Each period goes out once, with a delivery log.
  - **Format:** reports are plain-text e-mails (ADR-027) with the key figures and a link to the full report.
  - **Test send:** *Send a test report to me*.

## [0.9.13] — 2026-10-02 — Combined Google + Bing, IndexNow

### Added

- **IndexNow:** tells Bing, Yandex, Seznam, Naver and the other IndexNow engines about new and changed pages. Set it up under *Settings → IndexNow*:
  - **Key file:** download it, upload it to the site's root and check it.
  - **By hand:** submit up to 1,000 URLs at once.
  - **From the sitemap:** switch on automatic submission. The sitemap is read every 6 hours; the first check records its URLs, and later checks submit new URLs and changed `lastmod` values.
  - **Log:** every submission is listed with its result.
  - **Safety:** sites are only fetched when they resolve to public addresses.
  - **Licence feature:** `bing_webmaster`, now shown as *Bing Webmaster Tools and IndexNow*.

### Changed

- **Overview: one combined Google + Bing view.**
  - **Tiles:** clicks, impressions and CTR add both engines over the same days (the Search Console range), with the split under each tile. Average position stays Google's, labelled so.
  - **Chart and top pages:** *Clicks per day* and *Top pages* combine both engines too.
  - **Dashboard cards:** use the same days for Bing as for Google.
  - **Performance:** keeps Google and Bing apart.
- **Opportunities count Bing too:** Bing's queries and pages are added to Google's per query and URL before the rules run, and evidence shows *Google + Bing*. Rule texts no longer name Google alone.
- **AI access (MCP):** `search_performance_summary`, `search_queries_list` and `search_pages_list` take `source` (`combined`, the default, or `google` or `bing`) and report which engines contributed.

## [0.9.12] — 2026-10-02 — Bing on the overview and dashboard

### Added

- **Bing on the overview and the dashboard.**
  - **Project overview:** a **Bing** row with clicks, impressions and CTR, plus the change against the previous period, under the Google figures. It appears when a Bing site is linked; ranges longer than 6 months show Bing's 6 months.
  - **Dashboard cards:** clicks and impressions add Google and Bing together, with the split underneath ("Google 323 · Bing 13"). Average position stays Google's, because Bing reports no site-wide position per day.

## [0.9.11] — 2026-10-02 — Bing date range fix

### Fixed

- **Bing view with old data:** the date range now ends three days before today, the latest day Bing reports, instead of on the newest day that had data. Bing only sends days with impressions, so a site without recent impressions used to show an old period as if it were current. When the newest Bing data is more than two weeks old, the page now says so.

## [0.9.10] — 2026-10-02 — Bing Webmaster Tools

### Added

- **Bing Webmaster Tools.** Bing's index also powers Yahoo, DuckDuckGo, Ecosia and the web search of ChatGPT and Copilot.
  - **Connection:** *Integrations → Bing Webmaster Tools* takes the API key from Bing Webmaster Tools. It is checked with Bing and stored encrypted, and the account's verified sites are listed.
  - **Project link:** *Settings → Bing Webmaster Tools → Manage link* links a project to one of those sites.
  - **Imports:** run right away, then twice a day, and bring about six months of history. They cover daily clicks and impressions, plus top queries and pages with average positions, which Bing reports per week.
  - **Performance:** the page has a **Google / Bing** switch. The Bing view offers 7 days to 6 months, charts, query and page tables, and CSV export.
  - **Licence feature:** `bing_webmaster`, included in every edition. Installations get it with their next licence refresh once the license server is updated.
  - **Audit events:** `integration.bing.connected`, `integration.bing.disconnected`, `project.bing_linked`, `project.bing_unlinked`.

## [0.9.9] — 2026-10-02 — Install package and customer downloads

### Added

- **Install package:** `release:build` now also writes `scc-<version>-install.zip` for new installations. It holds the same signed files in a `seo-control-center/` folder, plus the empty `storage/` directories. Customers download it, and the update package, from license.seocc.net/download with their licence key.

## [0.9.8] — 2026-10-02 — CSV export

### Added

- **CSV export** on Keywords, Rankings, Opportunities and the Performance query and page tables:
  - The file follows the selected filter, status, type or date range and covers all rows, not only the current page (Performance: up to 5,000 rows).
  - It is UTF-8 with BOM, comma separated, so it opens correctly in Excel.
  - Cells that a spreadsheet would run as a formula (`=`, `+`, `-`, `@`) are prefixed with `'` (CSV injection).
  - Any user who can see the project can export; other workspaces get a 404.

## [0.9.7] — 2026-10-02 — User management

### Added

- **System → Users** (installation administrators, new permission `system.users.manage`):
  - It lists every account with its workspaces, two-factor status and last sign-in.
  - **Deactivate and reactivate:** a deactivated user's sessions end at once, and sign-in, workspace access and AI tokens stop working.
  - **Reset two-factor** sign-in, **send a password-reset link**, and **make admin / remove admin**.
  - **Guards:** no self-deactivation, no removing your own admin rights, and the last active admin always stays.
  - Audit events: `user.deactivated`, `user.reactivated`, `mfa.reset_by_admin`, `user.password_reset_sent`, `user.admin_granted`, `user.admin_revoked`.

## [0.9.6] — 2026-10-02 — E-mail and password reset

### Added

- **E-mail (ADR-027).** *System → E-mail* sets up sending via an SMTP server or the hosting's PHP `mail()`:
  - The SMTP password is stored encrypted.
  - A **Send test e-mail** button checks the settings.
  - The SMTP client is built in: STARTTLS or SSL/TLS, AUTH PLAIN or LOGIN, verified certificates.
- **Forgot password:**
  - A link on the sign-in page sends a one-time reset link, valid for 60 minutes.
  - The response is the same whether or not the account exists.
  - Requests are rate-limited.
  - A reset signs out all of the user's sessions.
- **Invitations by e-mail:**
  - When e-mail is set up, invited people receive their link by e-mail, and existing users get a note that they were added.
  - The link is still shown once to the inviter as a fallback.
- Migration 000023: `mail_settings`, `password_resets`.

### Changed

- *Workspace → Settings* explains up front when a workspace cannot be deleted, instead of showing the delete form.

## [0.9.5] — 2026-10-02 — Account settings and new logo

### Added

- **Your account** (`/account`, opened by clicking your name in the sidebar):
  - Users change their name, e-mail address and password.
  - Changing the e-mail address or the password needs the current password. Wrong attempts are limited to 5 per 15 minutes.
  - A password change signs out all other sessions of the user.
  - Audit events: `account.profile_updated`, `account.password_changed`, `account.password_check_failed`.

### Changed

- The app uses the new SEO Control Center logo in the sidebar, on the sign-in page and as the browser tab icon.

## [0.9.4] — 2026-10-02 — Workspaces in the browser

### Added

- Workspaces in the browser:
  - **Create:** installation administrators click *New workspace* in the sidebar. The licence limit `workspaces` applies, and the creator becomes owner.
  - **Rename:** owners and admins use *Workspace → Settings*.
  - **Delete:** owners use *Workspace → Settings* and type the name to confirm. It is a soft delete: the workspace and its projects are hidden, excluded from access checks, scheduled work and the licence count, and the data is kept. The installation's last workspace, and one the owner has no alternative to, cannot be deleted.
  - Audit events: `workspace.created`, `workspace.renamed`, `workspace.deleted`.
- Migration 000022: `workspaces.deleted_at`.

### Changed

- The licence limit check for new workspaces is shared by the console and the browser (`WorkspaceLimit`).

## [0.9.3] — 2026-10-01 — New license server address

### Changed

- The built-in license server is now `https://license.seocc.net`, with a new entitlement public key (`config/license.php`). The new server was set up fresh: after updating, remove the licence under *System → License* and activate the new key. Installations that set `LICENSE_PUBLIC_KEY` or `VENDOR_LICENSE_API_URL` in `.env` keep their own values; remove those lines to use the built-in ones.
- The Starter edition includes AI access (MCP). All editions now differ by limits only (ADR-026 amendment). This is set by the license server; installations pick it up at the next licence refresh.
- User guide: the edition table, the AI access chapter and troubleshooting reflect this.

## [0.9.2] — 2026-09-29 — Members in the browser

### Added

- *Workspace → Members* manages members in the browser (owners and admins):
  - **Add member** by e-mail with the role Admin, Member or Viewer. An address that already has an account is added right away.
  - Anyone else gets a one-time **invitation link**, shown once and valid for 7 days. Only its SHA-256 hash is stored. There is no mailer, so the inviter sends the link. The invitee chooses a name and password on the invitation page.
  - Open invitations are listed and can be revoked.
  - **Change role** and **remove** members. Owner cannot be assigned. Only owners change or remove owners. Nobody changes their own role or removes themselves.
  - Removing a member also clears their project restrictions in that workspace.
  - Every step is recorded in the audit log: `workspace.member_added`, `workspace.invitation_created`, `workspace.invitation_accepted`, `workspace.invitation_revoked`, `workspace.member_role_changed`, `workspace.member_removed`.
- Migration 000021: `workspace_invitations`.

## [0.9.1] — 2026-09-29 — Release candidate for 1.0: licensing live

### Changed

- The official license server (`https://the former license server`) and its entitlement public key are built in (`config/license.php`). Empty `VENDOR_LICENSE_API_URL` and `LICENSE_PUBLIC_KEY` fall back to them, so installations can activate a licence under *System → License* without editing `.env`.

### Added

- *System → License*:
  - activate a licence key, refresh the licence, and remove it from the installation;
  - removing frees the licence on the license server for another installation, and still works when the server is unreachable;
  - the page shows the edition, valid-until date, grace period, limits, features, licensed domain and installation ID.
- The licence limit `workspaces` (ADR-026) applies when workspaces are created. The first workspace is always allowed.

### Changed

- The licence notices point to *System → License* instead of the command line.

## [0.9.0] — 2026-09-27 — New interface

### Changed

- **New interface ("Night Shift", 0.9):**
  - dark by default, with a light mode toggle in the top bar that is remembered per browser;
  - grouped sidebar with icons, breadcrumbs, and a user block with sign-out;
  - every project page has a header with the domain, the Search Console status and tabs (Overview, Performance, Keywords, Rankings, Competitors, Opportunities, Settings) instead of "Back to project";
  - all colours meet WCAG AA in both modes.
- **Project overview (new):**
  - Search Console KPIs with the change against the previous period;
  - the click trend and top pages;
  - the three highest-priority opportunities;
  - rankings and competitor visibility;
  - a "Get started" checklist until Search Console, keywords, rank tracking and competitors are set up.
- **Dashboard:** one card per project with the last 28 days of clicks (and their change), impressions, average position, a click trend line and the open opportunities.
- **Rankings page:**
  - the numbers come first;
  - the tracking settings fold away;
  - the tracking status and *Check now* are in the header.
- **Charts:** full-width charts are drawn wider, so axis labels keep their size.
- **Fonts:** IBM Plex Sans and IBM Plex Mono, served from the installation itself. No requests to Google or any other font service.
- **Project settings:** the project name, domain and time zone, the Search Console link and deleting the project are now under the Settings tab.

## [0.8.5] — 2026-09-27 — Hosting compatibility and upgrade tests

### Added

- **Hosting compatibility checks** in the installer and in *System health → Hosting*: disabled PHP functions, `open_basedir`, updater write access, upload limit and free disk space (DEPLOYMENT.md).
- **Upgrade tests:** the database of every release since 0.1.0 must upgrade to the fresh-install schema, and 0.7 data must keep working (TESTING.md).

### Fixed

- On hosts that disable `set_time_limit`, `ignore_user_abort`, `chmod` or `ini_set`, backups, updates and error handling crashed. Since PHP 8 a disabled function throws, so these calls are now skipped when unavailable.
- The support bundle failed when `open_basedir` excluded the system temp directory. It is now built in `storage/tmp`.
- `zlib` is required (backups, stored DataForSEO responses) and is now checked by the installer and System health.
- The dashboard no longer says rankings and opportunities are coming in later updates.

## [0.8.4] — 2026-09-27 — License enforcement

### Added

- **License enforcement for licensed features (0.8):** a feature catalog (`search_console`, `rank_tracking`, `keyword_metrics`, `competitors`, `opportunities`, `mcp`, `updates`; ADR-052).
  - **When a feature is missing or the license is not active:**
    - imports, rank checks, metrics fetches, competitor additions, analysis runs, MCP requests and update installs stop with a clear message;
    - queued jobs of that feature are skipped instead of failing;
    - all existing data stays readable.
  - **Notices:** signed-in pages say when the license is not active; administrators also see the grace period.
  - **Development mode is unchanged:** everything is enabled.

### Security

- A license refresh can no longer roll back to an older entitlement (for example to undo a suspension).
- The licensed domain matches with or without `www.`, and regardless of letter case.

## [0.8.3] — 2026-09-27 — Faster long date ranges

### Changed

- **Performance (0.8):** the top queries and top pages on the Search performance dashboard (and in the MCP tools and opportunity detection) load about 9× faster for long ranges. Monthly rollups replace most of the daily rows, and the daily rows are read in primary-key order. With 16 months × 5,000 queries a day, the 16-month view went from 8.1 s to 0.9 s and the 3-month view from 1.5 s to 0.2 s. Results are identical: the rollups are rebuilt after each import, and the daily rows are used until then.

## [0.8.2] — 2026-09-27 — Accessibility

### Changed

- Accessibility pass (0.8):
  - a *Skip to content* link;
  - visible keyboard focus on every control;
  - form errors linked to their fields (`aria-invalid`, `aria-describedby`);
  - form field borders with 3:1 contrast;
  - dark-mode danger buttons with readable text;
  - reduced motion respected.

  `tests/Http/AccessibilityTest.php` checks every page automatically.

## [0.8.1] — 2026-09-27 — Failed jobs page

### Added

- *System → Jobs*: queue counts and failed jobs with their error. Administrators can retry a job (queued again with fresh attempts), retry all or dismiss. Before this, failed jobs were only counted on System health.

## [0.8.0] — 2026-09-27 — Hardening: backups and signed updates

### Added

- `CRON_INTERVAL_MINUTES` for hosts that run cron only every few minutes (Namecheap: 5): the scheduler looks back two intervals, a cron run works up to 4 minutes, and System health expects the configured interval.
- Database backups (0.8, part 1): `php bin/console backup:create|list|restore` and *System → Backups* (create and download, `system.update`). Backups are gzip-compressed SQL in `storage/backups`, streamed table by table without `mysqldump`, restore byte for byte (including encrypted values) and keep the newest `BACKUP_KEEP` (default 7). The key ring is never included. Restore is console-only and needs `--force`.
- System health warns when there is no backup or the latest is older than 7 days.
- Signed updates (0.8, part 2): *System → Updates* installs a release package (`scc-<version>.zip`).
  - **Verification:** before installing, it checks the package's Ed25519 signature, version, PHP requirement, paths and every file hash.
  - **Installing:**
    - takes a backup;
    - switches to maintenance mode (503 except sign-in and the updates page; cron pauses);
    - replaces changed files atomically;
    - removes files the release dropped;
    - runs migrations;
    - records the result in `update_history`.
  - **Failures:** a failed step keeps maintenance mode on and can be retried with the same package.
  - **Building packages:** `php bin/console release:keygen|build` builds signed packages on the vendor machine.
  - **New settings:** `UPDATE_PUBLIC_KEY`, `UPDATE_MAX_UPLOAD_MB`.
- Support bundle (*System health → Download support bundle*): ZIP with health checks, versions, migrations, queue state, allowlisted settings and recent logs, with e-mail addresses and tokens removed. No secrets, key ring or SEO data.

### Changed

- When DataForSEO refuses access, the message no longer claims the password is wrong: it shows DataForSEO's reason (for example an unverified account) and says that *Test connection* is enough once the cause is fixed.

### Security

- Security review of all changes since 0.1.0 (SECURITY.md → review log): no critical or high findings. Backup files are now written owner-only (0600); failed MCP authentication is limited to 30 attempts per minute per IP.

### Fixed

- Rank checks and keyword metrics never ran: a job that asked to renew its lease in the same second it was claimed was told it had lost the job (MySQL reports 0 changed rows), so it re-queued itself without doing any work, up to 100 times per cron run. The worker now verifies the reservation like `LeaseLock` does.
- Continuations of long jobs (rank checks, keyword metrics, Search Console imports) wait for the next cron run instead of spinning in the last seconds of the current one.
- With cron every 5 minutes, scheduled tasks whose minute fell between two runs were never started (hourly cleanup, rank-check scheduling, DataForSEO cleanup, license refresh); the scheduler only looked back 2 minutes.

## [0.7.0] — 2026-09-27 — MCP

Read-only access for AI clients such as Claude through the Model Context Protocol.

### Added

- MCP (0.7): read-only endpoint `POST /mcp` for AI clients such as Claude, with 10 tools for projects, Search Console data, keywords, rankings, keyword history, competitors, competitor comparison and opportunities.
- AI access tokens per workspace (Integrations → AI access): scopes, all or selected projects, expiry, revocation; the token is shown once and stored as a hash; tokens act for their creator and stop working when the creator loses access; 60 requests per minute; every tool call is audited (migration 000018).

### Fixed

- Apache answered 500 instead of 419 when a form expired, because the status line had no reason phrase.
- The `Authorization` header is passed to PHP on CGI/FastCGI/LiteSpeed hosts.

## [0.6.0] — 2026-09-26 — Opportunities

Rule-based, explainable opportunities from stored Search Console, ranking and competitor data. Released together with 0.5.0.

### Added

- Opportunities (0.6, part 1): rule-based, explainable recommendations from stored data, recalculated every 6 hours and on demand (*Analyze now*), no provider cost. Rules: quick wins (positions 4–20), low click-through rate on page one, ranking declines, traffic declines, competitor gaps and content gaps.
- Every opportunity shows its evidence (the data that triggered it) with impact, confidence, effort and a priority score; the list is sorted by priority and filtered by status and type.
- Workflow: open, done, dismissed (both stay as set even if the rule still detects them) and resolved automatically when a rule no longer detects the opportunity; resolved ones reopen when they come back. Every change is kept in a status history and audited.
- Opportunity tables (migration 000017): `opportunities`, `opportunity_status_history`, `project_opportunity_runs`.

### Changed

- A page-one query with a far too low click-through rate is reported only as a low-CTR opportunity, no longer also as a quick win. Positions in opportunity texts are rounded ("10" instead of "10.0").

## [0.5.0] — 2026-09-26 — Competitors

Competitors measured on the rank-check SERPs at no extra cost. Released together with 0.6.0 (no separate tag).

### Added

- Competitors (0.5, part 1): up to 10 competing domains per project, added by hand or from suggestions (domains most often in the top 10 of your keywords); positions recorded from the SERPs rank checks already fetch, so they cost nothing extra; existing checks are used right away from the stored top 10 (ADR-025).
- Competitor comparison: visibility with change, average position, top 3, top 10, keywords where both rank, keywords where the competitor is ahead and keyword gaps (competitor on page one, at least 10 places ahead of you).
- Competitor detail: keyword-by-keyword comparison with your and their position, difference, their ranking URL and filters (gaps, they are ahead, you are ahead).
- Competitor tables (migration 000016): `competitors`, `competitor_rankings`, `competitor_daily`.

## [0.4.0] — 2026-09-26 — Rankings

Opt-in rank tracking with visibility, winners and losers, keyword history and Search Console keyword suggestions.

### Added

- Rank tracking (0.4, part 1): per-project opt-in with device (desktop/mobile) and frequency (daily/weekly), shown with an estimated cost per check; hourly scheduler and *Check now*; one Google SERP check per keyword and day (never paid twice), own domain including subdomains, SERP features and the top 10 results stored (ADR-024).
- Rankings page: visibility (volume-weighted, CTR curve), average position, top 3 and top 10 with change against the check a week earlier, 90-day visibility chart, winners and losers, keyword table with position, change, best position, volume, ranking URL and SERP features.
- Ranking tables (migration 000015): `project_ranking_settings`, `ranking_snapshots`, `ranking_daily`, `project_ranking_sync`.
- Keyword history page (0.4, part 2): position chart (1 at the top) and every check with ranking URL, SERP features and the top 3 domains, plus search volume and the exact query's Search Console clicks and impressions for the last 28 days of data.
- Rankings table filter by latest position (top 3, top 10, 11–20, 21–100, not in the top 100) and links to each keyword's history.
- Keyword suggestions from Search Console: untracked queries of the last 28 days, by clicks, can be added with one click (no provider cost).

### Changed

- The SVG line chart helper moved to `Core\View\LineChart` (shared by Search performance and Rankings) and supports inverted axes.

## [0.3.0] — 2026-09-26 — DataForSEO

Keyword metrics from the workspace's own DataForSEO account, completing the DataForSEO connection and budgets released with 0.2.0.

### Added

- Keywords per project (0.3, part 2): add up to 1,000 keywords per project for a country and language, remove keywords, table with search volume, CPC and competition, sorted by volume.
- Keyword metrics from DataForSEO (Google Ads data), fetched in the background after adding keywords or with *Update metrics*; one request per 1,000 keywords and market, metrics reused for 30 days, workspace budgets enforced, status and errors shown on the Keywords page. Metrics are stored per workspace (ADR-023).
- Provider contracts `KeywordMetricsProvider` and `SerpProvider` with DataForSEO adapters (SERP collection foundation for rank tracking).
- Keyword tables (migration 000014): `project_keywords`, `keyword_metrics`, `project_keyword_sync`.

### Fixed

- Stylesheet and script URLs carry a version, so browsers load updated styles after a deploy instead of a stale cached copy.

## [0.2.0] — 2026-09-26 — Google Search Console

Search Console connection, background imports and the first organic performance dashboard. Also contains the first part of 0.3 (DataForSEO connection and provider budgets); DataForSEO requests that cost money arrive with keyword metrics in 0.3.0.

### Added

- Google Search Console connection (0.2, part 1): installation-level OAuth client settings, workspace Google connections with encrypted tokens (PKCE, single-use state), automatic token refresh and "needs reconnection" handling, property discovery, project ↔ property linking, disconnect with revocation, audit events.
- Allowlisting HTTPS client for provider adapters.
- Web installer (`/install`) protected by a setup key: server check, `.env` writing, key ring creation, migrations, administrator and workspace creation, then locked.
- `staging` environment: production-like, but runs without a license (ADR-021 amendment).
- System health shows pending database migrations and lets administrators apply them (for hosts without SSH).
- cPanel deployment guide (DEPLOYMENT.md).
- Search Console imports (0.2, part 2): background import of daily totals and per-day query, page, device and country data for up to 16 months, newest days first, in cron-sized steps with a per-project lock and continuation jobs; scheduled sync every 6 hours; import starts on linking; *Import now*; changing a project's property replaces its data.
- Search performance dashboard per project: clicks, impressions, CTR and average position with change vs the previous period, daily charts with hover and a table view, top queries, pages, devices and countries; ranges from 7 days to 16 months.
- Search performance tables (migration 000012) and `DateRange` value object.
- DataForSEO connection (0.3, part 1): workspace API credentials verified with DataForSEO and stored encrypted (included in key rotation), balance display, connection test, disconnect, audit events; optional free sandbox mode (`DATAFORSEO_SANDBOX`).
- Provider cost accounting and budgets: daily, monthly and per-request limits per workspace in USD; every paid request is reserved against the budget before it is sent and settled with the provider-reported cost; usage for today and this month on the DataForSEO page.
- Raw provider responses kept compressed for 7 days (`DATAFORSEO_RAW_RESPONSE_DAYS`) with hourly cleanup, which also closes reservations left open by crashed workers.
- DataForSEO tables (migration 000013): `dataforseo_profiles`, `api_budgets`, `api_usage`, `provider_responses`.

### Changed

- `.env` parsing now supports backslash escapes inside double quotes.

## [0.1.0] — 2026-09-26 — Foundation

Foundation release: no SEO features yet. Known gaps, planned before 1.0: web installer, signed updater (UPDATES.md), sanitized support bundle, e-mail (password reset, invitations), vendor license control plane.

### Added

- Foundation slice 1 (toward 0.1.0):
  - Composer project with PHPUnit, PHPStan (level 8) and PHP CS Fixer configuration.
  - `.env` loading and typed, immutable configuration; debug output can never be enabled in production.
  - In-house PSR-11 container with explicit wiring (`bootstrap/app.php`).
  - HTTP kernel: PSR-15 middleware pipeline, FastRoute-backed `Router`, request IDs, security headers with per-request CSP nonce, safe HTML/JSON error pages.
  - Structured JSON-lines logging with central secret redaction; exceptions logged without call arguments.
  - PDO connection (lazy, UTC, `utf8mb4`, strict SQL mode), database version check (ADR-011).
  - Forward-only migration runner with checksums and a lease lock (ADR-018), `locks` table (ADR-015), first migration (`system_events`).
  - Console: `migrate`, `migrate:status`, `make:migration`, `db:check`.
  - Start page and public liveness probe (`/health/live`).
  - Unit, HTTP, security and database integration tests.
- Local development guide for Windows + Laragon in `README.md`.
- Foundation slice 3 (completes the 0.1.0 foundation scope):
  - Encryption key ring and XChaCha20-Poly1305 secret encryption with context binding; `crypto:key:generate`, `crypto:rotate`; background re-encryption (ADR-014).
  - TOTP two-factor authentication with recovery codes, replay protection and throttling; account security page; sign out other sessions (ADR-020).
  - MariaDB-backed queue with `SKIP LOCKED` claiming, leases, retries with backoff, permanent failures and idempotency keys; scheduler with once-per-slot guarantee; `bin/cron.php` runner with worker slots and heartbeat; `cron:run`, `queue:status` (ADR-022).
  - Licensing: Ed25519-verified entitlements, activation/refresh client, offline grace, expiry that never deletes data, development mode (ADR-021); `FeatureManager` with project limit enforcement; `license:status|activate|refresh`.
  - System Health page for installation administrators (PHP, database, storage, encryption, cron, queue, license).
  - Housekeeping job for expired sessions, rate limits, schedule runs and old jobs.
- Foundation slice 2 (toward 0.1.0):
  - Database-backed sessions with hashed tokens, idle/absolute timeouts and ID rotation on login (ADR-019).
  - CSRF protection (synchronizer token + Origin check) for all state-changing browser requests.
  - Password sign-in with Argon2id, transparent rehashing, uniform failure messages and per-account+IP / per-IP throttling.
  - Workspaces with seeded roles (owner, admin, member, viewer) and permissions; `Authorizer` implementing ADR-013.
  - Project shell: list, create, view, edit and soft-delete projects, strictly scoped to the current workspace.
  - Workspace switching and members page; audit log (`audit_logs`) with a workspace-scoped viewer.
  - Application shell UI (sidebar, workspace selector, flash messages).
  - `user:create` console command; UUIDv7 public identifiers.
  - Tests: tenant isolation (in-memory and full-stack against MySQL/MariaDB), CSRF, session fixation, throttling, authorization matrix.
- Minimum database versions: MariaDB 10.6+ / MySQL 8.0+, InnoDB, `utf8mb4` (ADR-011).
- PHP 8.5 minimum rationale and installer/cron version checks (ADR-012).
- Workspace-scoped role model with project-level restriction and a starter permission catalog (ADR-013).
- Versioned encryption key ring, ciphertext envelope, AAD binding, and key rotation procedure (ADR-014).
- Lease-based `locks` table, `schedule_runs` de-duplication, `SKIP LOCKED` job claiming, reservation leases, worker slots, and job idempotency keys (ADR-015).
- Kernel component choices: in-house PSR-11 container and PSR-15 pipeline, `nyholm/psr7`, `nikic/fast-route` behind a `Router` interface (ADR-016).
- Development tooling (PHPUnit, PHPStan, PHP CS Fixer), Composer scripts, and CI matrix (ADR-017).
- Shared-hosting directory layouts and encryption key placement guidance.
- `Domain/Integrations`, `Domain/Pages`, and `Domain/MCP` in the project structure; test directory layout.

### Changed

- The start page now requires sign-in; the public liveness probe stays at `/health/live`.
- `INSTRUCTIONS.md` is now agent-neutral (applies to Claude, Codex, and human contributors).
- Removed the redundant `user_roles` table; roles are assigned via `workspace_users.role_id` and `project_members.role_id`.
- Clarified the difference between `websites` and `project_domains`.
- Cron runner holds the scheduler lock only for planning, so job processing can overlap safely.

### Security

- Validation regular expressions use the `D` modifier so a trailing newline cannot pass `$` anchors.
- Defined AEAD algorithm, key ring storage rules, and rotation for stored secrets.
- Defined permission catalog and "narrow only" project roles.

## [0.0.1] — Project specification

### Added

- Initial project documentation and architecture specification.
- Shared-hosting-first modular monolith design.
- Initial database/table plan.
- Licensing and signed update design.
- Database-backed queue and cron design.
- Google Search Console and DataForSEO integration boundaries.
- Read-only MCP architecture and initial tool/scopes plan.
- Initial phased release roadmap.
- Implementation instructions for coding agents.

### Security

- Defined encryption-at-rest requirements for integration secrets.
- Defined CSRF, RBAC, rate limiting, MFA, logging redaction, SSRF protection, and signed release requirements.

## Unreleased — opportunity source coverage

- Preserve findings and scores when required stored sources are missing or stale; resolve only from fresh evidence.
- Persist and display analysis coverage in Opportunities and opportunities_list. Keep source dependencies for mixed Google/Bing findings.

## Unreleased — opportunity CTR calibration

- Blend non-brand project Google CTR observations with the global prior for click estimates and show sample/model evidence.
- Require statistical evidence for low CTR and identify observed AI Overviews as a possible alternative explanation.

### Opportunity evidence confidence (2026-10-08)

- Replace rule confidence constants with a shared, explainable evidence model during analysis.
- Read subject-level daily sample deviation in three bounded local queries; exclude stale corroborating sources, preserve unknowns, and show freshness, sample size, agreement and completeness reasons in UI/MCP evidence.
- Add golden scoring and MySQL tenant/device/single-sample regression tests.

### Opportunity value weighting (2026-10-08)

- Add transparent intent weights and page-specific GA4 key-event/revenue estimates using stored, complete windows.
- Show the exact impact formula, session sample/prior, currency and non-causal Organic Search assumption in evidence; preserve click fallback when value data is unknown.
- Preserve formerly GA4-weighted scores when credentials/data disconnect and test scope, host matching, priors, caps and unknown values.

### Opportunity trend-aware detection (2026-10-08)

- Add three-period page trends, click-count significance, tracker-volatility gating and marked monthly seasonal context.
- Select Google samples by impressions, expose total/sample counts and preserve unseen findings when samples are incomplete.
- Derive CTR brand terms from non-generic project names as well as domains and high-CTR queries.
- Correct GA4 evidence to describe the existing Organic Search/own-host importer filters.

### Opportunity panel evidence


Opportunities also use stored query/URL shares and position flips to identify overlapping visibility, successful URL Inspection snapshots to flag indexing concerns, and a complete bounded audit graph to suggest contextual internal links. Multiple ranking URLs alone are not a cannibalization finding. Failed, missing or stale inspections do not prove absence from Google. Internal-link suggestions require an audited indexable source and are withheld above 2,000 pages or 5,000 graph links. Audit traffic/value sums distinct affected URLs across stored rows rather than three examples; missing rows remain unknown. Page and origin CrUX measurements remain distinct. Recent relevant annotations and title/canonical/noindex watch changes apply one −15 confidence adjustment with “measure before acting” evidence and the existing annotation impact comparison. Alert context links existing alerts without creating or sending messages. All analysis uses local records, with no provider request. Per-keyword and per-URL freshness protects old findings even when other records in the same source are fresh.

### Opportunity consolidation


The findings list, CSV export and MCP list consolidate a normalized query (case and whitespace) or landing page (host/path, trailing slash and query-string variants) into one primary finding, chosen by priority with a stable UUID tie-break. Other rule observations and actions remain supporting evidence. Existing stored subject hashes, UUIDs, tasks and status histories are retained: done/dismissed decisions suppress duplicate open findings for the same canonical subject. The catalog is bounded at 5,000 stored rows; an incomplete catalog withholds open groups rather than overlook closed decisions. Page action plans are available at `/opportunities/by-page` and the scoped read-only `/opportunities/page-plans` endpoint, up to 200 plans. Structured monthly click estimates are nullable; old rows remain unknown until reanalyzed. Page plans take the larger of summed distinct primary-query estimates and the page estimate, instead of adding overlapping page/query gains. This is a scenario estimate, not a causal forecast.

### Opportunity effort and weekly selection


Effort is refined from observable scope: 2–10 touched URLs imply at least medium effort, more than ten high; content work above 1,500 words is at least medium and above 3,000 high; known keyword difficulty ≥30 is medium and ≥60 high. Unknowns retain the rule estimate rather than imply easy work. Fresh stored noindex/canonical or inspection concerns appear as prerequisites before visibility/content work; dependent work follows unblocked findings, then numeric priority, then stable UUID. Priority arithmetic remains impact × confidence / 100 / effort. Time-to-value ranges (near 1–4 weeks, medium 4–8, longer 8–12+) are planning assumptions, not promised effects. `/opportunities/weekly` and the scoped `/opportunities/weekly-plan` read endpoint show up to ten findings with at most three per primary type. MCP `opportunities_list` accepts `view: weekly` and returns the same bounded selection and ordering reasons. A sparse type mix can produce fewer than ten items.

### Opportunity outcome feedback


Marking a task or finding done captures a nullable Google baseline for the preceding 28 Pacific calendar days, excluding completion day, and creates scoped UUID outcomes for days 28 and 56. Task/finding completion on the same date shares one idempotent episode; the first baseline is preserved and linked annotations provide context. At day 28, days 1–28 are measured; day 56 measures days 29–56. All 28 import marks and an observed subject are required; missing data retries later, never becomes zero. Non-query/non-page technical findings remain unmeasurable from stored GSC. An hourly local job at :43 handles at most twenty due checkpoints, rotates missing rows, and retries them once per UTC day; due dates use Pacific labels with two days for GSC availability. No providers are called. The comparison widget `/opportunities/outcomes` shows raw baseline/actual clicks, position, CTR and monthly-normalized change, explicitly as correlation.

Project/type realization factors use eligible 56-day completions from the last year, at least five samples and at least 200 impressions in each window. Individual observed monthly change / raw predicted gain ratios are clipped 0–2 and blended with ten neutral prior observations; the final factor is bounded 0.25–1.5. Raw and calibrated predictions are stored separately so calibration does not compound. Technical exposure and unknown gains are not calibrated as incremental gain. Factor, sample count, raw estimate and adjusted estimate appear in evidence and MCP. Outcomes do not isolate implementation effects from seasonality, demand, ranking changes or other edits.

### Opportunity detail, filters and snooze


Opportunity detail pages expose numeric priority arithmetic, calibration/value formulas, confidence reasons, source dates, nullable raw/adjusted click estimates and related findings. Read-only JSON detail is at `/opportunities/{finding_uuid}/data`, with normal project authorization and foreign-project/finding 404s. UI and CSV share filters `type`, `page_url` (exact normalized URL/path), `min_impact` (0–100), `effort` (1–3), and `data_basis`. MCP `opportunities_list` accepts those filters, `view: weekly`, optional `opportunity_id` for scoped detail, and `status: snoozed`; all MCP access remains read-only under existing opportunities:read and selected-project constraints. Result-size limits still apply.

Editors can snooze a finding through its detail page until a valid project-local date after today and within one year. This hides equivalent open findings, preserves UUID/history and records an audit event. At the next local analysis on/after that date (normally within the six-hour schedule), a bulk system history transition reopens it; missing sources continue to preserve the finding. A complete fresh analysis can resolve a disappeared condition. Reopening/done/dismissed clears the snooze date. No new provider calls or MCP write scope are introduced.



