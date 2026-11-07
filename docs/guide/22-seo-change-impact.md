# SEO change impact

Open **Project → Manage → Change timeline**, then **Review impact** on a recorded change. Page details also links each displayed annotation to its comparison. Viewers can read results. No website edit, import or paid lookup runs when opening a report.

Choose **14, 28 or 90 days** per period. Before ends the day before the change; After starts the day after it. The change day is excluded. SEOcc retains equal full windows rather than shortening a recent period: wait until After ends, then import the available data.

## Google results

Exact-page annotations use that URL's saved Google page measurements, preserving case and query strings. Site-wide changes use whole-property daily totals. Results show clicks, impressions, CTR and impression-weighted average position (lower is better). CTR deltas are percentage points. Average position is not a tracked-keyword rank or a fixed keyword cohort; query mix can change.

Both periods need all dates and measured metrics. Completed page imports do not prove an omitted URL had zero traffic; anonymized or bounded data can omit rows. Partial values are shown but receive no comparison. A zero baseline gets an absolute change without an infinite percentage; no impressions means CTR/position are unavailable.

**Improved**, **Declined**, **Mixed**, **Unchanged** or **Insufficient data** summarizes Google clicks/impressions direction only. This is not a statistical significance test; even a one-click difference can change the label. Review scale and context. GA4 does not determine this status.

## GA4 outcomes

Exact-page changes show Organic Search landing-page sessions, conversions and purchase revenue. Both periods require all imported dates matching current currency/timezone, without limited or thresholded reports. Successful complete GA4 imports without this URL are measured zeros. Property changes clear history; incompatible currency/timezone history prevents comparison. Currency, timezone, coverage and fetch time appear in the report.

Site-wide GA4 totals are unavailable here; SEOcc does not estimate them from a truncated landing-page list. Bing weekly page buckets are excluded from daily comparisons. Google dates use Pacific Time, GA4 its property timezone; these can differ from your local edit date.

## Measurement plan

Below the comparison, **Measurement plan** records how the change will be judged, ideally before its results come in.

A page change recorded with *Measure this change* (ticked by default) already has a plan with a frozen baseline: Google clicks for the page (CTR for a title or snippet change), 28 days on each side. Adjust it here. Adding target searches or control pages, or changing the metric or period, clears the baseline: freeze it again afterwards.

1. **Create the plan.** Enter:
   - the objective, for example "More clicks from searches about mold on pepper";
   - the primary metric;
   - the target pages and queries (the change's address is filled in; leave both empty to measure the whole property);
   - control pages: similar pages this change did not touch;
   - the days on each side (14, 28 or 90; the change day is excluded);
   - review dates in the project timezone. Default reviews are halfway and four days after the period ends, when Search Console has the last day.
2. **Freeze the baseline** on or after the change day. It stores the period before the change with how complete each source was. Changing the metric, targets, controls or period clears it, so freeze again.
3. **Wait for the gates.** The result is read only when the change was made, the baseline is frozen and complete, the period after the change is over, and every day of it is imported. Until then the plan says *Planned* or *Pending*, for example "Only 2 of 28 days after the change are imported so far". A partial period is never compared.

| Result | Meaning |
|---|---|
| Improved / Declined | The primary metric moved by at least 5 % (half a position for average position). |
| No clear change | It moved less than that. |
| Insufficient evidence | No frozen baseline, missing days before or after, a target without data (for example a page that did not exist before the change), or fewer than 100 impressions (30 GA4 sessions) before the change. |

**New pages.** A page that did not exist before the change has no data to compare with, so a before/after plan can only say *Insufficient evidence*. Set an **after-only goal** instead: the level of the primary metric to reach in the days after the change, for example at least 500 impressions in 28 days (or at most position 10). The plan then reads *Goal reached* or *Goal not reached* once the period is over and imported. No baseline is needed. Reaching a level shows where the page stands, not what the change caused.

With control pages, the effect is the targets' change minus the controls' change over the same periods, and the result is marked **controlled**: a season or a search update that moves both is taken out. Without controls, a result is an **association**. Other recorded changes in the periods are listed, because they may explain part of it. GA4 metrics need landing pages; queries work with Search Console metrics only.

## Interpretation and Ask AI

Other changes across both windows show at most the most recent 100 matching notes, excluding this note. Page comparisons include site-wide notes; site-wide comparisons include all pages. No notes does not mean no other edits occurred. Seasonality, competitors, search updates and overlapping changes affect results. **Association is not causation.** New pages may lack a measured Before period.

**Ask AI to explain the results** reads the same report and proposes investigations. Existing model configuration/costs apply; notes are untrusted data, never instructions. MCP `seo_change_impact` requires **pages:read**; site-wide results also require **search:read** and existing project restrictions. No AI write tool is added.

Reports recalculate from current stored imports. Editing the annotation's date/URL changes the comparison, and later imports can revise results. This is not an immutable experiment or automated causal estimate.

## What worked on your site

**Measure past changes.** If page changes from the last year have no plan, *What worked* offers to give them the default plan from stored search data: clicks for the page (CTR for a title or snippet change), 28 days on each side. It handles up to 40 changes per click and costs nothing. Changes old enough show a result at once; those without enough stored data say so.

Results of these changes are marked **measured afterwards**, because the plan was set after the change (the baseline was frozen more than 7 days later). Treat them as a rough guide. **Overlaps** means other changes fall inside the comparison period, so the effect cannot be credited to this change alone, and the same effect can appear on each of them.

**Manage → Change timeline → What worked** collects the results of every change with a measurement plan, newest first (at most 60):

- **By kind of change** (title and snippet, content update, internal links, technical change, website release, other). For each kind, the page shows:
  - how many changes were *measured*: read behind every check above;
  - how many improved, declined, or showed no clear change;
  - the median effect;
  - how many results are *controlled*: compared with control pages.

  A reached goal counts as improved and a missed goal as declined. Results that could not be read (*insufficient evidence*) and plans still running are counted apart, never as "no change".
- **By action plan** for changes deployed from a change bundle that started on a finding. The same record appears on each finding with that plan, below its "how well tested" label: "On your site: 3 of 4 measured changes improved, median +12 %."
- **Every measured change** with its metric, result and effect. Effects are "better is positive": percent for clicks, impressions, CTR, sessions and key events; positions gained for average position.
- **To make results readable**: plans without a frozen baseline, and changes to a page from the last 180 days without a measurement plan.

Fewer than three measured changes of a kind are too few to show a pattern. Uncontrolled results are associations, not causes. MCP `what_worked` (**pages:read**) returns the same summary.
