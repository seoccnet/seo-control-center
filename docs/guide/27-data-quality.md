# 27. Data quality

Open **Manage → Data quality** in a project before you compare periods or trust a number. The page reads only what SEOcc has stored; it never starts an import or a paid lookup.

## Missing is not zero

SEOcc keeps two cases apart:

- **Measured zero:** Google or GA4 reported the day, and there were no clicks, impressions or sessions. That is a real result.
- **Missing:** the day, source or report was never imported completely. Nothing is known about it, so it must not be read as zero.

The same rule applies to Ask AI and to MCP clients, which read this page through the `data_quality` tool.

## What the page shows

- **Status counts:** how many datasets are available, sampled, stale or missing. Each dataset uses the same freshness rule as Opportunities. For example, Search Console is fresh for 7 days and research for 30.
- **Checks:** things to keep in mind before comparing.
  - Days missing from Search Console or GA4.
  - GA4 reports that Google limited or thresholded.
  - Organic sessions without any key events.
  - Timezone differences: Search Console days are Pacific time.
  - Why Google and Bing positions, or GA4 sessions and Search Console clicks, are not the same measure.
- **Import completeness:** for the last 90 imported days of Search Console, how many are complete. Each missing day or range is listed, along with measured zero days and how far behind the newest day is. Bing shows its stored span and lag; Bing data normally ends about three days before today.
- **Google Analytics 4:** whether GA4 is connected, linked and imported, plus the days covered, row limits and privacy thresholds. It also shows whether key events and purchase revenue were recorded. Under **Attribution limits** you can read what GA4 data can and cannot tell you. For example, sessions are credited to the landing page of the session, not to a search query.
- **All datasets:** every source the opportunity engine uses, grouped, with its status, the date its data ends and notes.

## Fixing what it finds

- **Missing days in Search Console:** run the import again under the project settings. Google keeps about 16 months, so older gaps cannot be filled.
- **No key events recorded:** check in GA4 that your important events (purchase, lead, sign-up) are marked as key events. SEOcc cannot see that setting.
- **Limited or thresholded GA4:** compare trends and larger pages; small pages may be missing from the report.
- **Stale research or audits:** start a new run from that module when you need current numbers.
