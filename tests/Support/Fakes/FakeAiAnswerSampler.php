<?php

declare(strict_types=1);

namespace SeoControlCenter\Tests\Support\Fakes;

use SeoControlCenter\Domain\Integrations\IntegrationException;
use SeoControlCenter\Domain\Research\AiAnswerSampler;
use SeoControlCenter\Domain\Research\SampledAnswer;

final class FakeAiAnswerSampler implements AiAnswerSampler
{
    public bool $available = true;
    /** @var array<string, SampledAnswer> platform|prompt => answer */
    public array $answers = [];
    /** @var list<array{platform: string, prompt: string}> */
    public array $calls = [];
    /** Throw for this platform|prompt, once. */
    public ?string $failOnce = null;

    public function isAvailable(int $workspaceId): bool
    {
        return $this->available;
    }

    public function estimatedCost(int $answers): float
    {
        return round(0.06 * max(1, $answers), 6);
    }

    public function answer(int $workspaceId, int $projectId, string $platform, string $prompt): SampledAnswer
    {
        $this->calls[] = ['platform' => $platform, 'prompt' => $prompt];
        if ($this->failOnce === $platform . '|' . $prompt) {
            $this->failOnce = null;
            throw new IntegrationException('Provider temporarily unavailable.', true);
        }
        return $this->answers[$platform . '|' . $prompt] ?? new SampledAnswer($platform, $platform . '-model', $prompt, 'No brands are named here.', [], true);
    }
}
