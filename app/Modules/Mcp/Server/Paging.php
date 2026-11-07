<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\Mcp\Server;

/**
 * Offset paging for MCP lists built in memory: every record stays reachable
 * through offset/next_offset, also when BoundedResult trims a response.
 */
final class Paging
{
    public const int MAX_OFFSET = 100000;

    /** @return array<string, array<string, mixed>> */
    public static function schema(int $default, int $max): array
    {
        return [
            'offset' => ['type' => 'integer', 'minimum' => 0, 'maximum' => self::MAX_OFFSET, 'default' => 0, 'description' => 'Records to skip. Continue with next_offset until it is null.'],
            'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => $max, 'default' => $default],
        ];
    }

    /**
     * @template T
     * @param list<T> $rows
     * @param array<string, mixed> $arguments
     * @return array{rows: list<T>, offset: int, limit: int, total: int, next_offset: ?int}
     */
    public static function slice(array $rows, array $arguments, int $default, int $max): array
    {
        $offset = ToolContext::int($arguments, 'offset', 0, 0, self::MAX_OFFSET);
        $limit = ToolContext::int($arguments, 'limit', $default, 1, $max);
        $total = count($rows);

        return [
            'rows' => array_slice($rows, $offset, $limit),
            'offset' => $offset,
            'limit' => $limit,
            'total' => $total,
            'next_offset' => $offset + $limit < $total ? $offset + $limit : null,
        ];
    }
}
