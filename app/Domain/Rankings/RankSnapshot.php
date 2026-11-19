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
     * @param ?string $snippetDomain the site holding the featured snippet (1.5.0)
     * @param list<string> $questions "People also ask" questions (1.5.0)
     * @param ?string $shownTitle the title Google showed for the own result (1.7.0)
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
        public ?string $snippetDomain = null,
        public array $questions = [],
        public ?string $shownTitle = null,
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
            $serp->snippetDomain,
            $serp->questions,
            $own?->title !== null && trim($own->title) !== '' ? mb_substr(trim($own->title), 0, 300) : null,
        );
    }
}
