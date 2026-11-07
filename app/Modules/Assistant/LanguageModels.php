<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\Assistant;

use SeoControlCenter\Domain\Assistant\LanguageModel;

/**
 * The chat model providers a workspace can choose from (ADR-028):
 * `anthropic` (Claude) and `openai` (GPT). The first is the default.
 */
final readonly class LanguageModels
{
    /**
     * @param array<string, array{label: string, model: LanguageModel}> $providers
     */
    public function __construct(private array $providers) {}

    /**
     * @return array<string, string> provider => label
     */
    public function providers(): array
    {
        return array_map(static fn(array $p): string => $p['label'], $this->providers);
    }

    public function has(string $provider): bool
    {
        return isset($this->providers[$provider]);
    }

    public function get(string $provider): LanguageModel
    {
        return ($this->providers[$provider] ?? throw new \InvalidArgumentException('Unknown AI provider: ' . $provider))['model'];
    }

    public function label(string $provider): string
    {
        return $this->providers[$provider]['label'] ?? $provider;
    }

    /**
     * The provider that offers a model, or null.
     */
    public function providerOf(string $model): ?string
    {
        foreach ($this->providers as $provider => $p) {
            if (isset($p['model']->models()[$model])) {
                return $provider;
            }
        }

        return null;
    }

    public function defaultProvider(): string
    {
        return (string) array_key_first($this->providers);
    }

    /**
     * @return array<string, array<string, string>> provider => model id => label
     */
    public function models(): array
    {
        return array_map(static fn(array $p): array => $p['model']->models(), $this->providers);
    }
}
