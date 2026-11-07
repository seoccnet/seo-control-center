<?php

declare(strict_types=1);

namespace SeoControlCenter\Domain\SearchConsole;

final readonly class QueryPageRow
{
    public function __construct(public string $query, public string $url, public Metrics $metrics) {}
}
