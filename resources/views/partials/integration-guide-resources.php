<?php

declare(strict_types=1);

// Public teaching assets only. Source URLs and licensing are recorded in docs/integration-tutorial-assets.md.
$cloudSource = 'https://codelabs.developers.google.com/call-apis-from-a-google-cloud-project';
$searchSource = 'https://developers.google.com/search/blog/2019/02/announcing-domain-wide-data-in-search';
$libraryImage = ['google-api-library.png', 440, 52, 'Google Cloud: Enable APIs and Services', 'Open the API library, then search for the API named in this step.', $cloudSource];
$serviceImage = ['google-service-account.png', 510, 564, 'Google Cloud Create service account screen', 'Create a dedicated account. Project roles are optional; GA4 access is granted separately in Analytics.', $cloudSource];
$keyImage = ['google-api-key.png', 812, 510, 'Google Cloud Create credentials menu with API key selected', 'Choose API key, then restrict it to Chrome UX Report API and your server’s outgoing IP.', $cloudSource];
$guideResources = [
    'google' => [
        'needs' => ['A verified Search Console property', 'A Google account with property access', 'A configured Google OAuth app in SEOcc'],
        'steps' => [
            0 => ['links' => [['https://search.google.com/search-console', 'Open Search Console'], ['https://support.google.com/webmasters/answer/9008080?hl=en', 'Verification instructions']], 'image' => ['search-console-property.png', 500, 512, 'Google Search Console welcome screen for a Domain property', 'Google’s Domain property example: one property includes HTTP, HTTPS and subdomains. This is an illustrative screen from Google’s documentation.', $searchSource]],
            1 => ['links' => [['/system/integrations/google', 'Administrator settings · Google app']]],
            3 => ['links' => [['/projects', 'Choose your SEOcc project']]],
        ],
        'demo' => ['https://support.google.com/webmasters/answer/9128669?hl=en', 'Google Search Console training', 'Google’s guided examples explain the reports. Use your own verified property when connecting SEOcc.'],
    ],
    'ga4' => [
        'needs' => ['An existing GA4 property collecting website data', 'Permission to create a Cloud service account key', 'Analytics Administrator access to add a property user'],
        'steps' => [
            0 => ['links' => [['https://console.cloud.google.com/apis/library/analyticsdata.googleapis.com', 'Open Google Analytics Data API']], 'image' => $libraryImage],
            1 => ['links' => [['https://console.cloud.google.com/iam-admin/serviceaccounts', 'Open service accounts']], 'image' => $serviceImage],
            2 => ['links' => [['https://docs.cloud.google.com/iam/docs/keys-create-delete', 'Google’s JSON key instructions']]],
            3 => ['links' => [['https://analytics.google.com/', 'Open Google Analytics']]],
            4 => ['links' => [['/integrations/ga4', 'Open SEOcc GA4 settings']]],
            5 => ['links' => [['https://developers.google.com/analytics/devguides/reporting/data/v1/property-id', 'Find the property ID'], ['/projects', 'Choose your SEOcc project']]],
        ],
        'demo' => ['https://support.google.com/analytics/answer/6367342?hl=en', 'Explore Google’s Analytics demo', 'Use Google’s sample reports to learn the interface. The demo account does not support the Analytics Data API and cannot be linked to SEOcc.'],
    ],
    'crux' => [
        'needs' => ['A Google Cloud project', 'Permission to enable an API and create a key', 'Your hosting server’s outgoing public IP'],
        'steps' => [
            0 => ['links' => [['https://console.cloud.google.com/apis/library/chromeuxreport.googleapis.com', 'Open Chrome UX Report API']], 'image' => $libraryImage],
            1 => ['links' => [['https://console.cloud.google.com/apis/credentials', 'Open Google credentials'], ['https://docs.cloud.google.com/api-keys/docs/add-restrictions-api-keys', 'Google’s restriction instructions']], 'image' => $keyImage, 'note' => 'Ask your host for the outbound IP used by PHP requests from this installation. A shared hosting account’s displayed IP can differ. Website restrictions check browser referrers; SEOcc calls Google from its server. Changes can take a few minutes to apply.'],
            2 => ['links' => [['/integrations/web-vitals', 'Open SEOcc Core Web Vitals settings']]],
            3 => ['links' => [['/projects', 'Choose your SEOcc project']]],
        ],
        'demo' => ['https://pagespeed.web.dev/', 'Try Google PageSpeed Insights', 'Enter a public URL to see Google’s field and lab reports. Field data depends on Chrome coverage; a lab result is a separate simulated visit.'],
    ],
    'google-app' => [
        'needs' => ['SEOcc system administrator access', 'A Google Cloud project', 'The exact callback URL shown by SEOcc'],
        'steps' => [
            0 => ['links' => [['https://console.cloud.google.com/apis/library/searchconsole.googleapis.com', 'Open Google Search Console API']], 'image' => $libraryImage],
            1 => ['links' => [['https://console.cloud.google.com/auth/overview', 'Open Google Auth Platform']]],
            2 => ['links' => [['https://console.cloud.google.com/auth/clients', 'Open OAuth clients']]],
            3 => ['links' => [['/integrations', 'Open SEOcc Integrations']]],
        ],
    ],
];
