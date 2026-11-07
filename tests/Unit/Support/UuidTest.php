<?php

declare(strict_types=1);

namespace SeoControlCenter\Tests\Unit\Support;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use SeoControlCenter\Core\Support\FrozenClock;
use SeoControlCenter\Core\Support\Uuid;

final class UuidTest extends TestCase
{
    public function testGeneratesValidVersion7Uuids(): void
    {
        $uuid = Uuid::v7();

        self::assertTrue(Uuid::isValid($uuid));
        self::assertSame('7', $uuid[14]);
        self::assertContains($uuid[19], ['8', '9', 'a', 'b']);
    }

    public function testIsUniqueAndTimeOrdered(): void
    {
        $clock = new FrozenClock(new DateTimeImmutable('2026-09-26 10:00:00 UTC'));
        $a = Uuid::v7($clock);
        $clock->advance('+1 second');
        $b = Uuid::v7($clock);

        self::assertNotSame($a, $b);
        self::assertLessThan(0, strcmp($a, $b));
        self::assertCount(1000, array_unique(array_map(static fn(): string => Uuid::v7(), range(1, 1000))));
    }

    public function testRejectsInvalidValues(): void
    {
        self::assertFalse(Uuid::isValid('1'));
        self::assertFalse(Uuid::isValid('0190F2A4-7B3C-7D2E-9C1A-2B3C4D5E6F70'));
        self::assertFalse(Uuid::isValid("0190f2a4-7b3c-7d2e-9c1a-2b3c4d5e6f70\n"));
    }
}
