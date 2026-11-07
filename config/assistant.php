<?php

declare(strict_types=1);

/** @var SeoControlCenter\Core\Config\Environment $env */

return [
    // Chat model providers and models offered under Integrations → AI assistant; the first of each is the default.
    // Prices are USD per million tokens (list prices; adjust here when the providers change them).
    // They are used for the budget: the providers report tokens, not money.
    'providers' => [
        'anthropic' => [
            'label' => 'Claude (Anthropic)',
            'models' => [
                'claude-sonnet-5-5' => ['label' => 'Claude Sonnet 5.5 (balanced)', 'input' => 2.0, 'cached' => 0.2, 'output' => 10.0],
                'claude-opus-5-5' => ['label' => 'Claude Opus 5.5 (most capable)', 'input' => 4.0, 'cached' => 0.2, 'output' => 20.0],
                'claude-haiku-4-5-20251001' => ['label' => 'Claude Haiku 4.5 (fastest, cheapest)', 'input' => 1.0, 'output' => 5.0],
            ],
        ],
        'openai' => [
            'label' => 'GPT (OpenAI)',
            'models' => [
                // `cached` is the price of input tokens read from OpenAI's prompt cache.
                'gpt-6.1-sol' => ['label' => 'GPT-6.1 Sol (balanced)', 'input' => 2.0, 'cached' => 0.1, 'output' => 10.0],
                'gpt-6-astra' => ['label' => 'GPT-6 Astra (most capable)', 'input' => 10.0, 'cached' => 1.0, 'output' => 50.0],
                'gpt-6-luna' => ['label' => 'GPT-6 Luna (fastest, cheapest)', 'input' => 0.1, 'cached' => 0.01, 'output' => 0.5],
            ],
        ],
    ],
    // Longest answer per model call, in tokens (GPT counts its reasoning in it, too).
    'max_tokens' => $env->int('ASSISTANT_MAX_TOKENS', 4096),
    // Seconds to wait for one model call (one call per browser request, ADR-028).
    'timeout' => $env->int('ASSISTANT_TIMEOUT', 60),
];
