<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\Mcp\Tools;

use SeoControlCenter\Domain\Shared\DateRange;
use SeoControlCenter\Modules\Mcp\Server\ToolError;

final class ReadInput
{
    /** @param array<string, mixed> $arguments */
    public static function range(array $arguments, DateRange $default, int $maximum = 365): DateRange
    {
        if (!isset($arguments['from']) && !isset($arguments['to'])) {
            return $default;
        }
        try {
            $range = new DateRange(is_string($arguments['from'] ?? null) ? $arguments['from'] : '', is_string($arguments['to'] ?? null) ? $arguments['to'] : '');
            if ($range->days() > $maximum) {
                throw new \InvalidArgumentException();
            }
            return $range;
        } catch (\InvalidArgumentException) {
            throw new ToolError('Provide both from and to as YYYY-MM-DD, in order, at most ' . $maximum . ' days apart.');
        }
    }

    /** @param array<string, mixed> $arguments */
    public static function url(array $arguments): ?string
    {
        if (!isset($arguments['url'])) {
            return null;
        }
        $url = $arguments['url'];
        if (!is_string($url) || strlen($url) > 2048 || filter_var($url, FILTER_VALIDATE_URL) === false || !in_array(parse_url($url, PHP_URL_SCHEME), ['http', 'https'], true)) {
            throw new ToolError('url must be an exact HTTP(S) URL of at most 2048 bytes.');
        }
        return $url;
    }
}
