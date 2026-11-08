<?php

declare(strict_types=1);

namespace SeoControlCenter\Domain\ArticleIdeas;

/** One paid lookup of competitors' ranked keywords (ADR-041, G1). */
final readonly class ArticleIdeaRun
{
    public const string QUEUED = 'queued';
    public const string RUNNING = 'running';
    public const string DONE = 'done';
    public const string FAILED = 'failed';

    /**
     * @param list<array{domain: string, found: bool}> $competitors
     * @param list<string> $markets locale keys bought by the run, first = $market; empty on runs saved before markets
     */
    public function __construct(
        public int $id,
        public string $publicId,
        public int $projectId,
        public int $workspaceId,
        public string $status,
        public ?string $market,
        public array $competitors,
        public float $estimatedCost,
        public ?string $error,
        public string $createdAt,
        public ?string $finishedAt,
        public array $markets = [],
    ) {}

    public function active(): bool
    {
        return in_array($this->status, [self::QUEUED, self::RUNNING], true);
    }

    /**
     * The run's markets: the stored list, or the one market of a run saved
     * before markets were stored; empty when it has none.
     *
     * @return list<string>
     */
    public function marketKeys(): array
    {
        return $this->markets !== [] ? $this->markets : ($this->market !== null ? [$this->market] : []);
    }
}
