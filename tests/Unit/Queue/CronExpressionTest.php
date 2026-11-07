<?php

declare(strict_types=1);

namespace SeoControlCenter\Tests\Unit\Queue;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SeoControlCenter\Core\Queue\CronExpression;

final class CronExpressionTest extends TestCase
{
    #[DataProvider('cases')]
    public function testIsDue(string $expression, string $time, bool $expected): void
    {
        self::assertSame($expected, (new CronExpression($expression))->isDue(new DateTimeImmutable($time . ' UTC')));
    }

    /**
     * @return iterable<string, array{string, string, bool}>
     */
    public static function cases(): iterable
    {
        yield 'every minute' => ['* * * * *', '2026-09-26 10:13', true];
        yield 'hourly at 7' => ['7 * * * *', '2026-09-26 10:07', true];
        yield 'hourly at 7, wrong minute' => ['7 * * * *', '2026-09-26 10:08', false];
        yield 'every 15' => ['*/15 * * * *', '2026-09-26 10:45', true];
        yield 'every 15, no' => ['*/15 * * * *', '2026-09-26 10:46', false];
        yield 'range with step' => ['0-30/10 * * * *', '2026-09-26 10:30', true];
        yield 'list' => ['0 3,15 * * *', '2026-09-26 15:00', true];
        yield 'daily 03:17' => ['17 3 * * *', '2026-09-26 03:17', true];
        yield 'weekdays (Sat)' => ['0 9 * * 1-5', '2026-09-26 09:00', false];
        yield 'sunday as 7' => ['0 9 * * 7', '2026-09-27 09:00', true];
        yield 'sunday as 0' => ['0 9 * * 0', '2026-09-27 09:00', true];
        yield 'dom or dow (dom matches)' => ['0 0 1 * 1', '2026-10-01 00:00', true];
        yield 'dom or dow (dow matches)' => ['0 0 1 * 1', '2026-09-28 00:00', true];
        yield 'month' => ['0 0 1 1 *', '2026-09-01 00:00', false];
    }

    #[DataProvider('invalid')]
    public function testRejectsInvalid(string $expression): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new CronExpression($expression);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalid(): iterable
    {
        yield 'too few fields' => ['* * * *'];
        yield 'minute out of range' => ['60 * * * *'];
        yield 'bad step' => ['*/0 * * * *'];
        yield 'garbage' => ['a * * * *'];
        yield 'reverse range' => ['30-10 * * * *'];
    }
}
