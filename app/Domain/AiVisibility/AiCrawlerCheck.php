<?php

declare(strict_types=1);

namespace SeoControlCenter\Domain\AiVisibility;

/**
 * The latest robots.txt and llms.txt check of a project (0.43.0).
 *
 * robotsState: ok (read), missing (4xx: everything allowed) or unreachable
 * (5xx, 429, timeout: crawlers may pause, agents are not evaluated).
 * llmsState: found, missing or unreachable.
 */
final readonly class AiCrawlerCheck
{
    public const array ROBOTS_STATES = ['ok', 'missing', 'unreachable'];
    public const array LLMS_STATES = ['found', 'missing', 'unreachable'];

    /**
     * @param list<array{token: string, state: string, named: bool, blocked: list<string>}> $agents empty when robots.txt was unreachable
     */
    public function __construct(
        public int $projectId,
        public string $robotsUrl,
        public string $robotsState,
        public ?int $robotsStatus,
        public array $agents,
        public string $llmsState,
        public ?int $llmsStatus,
        public ?int $llmsBytes,
        public string $checkedAt,
    ) {}

    /**
     * Search crawlers robots.txt blocks, wholly or for some top pages.
     *
     * @return list<array{token: string, state: string, named: bool, blocked: list<string>}>
     */
    public function blockedSearch(): array
    {
        return array_values(array_filter($this->agents, static fn(array $a): bool => AiCrawlers::isSearch($a['token']) && $a['state'] !== AiCrawlers::ALLOWED));
    }

    /** @return list<array{token: string, state: string, named: bool, blocked: list<string>}> */
    public function ofKind(string $kind): array
    {
        return array_values(array_filter($this->agents, static fn(array $a): bool => (AiCrawlers::AGENTS[$a['token']]['kind'] ?? null) === $kind));
    }
}
