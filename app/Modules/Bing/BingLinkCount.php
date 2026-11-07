<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\Bing;

/** A page of the site and the number of inbound links Bing knows for it. */
final readonly class BingLinkCount
{
    public function __construct(
        public string $url,
        public int $links,
    ) {}
}
