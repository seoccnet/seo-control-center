<?php

declare(strict_types=1);

namespace SeoControlCenter\Domain\SearchConsole;

/**
 * Breakdowns imported from Search Console. Query and page rows are stored
 * per day; device and country are low-cardinality breakdowns.
 */
enum PerformanceDimension: string
{
    case Query = 'query';
    case Page = 'page';
    case Device = 'device';
    case Country = 'country';

    public function label(): string
    {
        return match ($this) {
            self::Query => 'Query',
            self::Page => 'Page',
            self::Device => 'Device',
            self::Country => 'Country',
        };
    }
}
