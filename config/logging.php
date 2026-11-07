<?php

declare(strict_types=1);

/** @var SeoControlCenter\Core\Config\Environment $env */

return [
    'level' => $env->string('LOG_LEVEL', 'info'),
    // Extra context keys to redact in addition to the built-in list.
    'redact_keys' => [],
];
