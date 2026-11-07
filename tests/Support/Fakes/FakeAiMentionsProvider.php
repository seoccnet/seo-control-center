<?php

declare(strict_types=1);

namespace SeoControlCenter\Tests\Support\Fakes;

use SeoControlCenter\Domain\Keywords\KeywordLocale;
use SeoControlCenter\Domain\Research\AiAnswer;
use SeoControlCenter\Domain\Research\AiMention;
use SeoControlCenter\Domain\Research\AiMentionsProvider;

/**
 * Scripted AI mentions: tests set mentions per platform and domain and the
 * answers per platform.
 */
final class FakeAiMentionsProvider implements AiMentionsProvider
{
    /** @var array<string, array{mentions: int, ai_search_volume: ?int}> */
    public array $brandMetrics = [];
    /** @var array<string, list<AiAnswer>> */
    public array $brandAnswers = [];
    public ?\Throwable $brandFailure = null;

    public function brandMetrics(int $workspaceId, int $projectId, string $brand, string $platform, KeywordLocale $locale): array
    {
        $this->calls[] = ['call' => 'brandMetrics', 'args' => [$workspaceId, $projectId, $brand, $platform, $locale->key()]];
        if ($this->brandFailure !== null) {
            throw $this->brandFailure;
        }
        return $this->brandMetrics[$platform] ?? ['mentions' => 0, 'ai_search_volume' => null];
    }
    public function brandAnswers(int $workspaceId, int $projectId, string $brand, string $domain, string $platform, KeywordLocale $locale, int $limit): array
    {
        $this->calls[] = ['call' => 'brandAnswers', 'args' => [$workspaceId, $projectId, $brand, $domain, $platform, $limit]];
        if ($this->brandFailure !== null) {
            throw $this->brandFailure;
        }
        return array_slice($this->brandAnswers[$platform] ?? [], 0, $limit);
    }
    public bool $available = true;

    /** @var array<string, array<string, int>> platform => domain => mentions */
    public array $mentions = [];

    /** @var array<string, list<AiAnswer>> platform => answers */
    public array $answers = [];

    /** @var list<array{call: string, args: list<mixed>}> */
    public array $calls = [];

    public function isAvailable(int $workspaceId): bool
    {
        return $this->available;
    }

    public function estimatedCost(int $requests, int $rows = 0): float
    {
        return 0.1 * $requests + 0.001 * $rows;
    }

    public function metrics(int $workspaceId, ?int $projectId, array $domains, string $platform, KeywordLocale $locale): array
    {
        $this->calls[] = ['call' => 'metrics', 'args' => [$domains, $platform, $locale->key()]];
        $result = [];
        foreach ($domains as $domain) {
            if (isset($this->mentions[$platform][$domain])) {
                $result[] = new AiMention($domain, $platform, $this->mentions[$platform][$domain], $this->mentions[$platform][$domain] * 10);
            }
        }

        return $result;
    }

    public function answers(int $workspaceId, ?int $projectId, string $domain, string $platform, KeywordLocale $locale, int $limit): array
    {
        $this->calls[] = ['call' => 'answers', 'args' => [$domain, $platform, $limit]];

        return array_slice($this->answers[$platform] ?? [], 0, $limit);
    }
}
