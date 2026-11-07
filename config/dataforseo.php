<?php

declare(strict_types=1);

/** @var SeoControlCenter\Core\Config\Environment $env */

return [
    // Use DataForSEO's free sandbox (sandbox.dataforseo.com): fixed sample data, no charges.
    'sandbox' => $env->bool('DATAFORSEO_SANDBOX', false),
    // Days raw DataForSEO responses are kept for diagnostics and reprocessing.
    'raw_response_days' => $env->int('DATAFORSEO_RAW_RESPONSE_DAYS', 7),
];
