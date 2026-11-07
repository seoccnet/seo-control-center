<?php

declare(strict_types=1);

namespace SeoControlCenter\Domain\SearchConsole;

/**
 * Google surfaces besides web search that Search Console reports
 * separately. Web search is the main import; these are daily totals only.
 */
enum SearchType: string
{
    case Discover = 'discover';
    case GoogleNews = 'googleNews';
    case News = 'news';
    case Image = 'image';
    case Video = 'video';

    public function label(): string
    {
        return match ($this) {
            self::Discover => 'Discover',
            self::GoogleNews => 'Google News',
            self::News => 'News tab',
            self::Image => 'Image search',
            self::Video => 'Video search',
        };
    }

    /** Discover and Google News have no search position. */
    public function hasPosition(): bool
    {
        return $this !== self::Discover && $this !== self::GoogleNews;
    }
}
