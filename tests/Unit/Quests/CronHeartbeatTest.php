<?php

declare(strict_types=1);

namespace SeoControlCenter\Tests\Unit\Quests;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SeoControlCenter\Modules\Quests\Setup\CronHeartbeat;

/** Ported from the Get started heartbeat test: the Foundations "Keep background tasks running" status. */
final class CronHeartbeatTest extends TestCase
{
    #[DataProvider('heartbeats')]
    public function testOnlyRecentValidCronHeartbeatsAreReady(mixed $heartbeat, int $interval, string $expected): void
    {
        self::assertSame($expected, CronHeartbeat::status($heartbeat, new DateTimeImmutable('2026-10-08T10:00:00Z'), $interval));
    }

    /** @return array<string, array{mixed, int, string}> */
    public static function heartbeats(): array
    {
        return [
            'missing' => [null, 1, 'Needs setup'],
            'wrong type' => ['now', 1, 'Needs setup'],
            'missing timestamp' => [[], 1, 'Needs setup'],
            'relative time' => [['at' => 'now'], 1, 'Needs attention'],
            'empty time' => [['at' => ''], 1, 'Needs attention'],
            'invalid calendar date' => [['at' => '2026-02-30 10:00:00'], 1, 'Needs attention'],
            'future' => [['at' => '2026-10-08 10:00:01'], 1, 'Needs attention'],
            'stale' => [['at' => '2026-10-08 09:40:00'], 1, 'Needs attention'],
            'healthy' => [['at' => '2026-10-08 09:59:00'], 1, 'Ready'],
            'configured interval' => [['at' => '2026-10-08 09:55:00'], 5, 'Ready'],
        ];
    }
}
