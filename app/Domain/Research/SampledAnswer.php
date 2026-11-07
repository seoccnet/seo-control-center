<?php

declare(strict_types=1);

namespace SeoControlCenter\Domain\Research;

/**
 * One AI model's answer to one prompt, as returned once by the provider.
 * Answers vary between runs; this is a sample, not a measurement of how
 * often the model mentions anything.
 */
final readonly class SampledAnswer
{
    public const int MAX_TEXT = 4000;
    public const int MAX_CITATIONS = 30;

    /**
     * @param list<array{url: string, title: ?string}> $citations sources the answer cited (web search only)
     */
    public function __construct(
        public string $platform,
        public string $model,
        public string $prompt,
        public string $text,
        public array $citations,
        public bool $webSearch,
    ) {}

    /** @return list<string> lower-case hosts of the cited sources, without "www." */
    public function citedHosts(): array
    {
        $hosts = [];
        foreach ($this->citations as $citation) {
            $host = strtolower((string) parse_url($citation['url'], PHP_URL_HOST));
            $host = str_starts_with($host, 'www.') ? substr($host, 4) : $host;
            if ($host !== '') {
                $hosts[$host] = true;
            }
        }
        return array_keys($hosts);
    }
}
