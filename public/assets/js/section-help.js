// Local, keyboard-accessible explanations. No provider or AI requests.
const help = {
    'Quest Log': 'Finish quests on your own project to learn SEOcc. Foundations shows your setup live; other quests complete when SEOcc can see the work in your data.',
    'Opportunities': 'Prioritized suggestions from stored evidence. Review each source, date and recommended action before assigning work or marking it complete.',
    'Rankings': 'Stored keyword positions for the configured location and device. Lower positions are better; scheduled checks require cron and provider access.',
    'Position': 'The latest stored rank for this keyword and device. A dash means no measurement, not position zero.',
    'Best (12 months)': 'The lowest stored position in the last twelve months. Compare the same keyword, device and location.',
    'Search volume': 'Provider estimates of monthly searches for the chosen market. Estimates are not guaranteed visits to your website.',
    'Search Console clicks': 'Google search clicks imported for the displayed period. Anonymized query data may be excluded.',
    'Checks': 'Historical stored checks. Compare their timestamps and device settings when evaluating a change.',
    'Comparison': 'Compare visibility using the same tracked keyword set and market. Coverage and missing checks affect the comparison.',
    'Domain overview': 'A provider snapshot of the requested domain and market. Check its date and coverage before comparing it to your imported search data.',
    'Your ranking keywords': 'Provider-discovered rankings for your domain. These are separate from the keywords you have chosen to track in SCC.',
    'Keyword gap': 'Terms where competing domains appear and your domain has weaker coverage. Review intent and relevance before creating content.',
    'Keyword ideas': 'Suggestions from the provider for your seed term and market. Add only relevant keywords; research requests can consume provider budget.',
    'Competitor discovery': 'Candidate competitors from overlapping searches. Review their domains before adding them to project comparisons.',
    'Locations and reviews': 'Each branch has its own business identifier, coordinates and stored checks. A shared website does not combine all branches into one location.',
    'Primary location reviews': 'Stored review text from the primary business profile. Provider samples can omit reviews; dates refer to the stored snapshot.',
    'Ranking grid': 'Each point records the business position around a specific coordinate. Missing positions differ from successful top rankings.',
    'AI assistants': 'Stored provider checks of brand mentions in supported AI assistants. Coverage depends on the provider and checked prompt; this is not a complete record of every response.',
    'Brand-name mentions': 'Word matches in sampled ChatGPT and Google AI answer text, including answers without a website citation. Review excerpts for unrelated namesakes. Counts overlap with domain-based results and must not be added together.',
    'Brand check history': 'The latest 12 attempts for the current name and market. Charts use the latest completed result per UTC day. Missing or failed checks are not zero; changing the name or market selects a separate history.',
    'Checked pages': 'URLs processed by the selected crawl. Open a URL’s issues to review the exact finding; excluded and failed requests have separate statuses.',
    'IndexNow': 'Notify supporting search engines about new or changed URLs after verifying the project’s public key file. Acceptance does not guarantee indexing.',
    'Recent submissions': 'Stored IndexNow notification results. Review the response and submitted URL count independently of actual search-engine indexing.',
    'View a report': 'Choose an equal reporting period and review the underlying coverage notices before sharing results with a client.',
    'E-mail schedule': 'Reports use stored project data and your configured mail server. Scheduled delivery requires cron and an eligible recipient.',
    'Sent reports': 'Delivery history for scheduled reports. Review a failed delivery’s recorded error and mail settings before retrying.',
    'Organic-search conversions and revenue': 'GA4 Organic Search key events and purchase revenue for the stated snapshot. Currency and attribution belong to the GA4 property.',
    'Project details': 'The primary domain identifies this project. Project settings and external integration links control which evidence is imported.',
    'Add a member': 'Workspace roles control what a person can do. Project assignments further restrict member and viewer visibility.',
    'Open invitations': 'Pending workspace invitations. Share the invitation only with its intended recipient and revoke invitations that are no longer needed.',
    'Sessions': 'Devices with an active account session. Ending a session signs that device out.',
    'Two-factor authentication': 'Add a second sign-in factor and keep recovery codes in a safe place. Follow the enrollment and confirmation instructions on this page.',
    'Alerts': 'Notifications generated from stored evidence according to your preferences. Delivery requires a working mail setup.',
    'Weekly digest': 'A scheduled summary of stored project results. Choose the projects and delivery settings that suit your role.',
    'Database backups': 'Backups contain installation data. Download and store them securely, and use the documented restore procedure when needed.',
    'Database updates': 'Applied and pending schema changes. Follow the release procedure and backup guidance before installing an update.',
    'Installed version': 'The application version currently installed on this server. A built release package does not change the live installation until it is installed.',
    'Upload a package': 'Use an SCC signed update package. The installer verifies the signature and compatibility before applying the update.',
    'White label': 'Customize the product identity and appearance for this installation. Shared dark and light theme tokens preserve consistent contrast.',
    'SMTP server': 'Configure the server that sends invitations, password resets and reports. Use the credentials and connection settings supplied by your mail host.',
    'Sender': 'Use a sender address approved by your mail provider. Domain authentication and provider policies affect deliverability.',
    'Clicks': 'Visits started by clicking your result in the selected search engines. Compare equal date windows; clicks and analytics sessions measure different things.',
    'Impressions': 'How often a search result was shown. More impressions can mean wider visibility even when clicks have not increased.',
    'Average CTR': 'Click-through rate is clicks divided by impressions. Compare similar queries and positions before deciding that a title needs changing.',
    'Average position': 'An impression-weighted search position. Lower is better. This average is not the exact rank of every query or device.',
    'Every known URL': 'This catalog combines stored URLs from search, rankings, crawls and integrations. Open a URL to see its evidence and recommended next actions. A dash means no imported measurement.',
    'What to improve': 'An explainable review order built from stored data. Check the source dates and evidence before acting. These recommendations do not edit your site.',
    'Search queries for this URL': 'Google searches that showed this exact URL. Anonymized searches are omitted, so query totals can be smaller than the page totals.',
    'Conversions and revenue': 'Stored GA4 Organic Search attribution. Conversions are GA4 key events; purchase revenue uses the property currency. These figures are separate from search-engine clicks.',
    'Organic-search landing pages': 'Pages where Organic Search sessions began. The report uses the latest stored GA4 snapshot, including its dates, timezone and currency.',
    'Tracked keyword rankings': 'The latest stored rank checks for keywords you track. A missing rank is different from a position outside the provider’s checked range.',
    'Tracked keywords': 'Keywords you chose to monitor. Location, language and device affect rank checks. Review estimated provider costs before starting a paid check.',
    'Suggestions from Search Console': 'Queries already appearing in your imported Google search data. Add useful queries to tracking to monitor them regularly.',
    'Add keywords': 'Choose terms relevant to your customers and the pages you want to improve. Tracking and metrics use your configured location, language and provider budget.',
    'AI Overview citations': 'Stored checks where Google’s AI Overview cited this URL. Absence here means no citation in the stored checks, not a claim about every search.',
    'Google AI Overviews': 'Google AI Overview presence and citations from stored keyword checks. Results depend on the checked location, device and date.',
    'Keywords with an AI Overview': 'Only stored keyword checks that returned an AI Overview. Switch to all checked keywords to include searches without one.',
    'All checked keywords': 'All stored AI Overview keyword checks, including those without an overview. Use the check date when comparing results.',
    'Most cited sources': 'Domains or URLs cited in the stored AI Overview checks. Counts describe this sample, not all Google searches.',
    'Site audit': 'Findings from the latest completed crawl. Crawl timing is not a real-user performance measurement. Review an issue’s affected URL and advice before changing it.',
    'Core Web Vitals': 'Mobile real-user LCP, INP and CLS from Google CrUX. Results cover a rolling collection window. Low-traffic URLs may have no field data.',
    'Mobile Core Web Vitals': 'LCP measures loading, INP responsiveness and CLS visual stability at the 75th percentile. No data does not mean the URL passes.',
    'Chrome UX Report': 'Connect Google’s CrUX API to read mobile field measurements for exact URLs. Enable the API in the key’s Cloud project and avoid website/referrer restrictions for server requests.',
    'Open opportunities': 'Tasks generated by fixed rules from your stored SEO evidence. Review impact, confidence and effort; a high priority is a review suggestion, not a ranking guarantee.',
    'Possible cannibalization': 'Several URLs share a meaningful query in the top 20. Different search intents can justify several pages; do not merge or redirect them without reviewing the evidence.',
    'Possible keyword cannibalization': 'Candidates need at least 200 query impressions, with each page contributing at least 50 and 10% of impressions. This identifies possible overlap, not a Google penalty.',
    'Internal link suggestions': 'Relevant indexable source pages in the latest crawl, with incoming links and no existing link to this target. Add links only where they help readers.',
    'Backlinks': 'Stored referring-link evidence. Page-level samples are bounded; no sampled links does not establish that a page has no backlinks. Provider refreshes may be paid.',
    'Issues by type': 'Groups of crawl findings by severity and issue type. Use the affected URL and specific advice to decide whether the finding applies to your intended page behavior.',
    'All issues': 'Findings in the selected audit. Server filters apply to the audit; table controls only filter rows already loaded on this page.',
    'Audits': 'Completed and running crawl history. Open a completed audit to inspect its findings; starting another crawl creates a background job.',
    'Google Maps positions': 'Local rankings vary across the checked area. The grid reflects stored points for the chosen location and keyword, not one universal Maps position.',
    'Local competitors': 'Businesses appearing in the stored local search results. Compare their coverage for the same location, keyword and check date.',
    'Business Profile': 'Stored Google business information for the configured location. Reviews and profile details may be limited to a provider sample.',
    'Property': 'Link the project to the correct GA4 numeric property ID. Changing it clears the previous property’s stored landing-page snapshot.',
    'Read-only service account': 'A separate Google credential for GA4 reporting. Give its email Viewer access to the property; Search Console OAuth does not grant this access.',
    'Google Search Console': 'Read-only Google search performance for verified properties. Connect the account, link a property to the project, then let imports run through cron.',
    'Bing Webmaster Tools': 'Connect Bing’s API key and link a verified site. Daily totals and weekly page/query buckets have different reporting windows.',
    'DataForSEO': 'Your own paid provider account for rankings, research and link data. SCC enforces the configured budgets; review the action’s estimated cost before running it.',
    'AI access (MCP)': 'External assistants receive only the scopes and projects you approve. Reading stored data requires no paid provider lookup; revoke access when it is no longer needed.',
    'AI assistant': 'Ask AI uses your chosen Claude or OpenAI API account and workspace budget. Review the answer’s evidence, and confirm any supported write action before it runs.',
    'Claude or GPT': 'Choose a provider and model available to your API account. A ChatGPT or Claude website subscription does not automatically include API credit.',
    'Account': 'Connection status describes the stored provider account. Saving credentials and verifying access are separate steps where the integration says so.',
    'Budgets': 'Limits apply to provider requests recorded by SCC. They do not limit spending made directly in another application or the provider dashboard.',
    'Usage': 'Recorded provider requests and settled costs for this workspace. A queued or pending request can temporarily reserve part of the available budget.',
    'Queue': 'Jobs run through cron. Waiting and running work is separate from failed jobs; retry a failure only after fixing its cause.',
    'Failed jobs': 'The last recorded error explains why background work stopped. Correct credentials, quotas or configuration before retrying; retries may contact the provider again.',
    'Settings': 'Review each field’s description and save only intended changes. Provider credentials stay encrypted and are not displayed again.',
    'Visibility': 'A relative measure calculated from stored keyword positions. It depends on your tracked keyword set and is not an estimate of all search traffic.',
    'Top pages': 'Leading URLs for the displayed period and source. Open Pages for combined URL-level evidence; a top list is a subset of your full catalog.',
    'Top queries': 'Leading imported searches for the chosen period. Search engines may omit anonymized or low-volume queries.',
    'Competitors': 'Compare stored measurements using the same location, device and date. A competitor gap suggests research, not that every competing keyword belongs on your site.',
    'Reports': 'Reports summarize stored data for their stated period. Review source and coverage notices before sharing a report with a client.',
};
for (const heading of document.querySelectorAll('main h2')) {
    if (heading.closest('summary') || heading.classList.contains('visually-hidden')) continue;
    const title = heading.textContent.trim();
    const plainTitle = [...heading.childNodes].filter((node) => node.nodeType === Node.TEXT_NODE).map((node) => node.textContent.trim()).join(' ').trim();
    const explanation = help[title] ?? help[plainTitle] ?? (/^(Clicks|Impressions) per day/.test(title) ? 'Daily imported search measurements for the displayed period. Hover or focus the chart and use the arrow keys to inspect values. Expand the data table for the underlying figures.' : null);
    if (!explanation) continue;
    const wrapper = document.createElement('div');
    wrapper.className = 'section-heading';
    heading.before(wrapper);
    wrapper.append(heading);
    const details = document.createElement('details');
    details.className = 'section-help';
    const summary = document.createElement('summary');
    summary.textContent = '?';
    summary.setAttribute('aria-label', `Help: ${title}`);
    const paragraph = document.createElement('p');
    paragraph.textContent = explanation;
    details.append(summary, paragraph);
    wrapper.append(details);
    details.addEventListener('keydown', (event) => {
        if (event.key === 'Escape') { details.open = false; summary.focus(); }
    });
    document.addEventListener('click', (event) => { if (!details.contains(event.target)) details.open = false; });
}
