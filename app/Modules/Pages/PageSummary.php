<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\Pages;

use SeoControlCenter\Domain\SearchConsole\Metrics;

final readonly class PageSummary
{
    public function __construct(public string $url, public ?Metrics $google, public ?Metrics $bing) {}
}
