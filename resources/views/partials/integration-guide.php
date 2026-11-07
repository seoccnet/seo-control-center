<?php

declare(strict_types=1);

/** @var SeoControlCenter\Core\View\Template $view */
/** @var string $guide */
/** @var bool|null $open */
$guides = [
    'crux' => [
        'title' => 'Core Web Vitals setup · Google CrUX',
        'intro' => 'Read real Chrome visitor measurements for individual pages on mobile and desktop. Connect a Chrome UX Report API key, then request a check from Pages or Site audit.',
        'steps' => [
            ['Enable the correct API', 'In Google Cloud Console, select the project that will own the key. Open APIs & Services → Library, search for Chrome UX Report API and enable it. For free speed tests (Improve → Speed tests), also enable PageSpeed Insights API; it is a separate API.'],
            ['Create and restrict an API key', 'Open APIs & Services → Credentials → Create credentials → API key. Edit the key and restrict its allowed APIs to Chrome UX Report API, plus PageSpeed Insights API if you run speed tests. SEOcc sends requests from your server: do not use Websites/HTTP-referrer restrictions. If you use IP restrictions, enter the hosting server’s outgoing public IP, confirmed by your host.'],
            ['Save the key in this workspace', 'Paste the key into the API key field and save. SEOcc stores it encrypted. Saving alone does not test access. On this form, submitting a blank key removes the saved key.'],
            ['Check mobile and desktop separately', 'Open Project → Pages and choose a URL. Under Core Web Vitals, use Check mobile Web Vitals or Check desktop Web Vitals. After cron runs, refresh the page to see LCP, INP and CLS and the collection period.'],
            ['Keep measurements up to date', 'Use the page’s weekly watch control if you want scheduled checks. Mobile and desktop have separate results and history. A Lighthouse check in Site audit is a separate paid DataForSEO diagnostic.'],
        ],
        'verify' => 'A completed check shows field metrics or “No data” with a check time. CrUX covers a rolling 28-day period. Missing URL or device coverage is neither a pass nor a fail; SEOcc does not substitute origin-wide data.',
        'troubleshooting' => ['HTTP 403: check that the API is enabled in the key’s project, that API restrictions allow Chrome UX Report API (and PageSpeed Insights API for speed tests) and that server requests are not blocked by referrer or IP restrictions.', 'HTTP 429: the Google project quota is exhausted; wait before requesting another check.', 'No result yet: inspect System → Jobs and confirm cron is running. Fix the cause before retrying a failed job.'],
        'links' => [['https://console.cloud.google.com/', 'Google Cloud Console'], ['https://developer.chrome.com/docs/crux/api', 'Official CrUX documentation']],
    ],
    'ga4' => [
        'title' => 'Google Analytics 4 setup · read-only reporting',
        'intro' => 'You need access to the GA4 property and a Google Cloud service account. This is separate from the Google Search Console connection.',
        'steps' => [
            ['Enable the reporting API', 'In Google Cloud Console, select a project. Open APIs & Services → Library and enable Google Analytics Data API.'],
            ['Create a dedicated service account', 'Open IAM & Admin → Service Accounts and create an account for SEOcc. Copy its email, which ends in .iam.gserviceaccount.com. Cloud project Administrator or Editor roles are not needed to read GA4 reports.'],
            ['Download its JSON key', 'Open the service account → Keys → Add key → Create new key → JSON. Store the downloaded file securely. Google cannot download the same private key again; if your organization disables key creation, contact its administrator.'],
            ['Give that account Viewer access in Analytics', 'In Google Analytics, select the correct property. Open Admin → Property access management → + → Add users. Enter the service-account email and select Viewer. Your personal Gmail address and Cloud IAM roles do not grant this account access to the property.'],
            ['Save the credential in SEOcc', 'Paste the complete JSON into the Service-account JSON field and save. SEOcc encrypts it. A blank submission disconnects GA4; do not submit an empty form to test the connection.'],
            ['Link the numeric property ID and import', 'In Google Analytics, open Admin → Property details and copy the numeric Property ID. In SEOcc, open Project → Analytics → Property and history, enter that ID and choose Link and import. Use a number such as 123456789, not a G- measurement ID.'],
        ],
        'verify' => 'After cron completes the import, Analytics shows the snapshot dates, timezone, currency and Organic Search landing-page sessions, key events and purchase revenue. These results also appear in Pages and reports.',
        'troubleshooting' => ['Access denied: grant the service account Viewer access to the same property ID entered in SEOcc.', 'Empty report: confirm the property collects data for this domain and has Organic Search sessions. Key events and purchase revenue depend on your GA4 event setup.', 'A blank credential submission disconnects GA4 while retaining imported data. Changing the project’s property clears the previous snapshot.'],
        'links' => [['https://console.cloud.google.com/', 'Google Cloud Console'], ['https://analytics.google.com/', 'Google Analytics'], ['https://developers.google.com/analytics/devguides/reporting/data/v1/quickstart-client-libraries', 'Official GA4 API setup']],
    ],
    'google' => [
        'title' => 'Connect Google Search Console · account and property',
        'intro' => 'Connect your Google account, choose its verified website property, and import search performance. You need access to the property and a configured SEOcc Google app.',
        'steps' => [
            ['Add or confirm the website property', 'Open Search Console’s property selector → Add property. A Domain property covers protocols and subdomains and requires DNS verification. A URL-prefix property covers the exact prefix entered. Complete verification, or use an account already granted access to the property.'],
            ['Check installation setup', 'If SEOcc says Google is not configured, an installation administrator must configure System → Google app. Workspace users cannot replace that installation-wide OAuth client.'],
            ['Connect your Google account', 'Use Connect a Google account, select the correct account and approve the requested read-only access. Return here to review the discovered properties.'],
            ['Link the property to a project', 'Open Project → Settings → Search Console. Select the property matching your website and save. The import runs in the queue; initial history can take several cron runs. Confirm the date range under Performance.'],
        ],
        'verify' => 'Project → Performance shows the last import time and available date range. Page loads use stored data rather than making Google API requests.',
        'troubleshooting' => ['Property missing: confirm the connected account has access, then refresh properties.', 'Revoked or expired token: reconnect the Google account. A Google OAuth app in Testing may have short-lived refresh access.', 'No new data: check import status and cron. Recently collected search data may not yet be finalized by Google.'],
        'links' => [['https://search.google.com/search-console', 'Google Search Console'], ['https://developers.google.com/webmaster-tools/v1/how-tos/authorizing', 'Official authorization documentation']],
    ],
    'google-app' => [
        'title' => 'Administrator tutorial · Google OAuth client',
        'intro' => 'This installation-wide client lets workspace users connect their Google accounts. Use an OAuth Web application client, not an API key or GA4 service account.',
        'steps' => [
            ['Create a Cloud project and enable the API', 'In Google Cloud Console, choose the installation’s project and enable Google Search Console API from the API library.'],
            ['Configure the consent screen', 'Configure Google Auth Platform/OAuth consent with the app name, support email and audience. Request webmasters.readonly along with sign-in identity scopes. In Testing, add the intended Google account as a test user. Follow Google’s publishing and verification requirements for your audience.'],
            ['Create a Web application OAuth client', 'Open the OAuth clients/credentials screen and create a Web application client. Add the exact authorized redirect URI shown in the form below, including HTTPS, hostname and callback path.'],
            ['Save and connect an account', 'Enter the client ID and secret below. A blank secret keeps an existing secret. Then open Integrations and connect the Google account; link each project to its property.'],
        ],
        'verify' => 'A successful connection returns to Integrations and lists the account’s accessible properties. The OAuth client itself contains no search performance data.',
        'troubleshooting' => ['redirect_uri_mismatch: copy the exact redirect URI shown by SEOcc into the same OAuth client in Google Cloud.', 'access_denied: verify the app audience, test-user access and Google account permissions.', 'Testing-mode refresh tokens can expire after seven days. Choose the appropriate publishing state according to Google’s requirements.'],
        'links' => [['https://console.cloud.google.com/', 'Google Cloud Console'], ['https://developers.google.com/identity/protocols/oauth2/web-server', 'Official OAuth setup']],
    ],
    'bing' => [
        'title' => 'Bing Webmaster Tools setup',
        'intro' => 'A Bing API key belongs to your Bing Webmaster account and covers its verified sites. Search Console credentials do not connect Bing automatically.',
        'steps' => [
            ['Add and verify the website in Bing', 'Open Bing Webmaster Tools and add your website, or import its verification from Search Console. Confirm that the site is verified for this account.'],
            ['Generate the API key', 'Open Settings → API Access → API Key. Follow Bing’s terms and key-generation steps yourself, then copy the resulting key.'],
            ['Connect the workspace', 'Paste the key into the SEOcc form and save. SEOcc checks the key, stores it encrypted and lists the account’s verified sites.'],
            ['Link each project to a site', 'Open the project settings, choose its verified Bing site and save. The import runs in the background; refresh after cron has completed it.'],
        ],
        'verify' => 'The account shows Connected with verified sites, and Project → Performance includes imported Bing data. Weekly query/page statistics differ from daily totals.',
        'troubleshooting' => ['No verified sites: verify the site in Bing, then refresh sites in SEOcc.', 'Access refused: confirm that the API key belongs to the correct account and has not been replaced.', 'Linking a different Bing site replaces the project’s previously imported Bing data.'],
        'links' => [['https://www.bing.com/webmasters', 'Bing Webmaster Tools'], ['https://learn.microsoft.com/en-us/bingwebmaster/getting-access', 'Official API access instructions']],
    ],
    'dataforseo' => [
        'title' => 'DataForSEO setup · credentials and budgets',
        'intro' => 'SEOcc uses your own DataForSEO API account. Paid checks consume provider credit; the workspace’s budgets control requests made through SEOcc.',
        'steps' => [
            ['Find the API credentials', 'Open your DataForSEO dashboard → API Access. Copy the API login and API password. The API password is different from the dashboard sign-in password.'],
            ['Save the account in SEOcc', 'Enter the API login and password in the connection form. SEOcc stores the password encrypted; it is not displayed again.'],
            ['Choose practical spending limits', 'Set daily, monthly and per-job limits before requesting rankings or research. Budgets apply only to requests made through SEOcc, not other applications using the same provider account.'],
            ['Test and start one small check', 'Use Test connection to verify account access and balance. In a project, review the location, device and estimated cost before starting a limited rank or research check.'],
        ],
        'verify' => 'The connection displays its checked status and balance. Provider requests and costs appear in workspace Usage; job results appear in their project sections.',
        'troubleshooting' => ['Access refused: check the API password, account verification and provider account status.', 'Insufficient balance or budget: review available provider credit and SEOcc’s limits before retrying.', 'A connection test is different from a paid ranking or research check. Never assume a scheduled feature is free.'],
        'links' => [['https://app.dataforseo.com/api-access', 'DataForSEO API Access'], ['https://docs.dataforseo.com/v3/auth/', 'Official API authentication']],
    ],
    'assistant' => [
        'title' => 'Ask AI setup · provider, model and budget',
        'intro' => 'Ask AI uses your own Claude or OpenAI API account. It reads authorized SEOcc evidence and counts model calls against this workspace’s AI budget.',
        'steps' => [
            ['Choose an API provider', 'Use a Claude/Anthropic or OpenAI API account with access to the desired model. A subscription to the provider’s chat website does not automatically supply API credit.'],
            ['Create an API key in the provider console', 'Create a key for this installation using the provider’s own account controls. Copy it directly into SEOcc; do not share it with clients or paste it into an external chat.'],
            ['Choose a model and limits', 'Select the matching provider and an available model from SEOcc’s list. Set daily, monthly and per-request budgets that fit your expected usage.'],
            ['Save and ask a focused question', 'Save the settings, then open a project or URL and use Ask AI. Start with a question about the stored evidence. Review the explanation and confirm any supported write action before it runs.'],
        ],
        'verify' => 'Ask AI returns an answer based on the current user’s authorized projects. The provider’s response can fail independently of the stored SEO dashboard.',
        'troubleshooting' => ['Authentication or model error: check that the key, provider and model match and that the API account has access.', 'Budget or quota error: check SEOcc’s limits as well as the provider account’s usage and credit.', 'External MCP access is configured separately under AI access; it does not require sharing the assistant provider key.'],
        'links' => [['https://console.anthropic.com/', 'Anthropic Console'], ['https://platform.openai.com/api-keys', 'OpenAI API keys']],
    ],
    'mcp' => [
        'title' => 'Connect an external AI client · MCP access',
        'intro' => 'Give an AI client explicit access to selected workspace data. This is separate from SEOcc’s built-in Ask AI provider settings.',
        'steps' => [
            ['Choose the minimum data access', 'Select only the scopes the client needs. Pages access enables pages_list and page_details; older tokens do not gain it automatically. Choose selected projects where possible.'],
            ['Choose a supported connection method', 'For a client that supports remote HTTP MCP with bearer tokens, create a named token with an appropriate expiry. For supported OAuth clients, use the SEOcc server URL and approve the sign-in connection yourself.'],
            ['Enter the server URL and token in the client', 'Use the exact MCP endpoint shown on this page. Copy a newly created token directly into the intended client; the complete token is shown only once.'],
            ['Verify and review access', 'Ask the client to list the authorized projects or read a stored page. Review its scope and project restrictions. Revoke unused connections from the token list.'],
        ],
        'verify' => 'The client sees only the tools allowed by the approved scopes and the projects allowed by both the token and its creator’s current membership.',
        'troubleshooting' => ['Tool missing: check the token’s approved scopes. Existing tokens do not gain new permissions automatically.', 'Project not found: check selected-project restrictions and the token creator’s project membership.', 'Authentication failed: check the endpoint, token expiry/revocation and the client’s remote HTTP/OAuth support.'],
        'links' => [],
    ],
    'indexnow' => [
        'title' => 'IndexNow setup · website key file and submissions',
        'intro' => 'IndexNow notifies supporting search engines when your website’s URLs change. A successful notification does not guarantee indexing or a ranking improvement.',
        'steps' => [
            ['Generate the project key', 'Open project Settings → IndexNow and choose Set up IndexNow. SEOcc generates a key and shows the exact filename and HTTPS address required on your website.'],
            ['Publish the key file on your website', 'Choose Download key file and upload it to the website root, for example public_html in cPanel. For WordPress websites, including sites in a multisite network, the companion plugin can serve the key URL instead: install its ZIP, paste the SEOcc key and save, then check the link. Keep the filename and plain-text content unchanged. The displayed HTTPS address must open publicly.'],
            ['Verify before submitting URLs', 'Use the available verification control and correct any file-access or key mismatch error. SEOcc cannot publish this file onto your separate website for you.'],
            ['Submit changed URLs deliberately', 'Choose the intended sitemap or URLs and start a submission. Check its stored status after cron runs. Notify meaningful updates rather than repeatedly submitting unchanged pages.'],
        ],
        'verify' => 'The key-file verification succeeds and the submission history records the provider’s result for the requested URLs.',
        'troubleshooting' => ['Verification failed: open the key-file URL and check HTTPS, status 200 and exact plain-text content.', 'URL refused: check that it belongs to the configured project host.', 'Accepted notification: inspect actual search-engine indexing separately; acceptance is not an indexing guarantee.'],
        'links' => [['https://www.indexnow.org/documentation', 'Official IndexNow documentation']],
    ],
];
$tutorial = $guides[$guide] ?? null;
if ($tutorial === null) {
    return;
}
require __DIR__ . '/integration-guide-resources.php';
$resources = $guideResources[$guide] ?? [];
?>
<details class="card setup-guide" id="setup-<?= $view->e($guide) ?>"<?= ($open ?? false) ? ' open' : '' ?>>
    <summary><?= $view->icon('plug') ?><span><?= $view->e($tutorial['title']) ?> · step-by-step guide</span></summary>
    <div class="setup-guide-body">
        <div class="setup-guide-main"><p class="setup-intro"><?= $view->e($tutorial['intro']) ?></p>
        <?php if ($resources !== []): ?><p class="muted small">Google screenshots are reference examples. Setup links open the current Google screens.</p><?php endif; ?>
        <?php if (isset($resources['needs'])): ?><section class="setup-requirements"><h2>Before you start</h2><ul><?php foreach ($resources['needs'] as $need): ?><li><?= $view->e($need) ?></li><?php endforeach; ?></ul></section><?php endif; ?>
        <ol class="setup-steps">
            <?php foreach ($tutorial['steps'] as $index => [$step, $description]): $stepResources = $resources['steps'][$index] ?? []; ?>
            <li><strong><?= $view->e($step) ?></strong><p><?= $view->e($description) ?></p>
                <?php if (isset($stepResources['note'])): ?><p class="setup-note"><?= $view->e($stepResources['note']) ?></p><?php endif; ?>
                <?php if (isset($stepResources['links'])): ?><div class="setup-step-links"><?php foreach ($stepResources['links'] as [$url, $label]): ?><a href="<?= $view->e($url) ?>"<?= str_starts_with($url, 'https://') ? ' target="_blank" rel="noopener noreferrer"' : '' ?>><?= $view->e($label) ?><?= str_starts_with($url, 'https://') ? ' ↗' : '' ?></a><?php endforeach; ?></div><?php endif; ?>
                <?php if (isset($stepResources['image'])): [$file, $width, $height, $alt, $caption, $source] = $stepResources['image']; ?>
                <figure class="setup-screenshot"><a href="/assets/images/integrations/<?= $view->e($file) ?>" target="_blank" rel="noopener noreferrer" aria-label="<?= $view->e('View full screenshot: ' . $alt) ?>"><img src="/assets/images/integrations/<?= $view->e($file) ?>" width="<?= $view->e($width) ?>" height="<?= $view->e($height) ?>" alt="<?= $view->e($alt) ?>" loading="lazy" decoding="async"></a><figcaption><?= $view->e($caption) ?> <a href="<?= $view->e($source) ?>" target="_blank" rel="noopener noreferrer">Google screenshot</a> · <a href="https://creativecommons.org/licenses/by/4.0/" target="_blank" rel="noopener noreferrer">CC BY 4.0</a> · unmodified</figcaption></figure>
                <?php endif; ?>
            </li><?php endforeach; ?>
        </ol></div>
        <aside class="setup-guide-aside" aria-label="Check and get help"><section class="setup-guide-tile"><h2>Confirm the connection</h2><p><?= $view->e($tutorial['verify']) ?></p></section><section class="setup-guide-tile"><h2>Troubleshooting</h2><ul>
            <?php foreach ($tutorial['troubleshooting'] as $tip): ?><li><?= $view->e($tip) ?></li><?php endforeach; ?>
        </ul></section><?php if (isset($resources['demo'])): [$demoUrl, $demoLabel, $demoDescription] = $resources['demo']; ?><section class="setup-guide-tile setup-demo"><h2>See an example</h2><p><?= $view->e($demoDescription) ?></p><a href="<?= $view->e($demoUrl) ?>" target="_blank" rel="noopener noreferrer"><?= $view->e($demoLabel) ?> ↗</a></section><?php endif; ?><?php if ($tutorial['links'] !== []): ?><section class="setup-guide-tile"><h2>Official documentation</h2><?php foreach ($tutorial['links'] as [$url, $label]): ?><p><a href="<?= $view->e($url) ?>" target="_blank" rel="noopener noreferrer"><?= $view->e($label) ?> ↗</a></p><?php endforeach; ?></section><?php endif; ?></aside>
    </div>
</details>
