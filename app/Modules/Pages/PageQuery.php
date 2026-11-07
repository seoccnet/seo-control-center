<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\Pages;

use SeoControlCenter\Domain\SearchConsole\Metrics;

final readonly class PageQuery
{
    public function __construct(public string $query, public string $url, public Metrics $metrics) {}
}
