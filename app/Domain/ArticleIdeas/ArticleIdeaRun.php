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
    ) {}

    public function active(): bool
    {
        return in_array($this->status, [self::QUEUED, self::RUNNING], true);
    }
}
