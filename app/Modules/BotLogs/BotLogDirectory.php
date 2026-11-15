<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\BotLogs;

/** Where uploaded logs wait to be read: storage/tmp/bot-logs, outside the web root. */
final readonly class BotLogDirectory
{
    public function __construct(public string $path) {}
}
