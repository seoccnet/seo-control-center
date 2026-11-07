<?php

declare(strict_types=1);

namespace SeoControlCenter\Domain\Rankings;

/**
 * The project's ranking for one keyword, device and day, derived from a
 * SERP. `position` is null when the domain is not in the checked results.
 */
final readonly class RankSnapshot
{
    /**
     * @param list<string> $features SERP feature types on the page
     * @param list<array{position: int, domain: string, url: string}> $top first organic results (for competitor analysis)
     */
    public function __construct(
        public int $projectKeywordId,
        public int $projectId,
        public SerpDevice $device,
        public string $checkedOn,
        public ?int $position,
        public ?int $absolutePosition,
        public ?string $url,
        public array $features,
        public array $top,
    ) {}

    public static function fromSerp(int $projectKeywordId, int $projectId, string $domain, string $checkedOn, SerpSnapshot $serp, int $topCount = 10): self
    {
        $own = $serp->resultFor($domain);

        return new self(
            $projectKeywordId,
            $projectId,
            $serp->device,
            $checkedOn,
            $own?->position,
            $own?->absolutePosition,
            $own?->url,
            $serp->features,
            array_map(
                static fn(SerpResult $r): array => ['position' => $r->position, 'domain' => $r->domain, 'url' => $r->url],
                array_slice($serp->organic, 0, $topCount),
            ),
        );
    }
}
