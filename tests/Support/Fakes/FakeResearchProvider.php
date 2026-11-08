<?php

declare(strict_types=1);

namespace SeoControlCenter\Tests\Support\Fakes;

use SeoControlCenter\Domain\Integrations\IntegrationException;
use SeoControlCenter\Domain\Keywords\KeywordLocale;
use SeoControlCenter\Domain\Research\ResearchDomain;
use SeoControlCenter\Domain\Research\ResearchKeyword;
use SeoControlCenter\Domain\Research\ResearchProvider;

/**
 * Scripted keyword research: tests set the rows each lookup returns and
 * can make the next lookup fail.
 */
final class FakeResearchProvider implements ResearchProvider
{
    public bool $available = true;

    /** @var list<ResearchKeyword> */
    public array $ranked = [];

    /** @var array<string, list<ResearchKeyword>> by "domain|location:language", before $rankedFor */
    public array $rankedIn = [];

    /** @var array<string, list<ResearchKeyword>> by domain, before $ranked */
    public array $rankedFor = [];

    /** @var array<string, list<ResearchKeyword>> by competitor domain */
    public array $gaps = [];

    /** @var list<ResearchKeyword> */
    public array $ideas = [];

    /** @var array<string, list<ResearchKeyword>> by seed */
    public array $suggestions = [];

    /** @var list<ResearchDomain> */
    public array $competitors = [];

    public ?IntegrationException $fail = null;

    /** @var list<array{call: string, args: list<mixed>}> */
    public array $calls = [];

    public function isAvailable(int $workspaceId): bool
    {
        return $this->available;
    }

    public function estimatedCost(int $items, int $requests = 1): float
    {
        return 0.012 * $requests + 0.00012 * $items;
    }

    public function rankedKeywords(int $workspaceId, ?int $projectId, string $domain, KeywordLocale $locale, int $limit): array
    {
        $this->call('ranked', [$domain, $locale->key(), $limit]);

        return $this->rankedIn[$domain . '|' . $locale->key()] ?? $this->rankedFor[$domain] ?? $this->ranked;
    }

    public function keywordGap(int $workspaceId, ?int $projectId, string $domain, string $competitor, KeywordLocale $locale, int $limit): array
    {
        $this->call('gap', [$domain, $competitor, $locale->key()]);

        return $this->gaps[$competitor] ?? [];
    }

    public function keywordIdeas(int $workspaceId, ?int $projectId, array $seeds, KeywordLocale $locale, int $limit): array
    {
        $this->call('ideas', [$seeds, $locale->key()]);

        return $this->ideas;
    }

    public function keywordSuggestions(int $workspaceId, ?int $projectId, string $seed, KeywordLocale $locale, int $limit): array
    {
        $this->call('suggestions', [$seed, $locale->key(), $limit]);

        return $this->suggestions[$seed] ?? [];
    }

    public function competitors(int $workspaceId, ?int $projectId, string $domain, KeywordLocale $locale, int $limit): array
    {
        $this->call('competitors', [$domain]);

        return $this->competitors;
    }

    public function overview(int $workspaceId, ?int $projectId, array $domains, KeywordLocale $locale): array
    {
        $this->call('overview', [$domains]);

        return array_map(static fn(string $d): ResearchDomain => new ResearchDomain($d, 100, 5, 20, 1500.0), $domains);
    }

    /**
     * @param list<mixed> $args
     */
    private function call(string $call, array $args): void
    {
        $this->calls[] = ['call' => $call, 'args' => $args];
        if ($this->fail !== null) {
            $error = $this->fail;
            $this->fail = null;

            throw $error;
        }
    }
}
