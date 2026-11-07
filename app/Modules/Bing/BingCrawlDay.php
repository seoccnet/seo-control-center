<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\Bing;

/**
 * Bing's crawl and index counts for one day (Pacific Time). Redirects
 * combine Bing's 301 and 302 counts.
 */
final readonly class BingCrawlDay
{
    public function __construct(
        public string $date,
        public int $crawledPages,
        public int $crawlErrors,
        public int $inIndex,
        public int $inLinks,
        public int $code2xx,
        public int $code3xx,
        public int $code4xx,
        public int $code5xx,
        public int $blockedByRobots,
        public int $otherCodes,
    ) {}
}
