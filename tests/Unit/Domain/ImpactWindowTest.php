<?php

declare(strict_types=1);

namespace SeoControlCenter\Tests\Unit\Domain;

use PHPUnit\Framework\TestCase;
use SeoControlCenter\Modules\Annotations\ImpactWindow;

final class ImpactWindowTest extends TestCase
{
    public function testPhasesFollowTheSignalWindowAndTheComparison(): void
    {
        $waiting = new ImpactWindow('title', '2026-10-01', '2026-10-04');
        self::assertSame(ImpactWindow::WAITING, $waiting->phase);
        self::assertSame(3, $waiting->elapsed);
        self::assertSame(4, $waiting->daysToSignal());
        self::assertSame('2026-10-30', $waiting->comparisonDate);
        self::assertSame(29, $waiting->horizon, 'The full comparison outlasts a 21-day title window.');

        $signal = new ImpactWindow('title', '2026-10-01', '2026-10-15');
        self::assertSame(ImpactWindow::SIGNAL, $signal->phase);
        self::assertSame(15, $signal->daysLeft());
        self::assertFalse($signal->comparisonReady());

        $ready = new ImpactWindow('title', '2026-10-01', '2026-10-30');
        self::assertSame(ImpactWindow::READY, $ready->phase);
        self::assertTrue($ready->comparisonReady());
        self::assertSame(0, $ready->daysLeft());

        $links = new ImpactWindow('links', '2026-08-01', '2026-09-15');
        self::assertSame(56, $links->horizon, 'Slow changes keep counting until their window closes.');
        self::assertSame(ImpactWindow::SIGNAL, $links->phase);
        self::assertTrue($links->comparisonReady());

        self::assertSame(ImpactWindow::SETTLED, (new ImpactWindow('content', '2026-01-01', '2026-10-11'))->phase);
        self::assertSame(90, (new ImpactWindow('new_page', '2026-10-01', '2026-10-11'))->horizon);
        self::assertSame('Clicks and impressions', (new ImpactWindow('unknown', '2026-10-01', '2026-10-11'))->watch);
    }

    public function testTrackPositionsStayOnTheScale(): void
    {
        $window = new ImpactWindow('content', '2026-10-01', '2026-10-15');
        self::assertSame(['signal_from' => 33.3, 'signal_to' => 100.0, 'today' => 33.3], $window->track());
        self::assertSame(100.0, (new ImpactWindow('title', '2026-01-01', '2026-03-01'))->track()['today']);
        self::assertSame(0, (new ImpactWindow('title', '2026-10-12', '2026-10-11'))->elapsed, 'A future date counts as day 0.');
    }
}
