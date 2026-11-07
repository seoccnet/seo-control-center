# Analytics and Core Web Vitals

Connect Google Analytics 4 to understand Organic Search landing-page results, and Google CrUX for real mobile and desktop field performance. Both are optional and need their own Google setup.

**Integrations → Connection guides** includes illustrated GA4 and CrUX setup steps, original Google screenshots and links to the relevant console screens. The same guides appear on each provider’s settings page. Google’s Analytics demo is a reporting example; Google does not allow its demo properties to be imported through the Data API.

## Connect Google Analytics 4

Enable the **Google Analytics Data API** in a Google Cloud project. Create a service account and grant its e-mail address **Viewer** access to your GA4 property. Under **Integrations → Google Analytics 4**, an owner or administrator supplies the service account's JSON credential. SEOcc encrypts it and does not display it again. This connection is separate from Search Console.

Under **Project → Analytics**, link the numeric GA4 property ID and start an import. Daily imports can then keep the latest 28 completed days current. Changing the linked property clears the previous property's imported data. Disconnecting the workspace credential stops imports while retaining stored results.

If an import fails, check **System → Jobs**. Errors distinguish service-account authentication from the GA4 report request, and recognized Google reasons identify disabled APIs, permission or policy refusals, invalid authentication and quota exhaustion. Check that the credential saved in SEOcc belongs to the service account granted access to the selected property. Saving credentials or linking an ID does not prove Google access; a successful import does. Unknown responses show a generic error without exposing private Google response content.

## Understand the analytics figures

Imports include **Organic Search** sessions, **conversions (GA4 key events)** and **purchase revenue** for the project's bare and www hostnames. Conversions may be fractional because of attribution. Purchase revenue uses the property's currency and can include negative adjustments.

Dates use the property's timezone. Results show privacy thresholds and row-limit notices: an import is capped at 10,000 daily rows. Reports with missing imported dates display a partial-period warning. These figures also appear in page details and reports. Search clicks and sessions should not be expected to match.

## Import history and compare periods

Daily imports refresh the latest 28 completed days while retaining earlier imported dates. Under **Project → Analytics**, select 28, 90, 180 or 365 days before requesting an import. Larger imports consume more Google quota and may exceed the 10,000-row cap; a limited import is visibly marked.

Use the start and end date filters for a period of up to 365 days. Each landing page can show changes in sessions, conversions and purchase revenue against the immediately preceding period of the same length. Both periods need imported coverage for every date, including successful dates with no rows. Missing dates are unavailable rather than zero; comparisons are suppressed when either compared period contains limited or privacy-thresholded imported dates.

Only history matching the currently reported property currency and timezone is included. Older rows with a different currency/timezone remain stored but are not added together. Changing the linked property explicitly clears its previous history. Page details use their displayed reporting dates rather than lifetime imported totals.

## Business measurement

Sessions alone do not say whether search brings business. Under **Project → Analytics → Business measurement**, name the GA4 events that count as success for this project:
- a purchase (`purchase`);
- a qualified lead (`generate_lead`);
- a subscription or sign-up (`sign_up`);
- another goal, by its exact GA4 event name.

Find the exact names in GA4 under Admin → Events; case matters. A project can have up to ten business events.

Each event shows one of five states:

| State | Meaning |
|---|---|
| Not configured | No business event is defined, so SEOcc cannot tell whether organic visits lead to results. |
| Unknown | GA4 is not connected or linked, or not every day of the period is imported. A missing count is not zero. |
| Import failed | The last GA4 event import failed. The error is shown; the landing-page import is not affected. |
| Not observed | GA4 recorded none over fully imported days. Nothing may have happened, or the tag, consent banner or event name may not be set up. Treat business value as unverified, not zero. |
| Observed | GA4 recorded the event, shown site-wide and in Organic Search sessions. |

The period ends on the last imported GA4 day. The section also shows GA4 Organic Search sessions next to Search Console clicks for the same dates. Search Console runs two to three days behind, so its count is often marked *Partial* with the days it covers. They measure different things (sessions include Bing, need the tag and consent, and restart after 30 minutes), so they never match one to one. The limits list covers consent, time zones, currency, attribution, privacy thresholds and what SEOcc cannot see: it reads GA4 reports only and cannot check whether your site's tag fires on a page.

**Check a test conversion.** Make one conversion yourself on your live site (a test order, a test lead form or a test sign-up), then choose **Check GA4 now** within 30 minutes. At the next cron run SEOcc reads GA4's realtime report, read-only, and shows which business events GA4 received. A count above zero shows that GA4 received the event, not which page or channel sent it. SEOcc never buys, submits or sends anything itself.

When GA4 records no key events or revenue, opportunity value estimates say "unknown" instead of zero.

The Quest Log's *Connect Google Analytics* quest has two more steps: name your business events, then see GA4 record one of them. If you finished the quest before these steps existed, it shows as in progress again until they are done.

