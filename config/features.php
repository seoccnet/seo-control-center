<?php

declare(strict_types=1);

/** @var SeoControlCenter\Core\Config\Environment $env */

return [
    // Comma-separated Feature values to turn off, for example "backlinks,mcp". Empty: everything is on.
    'disabled' => array_values(array_filter(array_map(trim(...), explode(',', $env->string('DISABLED_FEATURES', ''))), static fn(string $name): bool => $name !== '')),
];
