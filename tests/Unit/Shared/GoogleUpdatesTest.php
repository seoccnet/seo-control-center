<?php

declare(strict_types=1);

namespace SeoControlCenter\Tests\Unit\Shared;

use PHPUnit\Framework\TestCase;
use SeoControlCenter\Domain\Shared\GoogleUpdates;

/** Google's ranking updates shipped with SEOcc (1.5.0). */
final class GoogleUpdatesTest extends TestCase
{
    public function testUpdatesOverlappingAPeriod(): void
    {
        self::assertSame(['September 2026 spam update'], array_column(GoogleUpdates::between('2026-10-01', '2026-10-05'), 'name'), 'An update still rolling out at the start of the period counts.');
        self::assertSame([], GoogleUpdates::between('2026-10-09', '2026-11-09'));
        self::assertSame([['name' => 'November 2026 core update', 'start' => '2026-11-10', 'end' => null]], GoogleUpdates::between('2026-11-15', '2026-12-31'), 'Without an end date an update is still rolling out.');
        self::assertSame(['March 2024 core update', 'March 2024 spam update'], array_column(GoogleUpdates::between('2024-03-05', '2024-03-05'), 'name'));
    }

    public function testTheListIsOrderedAndConsistent(): void
    {
        $all = GoogleUpdates::all();
        self::assertGreaterThan(20, count($all));
        $previous = '';
        foreach ($all as $update) {
            self::assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}$/', $update['start']);
            self::assertGreaterThanOrEqual($previous, $update['start'], $update['name']);
            if ($update['end'] !== null) {
                self::assertGreaterThanOrEqual($update['start'], $update['end'], $update['name']);
            }
            $previous = $update['start'];
        }
        self::assertSame(1, count(array_filter($all, static fn(array $u): bool => $u['end'] === null)), 'At most the latest update is still rolling out.');
    }
}
