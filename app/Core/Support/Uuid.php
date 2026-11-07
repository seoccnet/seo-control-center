<?php

declare(strict_types=1);

namespace SeoControlCenter\Core\Support;

use InvalidArgumentException;

/**
 * Public identifiers (INSTRUCTIONS.md §6): UUID version 7, time-ordered so
 * inserts stay index-friendly, 74 random bits so IDs are not guessable.
 */
final class Uuid
{
    private const string PATTERN = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/D';

    /** Route parameter constraint for FastRoute. */
    public const string ROUTE_PATTERN = '[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}';

    private function __construct() {}

    public static function v7(?Clock $clock = null): string
    {
        $ms = $clock !== null
            ? (int) $clock->now()->format('Uv')
            : (int) floor(microtime(true) * 1000);

        $bytes = pack('J', $ms);                  // 8 bytes, big-endian
        $bytes = substr($bytes, 2) . random_bytes(10); // 48-bit timestamp + 80 random bits

        $bytes[6] = chr((ord($bytes[6]) & 0x0F) | 0x70); // version 7
        $bytes[8] = chr((ord($bytes[8]) & 0x3F) | 0x80); // RFC 4122 variant

        $hex = bin2hex($bytes);

        return sprintf('%s-%s-%s-%s-%s', substr($hex, 0, 8), substr($hex, 8, 4), substr($hex, 12, 4), substr($hex, 16, 4), substr($hex, 20));
    }

    public static function isValid(string $value): bool
    {
        return preg_match(self::PATTERN, $value) === 1;
    }

    public static function assertValid(string $value): string
    {
        if (!self::isValid($value)) {
            throw new InvalidArgumentException('Invalid identifier.');
        }

        return $value;
    }
}
