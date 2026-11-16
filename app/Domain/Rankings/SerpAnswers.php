<?php

declare(strict_types=1);

namespace SeoControlCenter\Domain\Rankings;

use SeoControlCenter\Domain\Keywords\KeywordLocale;

/**
 * The answer boxes of a tracked keyword's latest check (1.5.0): whether
 * Google showed a featured snippet and which site held it, and the
 * "People also ask" questions. Snapshots from before 1.5.0 know that a
 * snippet was shown, but not who held it.
 */
final readonly class SerpAnswers
{
    /** Positions from which a page can realistically win the featured snippet. */
    public const int SNIPPET_REACH = 10;

    /**
     * @param list<string> $questions
     */
    public function __construct(
        public string $keywordPublicId,
        public string $keyword,
        public KeywordLocale $locale,
        public string $checkedOn,
        public ?int $position,
        public ?string $url,
        public bool $hasSnippet,
        public ?string $snippetDomain,
        public array $questions,
        public ?int $searchVolume,
    ) {}

    /** Whether the project's domain (or a subdomain of it) holds the snippet. */
    public function holdsSnippet(string $domain): bool
    {
        if ($this->snippetDomain === null) {
            return false;
        }
        $domain = self::bare($domain);
        $host = self::bare($this->snippetDomain);

        return $host === $domain || str_ends_with($host, '.' . $domain);
    }

    /**
     * Another site holds the snippet while the project already ranks on page
     * one: the page most likely to win it is the one that ranks.
     */
    public function isSnippetOpportunity(string $domain): bool
    {
        return $this->hasSnippet && $this->snippetDomain !== null && !$this->holdsSnippet($domain)
            && $this->position !== null && $this->position <= self::SNIPPET_REACH;
    }

    private static function bare(string $host): string
    {
        $host = strtolower(trim($host));

        return str_starts_with($host, 'www.') ? substr($host, 4) : $host;
    }
}
