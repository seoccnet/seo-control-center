# SEO change annotations

Record what you changed on your website so that your next SEO review has a reliable timeline. An annotation is a manually recorded note, not a measurement or proof that a change caused a result.

## Record a change

1. Open your project, then **Manage → Change timeline**.
2. Choose the actual change date in UTC. Dates can run from 1 January 2000 through today; future changes belong in your work plan rather than this history.
3. Choose Title / snippet, Content update, Internal links, Technical change, Website release or Other.
4. Give the change a short, specific title, such as “Clarified delivery information in the coffee category title”. Titles allow up to 120 characters.
5. Optionally enter the exact HTTP(S) page URL. It must belong to the project domain or its www equivalent. Leave it blank for a site-wide change. Paths and query strings are case-sensitive; fragments, credentials and custom ports are not accepted.
6. Add details if useful: what changed, why, and what you intend to review. Details allow up to 2,000 characters.
7. Keep **Measure this change** ticked for a page change, then save the annotation. SEOcc drafts a measurement plan and freezes its baseline right away:
   - metric: Google clicks for the page, or its CTR for a title or snippet change;
   - comparison: 28 days before and 28 days after the change.

   Its result appears once 28 days of data after the change are imported. Untick the box for a change you do not want measured. A site-wide change has no page to measure, and editing a change never drafts a plan. See *SEO change impact → Measurement plan* to adjust the plan.

Use the date and URL filters (under **Filter by date or page**) to find previous changes. The timeline shows 25 notes per page, newest change date first, grouped by month.

## Follow each change

The counters at the top show how many changes are recorded, how many are **waiting for a recrawl**, how many are **in the effect window** and how many are **ready to review**. Changes older than 120 days count as settled.

Each change has a countdown with the day since the change and a track. The highlighted part of the track is the window in which changes of that type usually start to show in search data:

| Change type | Usually shows | Watch |
|---|---|---|
| Title / snippet | day 7–21 | CTR and clicks |
| Content update | day 14–42 | Impressions and average position |
| Internal links | day 14–56 | Position of the linked pages |
| Technical change | day 3–28 | Indexed pages, impressions and errors |
| Website release | day 7–56 | Site-wide clicks and impressions |
| Other | day 14–42 | Clicks and impressions |

These windows are planning guidance from typical recrawl times, not a prediction for your site. The countdown also gives the date of the full 28-day before/after comparison, which **Review impact** shows once 28 full days have passed.

After 7 days of imported Google data following the change, an **early read** appears. It compares equal, complete periods before and after the change day, up to 28 days each, for clicks, impressions, CTR and average position. It covers the exact page for a page change and the whole site for a site-wide change. Green and orange mark the direction, with lower position counting as better. Changes under 1 % (0.05 points of CTR, 0.1 positions) stay neutral. If either period is incomplete, the early read says so instead of comparing. Filtering by a URL also includes site-wide changes. Authorized project editors can edit or remove notes; viewers can read them. Removing a note hides it from the timeline and context, while the database retains the deleted row and the audit log records the action.

## Change bundles: review, guard and verify an edit

For an edit that should be reviewed before it goes live, use **Manage → Change bundles**. SEOcc never edits your site; you deploy in your CMS.

1. **Draft.** Enter:
   - the page and the problem;
   - the evidence (sources, figures);
   - each exact edit, with what the page says now and the new text;
   - what you expect and how to validate it.
2. **Capture live baseline.** Cron reads the page as it is now: status, title, description, headings, canonical, noindex, word count and body text.
3. **Approve.** The approval covers this exact content and baseline, identified by a hash. Editing the bundle or capturing a new baseline clears the approval.
4. **Check the live page (version guard).** Just before you deploy, SEOcc reads the page again. A deployment can only be recorded after a check from the last 24 hours that matches the approved baseline. If someone changed the page since the approval, SEOcc blocks the record and lists what changed. Capture a new baseline, review the edits against it, and approve again.
5. **Record deployment.** Make the edits in your CMS, then tick the ones that were applied. A partial deployment is kept as such, with a note on what failed. The deployment is added to this change timeline, where the change impact and its measurement plan follow.
6. **Verify on the live page.** SEOcc reads the title, description, main heading, canonical and noindex back and marks each edit *Live* or *Not live*. Body text, redirects and other edits need a check by hand.
7. **Rollback.** If you undo the edits, record when and why. The rollback is also added to the timeline.

Every step is kept in the bundle's history. A bundle that was never deployed can be deleted with its history (*Delete bundle*); a deployed bundle stays as the record of what changed. Viewers can read bundles; editors make and approve them. The MCP `change_bundles` tool gives AI assistants the same read-only view.

## Review the chart context

The project overview, Google and Bing performance charts, and individual keyword history charts show dashed markers for recorded dates within the chart's displayed data. Open **SEO change context** below the chart to read the notes. Several notes on the same date share one marker. Context includes the most recent 100 notes within the selected dates; use the full timeline for older notes or pagination.

Markers follow the existing chart's date positions. On sparse keyword checks, a marker between two checks indicates the change date; it does not invent a ranking measurement for that date. A chart with no data has no markers.

Page details include notes for that exact URL and site-wide changes within the page's reporting period. Recording an annotation does not fetch a URL, modify your website, start a provider request or change an opportunity's status.

## Ask AI about changes

For example: “What changed on this page during the displayed period? Compare the recorded changes with the available traffic and ranking evidence, and tell me what we still cannot conclude.”

Ask AI can read these notes through `annotations_list` and `page_details`. External MCP clients use the existing `projects:read` scope for `annotations_list`; page details still require `pages:read`. These tools are read-only and enforce the user's project access. An MCP client with the **Change notes** permission can also add and edit notes (`annotation_create`, `annotation_update`); such notes show "Last changed via <token name>". Ask AI does not write notes.

Notes are visible to authorized project users and can be included in AI conversations that request this context. Do not put passwords, API keys or other secrets in them. The assistant treats note text as evidence, not instructions.

## Interpret results carefully

Search engines may take time to recrawl and reflect an edit. Seasonality, campaigns, competitors, algorithm changes and missing imports can affect the same period. Annotation dates use UTC; Search Console and other sources can use different date boundaries. Compare complete reporting periods, check data coverage and inspect the affected queries. A marker beside a traffic increase does not establish that your edit caused the increase.
