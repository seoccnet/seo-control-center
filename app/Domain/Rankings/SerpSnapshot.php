<?php

declare(strict_types=1);

namespace SeoControlCenter\Domain\Rankings;

use SeoControlCenter\Domain\Keywords\KeywordLocale;

/**
 * A search result page as seen at one moment: organic results in order and
 * the SERP feature types present (e.g. featured_snippet, people_also_ask),
 * plus Google's AI Overview when the page shows one.
 */
final readonly class SerpSnapshot
{
    /**
     * @param list<SerpResult> $organic ordered by position
     * @param list<string> $features distinct non-organic element types
     */
    public function __construct(
        public string $keyword,
        public KeywordLocale $locale,
        public SerpDevice $device,
        public string $checkedAt,
        public ?int $totalResults,
        public array $organic,
        public array $features,
        public ?AiOverview $aiOverview = null,
    ) {}

    /**
     * Best organic position of a domain (exact host or its subdomains), or null.
     */
    public function positionOf(string $domain): ?int
    {
        return $this->resultFor($domain)?->position;
    }

    /**
     * First organic result of a domain (exact host or its subdomains), or null.
     */
    public function resultFor(string $domain): ?SerpResult
    {
        $domain = strtolower($domain);
        foreach ($this->organic as $result) {
            $host = strtolower($result->domain);
            if ($host === $domain || str_ends_with($host, '.' . $domain)) {
                return $result;
            }
        }

        return null;
    }
}
