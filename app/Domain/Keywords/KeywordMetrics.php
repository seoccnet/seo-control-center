<?php

declare(strict_types=1);

namespace SeoControlCenter\Domain\Keywords;

/**
 * Provider-independent keyword metrics for one keyword and locale. Every
 * metric is optional: providers have no data for rare keywords.
 */
final readonly class KeywordMetrics
{
    /**
     * @param int|null $competition 0-100 (advertiser competition index)
     * @param list<array{year: int, month: int, volume: int|null}> $monthly newest first
     */
    public function __construct(
        public string $keyword,
        public ?int $searchVolume,
        public ?float $cpc,
        public ?int $competition,
        public ?string $competitionLevel,
        public array $monthly = [],
    ) {}

    public static function empty(string $keyword): self
    {
        return new self($keyword, null, null, null, null);
    }
}
