<?php

declare(strict_types=1);

/** @var SeoControlCenter\Core\Config\Environment $env */

$secure = str_starts_with(strtolower($env->string('APP_URL', 'https://localhost')), 'https://');

return [
    // "__Host-" prefix (bound to this exact host, path "/", HTTPS only) whenever the site runs on HTTPS.
    'cookie' => $secure ? '__Host-scc_session' : 'scc_session',
    'secure' => $secure,
    'idle_timeout' => $env->int('SESSION_IDLE_MINUTES', 120) * 60,
    'absolute_lifetime' => $env->int('SESSION_LIFETIME_HOURS', 168) * 3600,
];
