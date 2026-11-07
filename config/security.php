<?php

declare(strict_types=1);

/** @var SeoControlCenter\Core\Config\Environment $env */

return [
    'hsts' => [
        // Only sent on HTTPS requests. Keep off locally unless you use HTTPS.
        'enabled' => $env->bool('SECURITY_HSTS', in_array($env->string('APP_ENV', 'production'), ['staging', 'production'], true)),
        'max_age' => 31536000,
    ],
];
