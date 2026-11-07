<?php

declare(strict_types=1);

namespace SeoControlCenter\Tests\Unit\Reports;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use SeoControlCenter\Modules\Reports\ReportPeriod;
use SeoControlCenter\Modules\Reports\ReportSchedule;

final class ReportPeriodTest extends TestCase
{
    public function testLatestCompleteWeekAndMonth(): void
    {
        $friday = new DateTimeImmutable('2026-10-02 12:00:00 UTC');

        self::assertSame('2026-09-21..2026-09-27', ReportPeriod::key(ReportPeriod::latestComplete(ReportSchedule::WEEKLY, $friday, 'UTC')));
        self::assertSame('2026-09-01..2026-09-30', ReportPeriod::key(ReportPeriod::latestComplete(ReportSchedule::MONTHLY, $friday, 'UTC')));
        // On a Monday, last week ended yesterday.
        self::assertSame('2026-09-28..2026-10-04', ReportPeriod::key(ReportPeriod::latestComplete(ReportSchedule::WEEKLY, new DateTimeImmutable('2026-10-05 00:30:00 UTC'), 'UTC')));
    }

    public function testSendableAfterTheDataIsInAtSevenLocalTime(): void
    {
        // Week of 21–27 Sep goes out on Wednesday 30 Sep at 07:00.
        $tuesday = new DateTimeImmutable('2026-09-29 12:00:00 UTC');
        self::assertSame('2026-09-14..2026-09-20', ReportPeriod::key(ReportPeriod::latestSendable(ReportSchedule::WEEKLY, $tuesday, 'UTC')));
        self::assertSame('2026-09-21..2026-09-27', ReportPeriod::key(ReportPeriod::latestSendable(ReportSchedule::WEEKLY, new DateTimeImmutable('2026-09-30 07:00:00 UTC'), 'UTC')));
        // 06:30 UTC is 08:30 in Berlin (summer time): already due there, not yet in New York.
        $morning = new DateTimeImmutable('2026-09-30 06:30:00 UTC');
        self::assertSame('2026-09-21..2026-09-27', ReportPeriod::key(ReportPeriod::latestSendable(ReportSchedule::WEEKLY, $morning, 'Europe/Berlin')));
        self::assertSame('2026-09-14..2026-09-20', ReportPeriod::key(ReportPeriod::latestSendable(ReportSchedule::WEEKLY, $morning, 'America/New_York')));
        // September goes out on 3 October at 07:00.
        self::assertSame('2026-08-01..2026-08-31', ReportPeriod::key(ReportPeriod::latestSendable(ReportSchedule::MONTHLY, new DateTimeImmutable('2026-10-02 23:00:00 UTC'), 'UTC')));
        self::assertSame('2026-09-01..2026-09-30', ReportPeriod::key(ReportPeriod::latestSendable(ReportSchedule::MONTHLY, new DateTimeImmutable('2026-10-03 07:00:00 UTC'), 'UTC')));
    }

    public function testPeriodsByEndDateComparisonAndLabels(): void
    {
        $week = ReportPeriod::endingOn(ReportSchedule::WEEKLY, '2026-09-27');
        self::assertNotNull($week);
        self::assertSame('2026-09-14..2026-09-20', ReportPeriod::key(ReportPeriod::before(ReportSchedule::WEEKLY, $week)));
        self::assertSame('Week 39 · 21–27 September 2026', ReportPeriod::label(ReportSchedule::WEEKLY, $week));
        self::assertNull(ReportPeriod::endingOn(ReportSchedule::WEEKLY, '2026-09-26'), 'Weeks end on Sunday.');

        $month = ReportPeriod::endingOn(ReportSchedule::MONTHLY, '2026-03-31');
        self::assertNotNull($month);
        self::assertSame('2026-02-01..2026-02-28', ReportPeriod::key(ReportPeriod::before(ReportSchedule::MONTHLY, $month)));
        self::assertSame('March 2026', ReportPeriod::label(ReportSchedule::MONTHLY, $month));
        self::assertNull(ReportPeriod::endingOn(ReportSchedule::MONTHLY, '2026-03-30'));
        self::assertNull(ReportPeriod::endingOn(ReportSchedule::MONTHLY, 'not a date'));
        self::assertSame('Week 40 · 28 September – 4 October 2026', ReportPeriod::label(ReportSchedule::WEEKLY, ReportPeriod::endingOn(ReportSchedule::WEEKLY, '2026-10-04') ?? $week));
    }
}
