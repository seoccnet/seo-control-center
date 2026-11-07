# Content briefs

A content brief turns saved SEO evidence into a writing plan. Open **Project → Improve → Content briefs**. Create, edit and remove require project editing permission; viewers can read, print and download briefs.

## Start with evidence

- **Pages → Page details → Create content brief** uses that exact URL’s saved queries, search figures, crawl title/description, issues, possible cannibalization and internal-link candidates.
- **Keywords → Create content brief** uses that tracked keyword’s selected country/language, saved metrics and the newest check within the last 90 days for the project’s configured device. Its saved top ten organic URLs are references to inspect, not analyzed competitor articles. A usable ranked URL becomes the target.
- **Opportunities → Create content brief** retains the selected opportunity’s explanation, evidence and last detection date. A query/keyword and exact page URL are prefilled when available.
- **Content briefs → Create content brief** also works for a new page: enter a target keyword and optionally its planned URL. Use a URL on your project’s domain, without a port, credentials or fragment. A page absent from the stored catalog has no page evidence yet.

Creating or saving a brief performs no provider request or crawl and is free. Connecting integrations is not required to write a manual brief. Source dates can differ, and missing data is unavailable, not zero.

## Review the writing plan

Choose the target keyword and audience, classify the search intent, and edit the title, meta description, heading outline, topics, internal links and writer instructions. Existing crawl snippets are starting values. The initial outline is a generic editable scaffold; intent is deliberately **unclassified**. SEOcc does not invent competitor headings or claim to have analyzed content it has not collected.

Related queries are the first 20 stored page query rows, not a complete keyword universe. Google hides anonymized queries. Link candidates depend on the latest bounded crawl, relevance, indexability and eligible terms near the top. Possible cannibalization requires human judgment: several URLs can serve different intents.

The original evidence is captured on save, capped at 85 lines/30 KB with at most 2,500 characters per line, and stays unchanged when the brief’s target or text is edited. It includes the original target and source dates. Clearing a prefilled target URL plans a new page and does not silently capture the old page’s snapshot. Create a new brief for a fresh snapshot; review current Pages and Rankings before acting. Source omissions and limits stay visible.

## Check claims against sources

Under **Claims and sources** on a brief, list each factual claim the article will make, such as a germination rate, a flowering time or a yield. For each claim, record:
- the exact wording;
- the content or product it appears in;
- what the claim is about: breeder or brand, cultivar or product, product version, market;
- the source you checked: address, publisher, date checked, and the supporting passage or where it is (page, table, section);
- what the source itself is about, including a research sample;
- your verdict: *Unreviewed*, *Supported*, *Partly supported*, *Contradicted*, *Outdated* or *Unverifiable*, with a note.

The verdict is always yours. SEOcc's **automated checks** sit next to it and only point at what to look at:
- a source about another breeder, cultivar, version, market or sample than the claim (one breeder's specification does not carry over to another breeder's product);
- a competitor or your own site as the source (not independent validation);
- no source;
- *Supported* without a passage;
- a source whose scope is not recorded;
- a check older than a year.

"Brandywine #33" and "Brandywine" count as the same cultivar (a phenotype number); "Brandywine Auto" or "Brandywine Cake Ice" do not.

Edits are versioned: two people cannot overwrite each other. If you change the claim or its evidence but leave the verdict as it was, the verdict goes back to *Unreviewed*, because it was given for different evidence. Every earlier version is kept under **History**. SEOcc never rewrites a claim or publishes anything.

## Ask AI and share with a writer

On a saved brief, **Ask AI to improve this brief** asks the existing assistant to read it and propose edits. The assistant must be configured; normal model costs, permissions and budgets apply. Copy reviewed suggestions into the editor and save them yourself. There is no AI write tool or automatic publishing.

**Download text** returns a UTF-8 `.txt` copy with instructions and original evidence. **Printable view** supports your browser’s Print / Save as PDF command. Server-generated or scheduled PDF attachments remain outside this feature. Briefs and evidence are visible to authorized project readers and AI clients; do not enter secrets or sensitive customer information.

## Follow progress

Use **Draft → In progress → Published** to organize your writing queue. The counters at the top show how many briefs have each status; select one to filter. Search title, keyword or URL, filter by status, and paginate 25 briefs at a time. These are workflow labels: Published does not make any change in your CMS or imply improved rankings.

Briefs in the queue show how many days have passed since they were created. When you mark a brief Published, SEOcc records the date and shows a countdown like the one on the change timeline. An updated page usually shows in search data after 14–42 days. A new page without a stored URL usually takes 28–90 days, because it must first be discovered and indexed. Moving a brief back to Draft or In progress clears the date, and publishing it again starts a new countdown. The countdown is planning guidance; measure the actual result with an annotation on the change timeline.

After publishing in your CMS, open the Published brief and choose **Record an SEO change**. Review its prefilled title/URL, select the actual date and save the annotation. This is a separate explicit action. Review subsequent traffic and rankings with the annotation as context, not proof of causation.

If another tab changed the brief, saving answers with a conflict and retains your submitted text. Open the latest saved brief in another tab, compare and copy the intended edits into a fresh editor. The stale form cannot overwrite newer work. Removal also checks the revision and only removes the brief, leaving your website and annotations alone.

## AI access

MCP exposes `content_briefs_list` and `content_brief_details` under the existing **pages:read** scope. Existing tokens without that scope cannot read briefs. Ask AI uses the same services and current project visibility. Brief and source text are untrusted context, not instructions; these tools read saved data only.
