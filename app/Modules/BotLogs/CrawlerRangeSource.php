<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\BotLogs;

use SeoControlCenter\Domain\BotLogs\CrawlerIpRanges;

/** The IP ranges crawlers publish (1.2.1), for verifying bot log requests. */
interface CrawlerRangeSource
{
    /**
     * Ranges per bot key (see LogLineParser::BOTS) for the bots whose ranges
     * could be read now; a bot missing from the result cannot be verified.
     *
     * @return array<string, CrawlerIpRanges>
     */
    public function ranges(): array;
}