## Connect Core Web Vitals

CrUX field data and optional paid browser/Lighthouse Site audit checks are separate. CrUX reports mobile or desktop real-user p75 LCP, INP and CLS with rolling collection dates. Browser diagnostics report lab runs and do not provide INP or an overall field-data pass. Missing metrics remain unavailable; SEOcc never substitutes origin data for a page. Invalid device/URL records and collection dates are rejected.

Enable the **Chrome UX Report API** in Google Cloud. Under **Integrations → Core Web Vitals**, supply an API key restricted to that API. From a known URL in **Pages**, an editor can request a field-data check. Google quotas apply.

SEOcc sends requests from the hosting server. If you restrict the key by application, use the server's outbound IP addresses; website/referrer restrictions block server requests. A shared hosting address may differ from the outbound address. System → Jobs shows recognized Google rejection reasons for IP/referrer/API restrictions, disabled APIs and invalid keys. A 403 means access was refused; it does not indicate missing field-data coverage.

Page details show separate mobile and desktop 75th-percentile **LCP**, **INP** and **CLS**, with their rolling collection dates. Editors can enable weekly refresh for up to 50 page/device watches per project. Cron checks up to 50 due watches each hour; a configured Google API key is required. The latest 52 snapshots per URL/device are retained. SEOcc requests URL-level data and does not silently substitute an origin-wide result. A low-traffic URL may have no data; that is not a pass or fail.

## Speed tests (lab)

Open **Improve → Speed tests**. A profile saves a test setup, so its runs can be compared with each other: one page, mobile or desktop, and a source.

- **PageSpeed Insights** is free. It uses the Google API key saved under **Integrations → Core Web Vitals**. Enable the **PageSpeed Insights API** in Google Cloud and, if the key has API restrictions, allow it for the key too. A new PageSpeed profile runs once right away.
- **DataForSEO Lighthouse** is paid (about $0.005 per run, from the workspace budget). Tick the confirmation each time you start a run.

Each run shows Lighthouse's performance score (0–100), LCP, CLS, TBT, FCP and Speed Index. The latest 8 runs are listed, with the score change since the previous run. Switch on **Repeat weekly** to test automatically once a week. Up to 10 profiles per project; the newest 50 runs of each profile are kept.

Lab runs simulate a page load under fixed conditions; they are not what visitors experience. Each profile therefore shows the field data of the same page and device beside its runs (if fetched under Pages), and the two are never mixed. Compare runs within one profile, not across sources or devices. TBT stands in for INP, which only exists in field data.

### Why a run scored as it did

Open **Why the latest run scored N** under a profile. It is read from the report Lighthouse returned for that run, so it costs nothing extra:

- **Largest Contentful Paint element**: what the LCP was (for example the hero image), its markup, the image address, and how the time split: TTFB, load delay, load time and render delay. The phases come from the page load Lighthouse observed, before its simulated throttling, so they don't add up to the run's LCP; read them as shares, and start with the largest. On Lighthouse 13 (PageSpeed Insights since late 2026) it also lists the request discovery checks: whether the image has `fetchpriority=high`, can be found in the HTML, and is not lazy-loaded.
- **Render-blocking resources**: style sheets and scripts that hold back the first paint, with how long and how large.
- **Top diagnostics**: audits below a good score, largest estimated saving first. The savings are Lighthouse's estimates for this run, not a promise, and they do not add up. Items without a saving or value (for example *Network dependency tree*) follow as *Also flagged*.
- **Requests by type** and the **largest requests**.
- **Test configuration**: device and screen, throttling (round trip, bandwidth, CPU slowdown), Lighthouse version and the test machine's benchmark, the tested address and user agent. PageSpeed Insights does not report its throttling or screen: it uses fixed mobile or desktop settings, so its runs are compared by Lighthouse version only.
- **Raw report**: its SHA-256, size and fetch time, plus the DataForSEO task for paid runs. SEOcc does not keep the full report.

Runs saved before 0.38.0 have only their metrics; the next run adds the diagnostics.

### Comparable runs

PageSpeed Insights updates Lighthouse from time to time, and a new major version scores differently. A run tested with another Lighthouse major version, throttling or screen size than the run before is marked **Different setup**. The profile then says why instead of showing a score change. Compare its later runs with each other.

AI assistants can read profiles, runs and field data with the `lab_tests` tool (*Pages* permission), including the latest run's diagnostics and whether each run is comparable with the one before.

## Where the results appear

Field data appears in **Pages** and **Site audit**, separately from crawler response timing. Opportunities can flag results with a collection window ending within the last 35 days when LCP exceeds 2.5 seconds, INP exceeds 200 milliseconds or CLS exceeds 0.1.

These are field measurements from real Chrome visits, not a Lighthouse laboratory test or a speed score generated by SEOcc.
