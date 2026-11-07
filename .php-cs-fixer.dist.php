<?php

declare(strict_types=1);

$finder = PhpCsFixer\Finder::create()
    ->in([__DIR__ . '/app', __DIR__ . '/bootstrap', __DIR__ . '/config', __DIR__ . '/database', __DIR__ . '/routes', __DIR__ . '/tests', __DIR__ . '/public'])
    ->append([__DIR__ . '/bin/console', __DIR__ . '/bin/sync-ai-guide.php', __DIR__ . '/.php-cs-fixer.dist.php'])
    ->exclude(['views']);

return (new PhpCsFixer\Config())
    ->setRiskyAllowed(true)
    ->setCacheFile(__DIR__ . '/storage/cache/.php-cs-fixer.cache')
    ->setRules([
        '@PER-CS' => true,
        'declare_strict_types' => true,
        'ordered_imports' => ['imports_order' => ['class', 'function', 'const']],
        'no_unused_imports' => true,
        'single_quote' => true,
        'trailing_comma_in_multiline' => true,
    ])
    ->setFinder($finder);
