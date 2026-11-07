<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\Reports;

use DateTimeImmutable;
use DateTimeZone;
use SeoControlCenter\Domain\Shared\DateRange;

/**
 * Report periods in the project's time zone: weekly = Monday to Sunday,
 * monthly = a calendar month. A period is sent once its data is in: three
 * days after it ends, at 07:00 (Wednesday for weeks, the 3rd for months),
 * because Search Console and Bing report a few days late.
 */
final class ReportPeriod
{
    private const int SEND_AFTER_DAYS = 3;
    private const int SEND_HOUR = 7;

    private function __construct() {}

    /**
     * The newest period that may be sent at $now.
     */
    public static function latestSendable(string $frequency, DateTimeImmutable $now, string $timezone): DateRange
    {
        $local = $now->setTimezone(self::zone($timezone));
        $period = self::latestComplete($frequency, $local);
        if ($local < self::sendTime($period, $local->getTimezone())) {
            $period = self::before($frequency, $period);
        }

        return $period;
    }

    /**
     * The newest period that has fully ended before $now (for previews).
     */
    public static function latestComplete(string $frequency, DateTimeImmutable $now, ?string $timezone = null): DateRange
    {
        $local = $timezone !== null ? $now->setTimezone(self::zone($timezone)) : $now;
        $today = $local->setTime(0, 0);
        if ($frequency === ReportSchedule::MONTHLY) {
            $first = $today->modify('first day of this month')->modify('-1 month');

            return new DateRange($first->format('Y-m-d'), $first->modify('last day of this month')->format('Y-m-d'));
        }
        $monday = $today->modify('-' . ((int) $today->format('N') - 1) . ' days')->modify('-7 days');

        return new DateRange($monday->format('Y-m-d'), $monday->modify('+6 days')->format('Y-m-d'));
    }

    /**
     * The period before $period (the comparison period of a report).
     */
    public static function before(string $frequency, DateRange $period): DateRange
    {
        if ($frequency === ReportSchedule::MONTHLY) {
            $first = new DateTimeImmutable($period->fromString())->modify('-1 month');

            return new DateRange($first->format('Y-m-d'), $first->modify('last day of this month')->format('Y-m-d'));
        }

        return $period->previous();
    }

    /**
     * The period of $frequency that ends on $to, or null when $to does not end one.
     */
    public static function endingOn(string $frequency, string $to): ?DateRange
    {
        $end = DateTimeImmutable::createFromFormat('!Y-m-d', $to);
        if ($end === false || $end->format('Y-m-d') !== $to) {
            return null;
        }
        if ($frequency === ReportSchedule::MONTHLY) {
            return $end->format('Y-m-d') === $end->modify('last day of this month')->format('Y-m-d')
                ? new DateRange($end->modify('first day of this month')->format('Y-m-d'), $to)
                : null;
        }

        return $end->format('N') === '7' ? new DateRange($end->modify('-6 days')->format('Y-m-d'), $to) : null;
    }

    public static function key(DateRange $period): string
    {
        return $period->fromString() . '..' . $period->toString();
    }

    /**
     * "Week 39 · 21–27 September 2026" or "September 2026".
     */
    public static function label(string $frequency, DateRange $period): string
    {
        $from = new DateTimeImmutable($period->fromString());
        $to = new DateTimeImmutable($period->toString());
        if ($frequency === ReportSchedule::MONTHLY) {
            return $from->format('F Y');
        }
        $span = $from->format('n') === $to->format('n')
            ? $from->format('j') . '–' . $to->format('j F Y')
            : $from->format('j F') . ' – ' . $to->format('j F Y');

        return 'Week ' . (int) $from->format('W') . ' · ' . $span;
    }

    private static function sendTime(DateRange $period, DateTimeZone $zone): DateTimeImmutable
    {
        return new DateTimeImmutable($period->toString(), $zone)->modify('+' . self::SEND_AFTER_DAYS . ' days')->setTime(self::SEND_HOUR, 0);
    }

    private static function zone(string $timezone): DateTimeZone
    {
        try {
            return new DateTimeZone($timezone);
        } catch (\Exception) {
            return new DateTimeZone('UTC');
        }
    }
}
