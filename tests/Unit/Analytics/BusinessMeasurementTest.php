<?php

declare(strict_types=1);

namespace SeoControlCenter\Tests\Unit\Analytics;

use PHPUnit\Framework\TestCase;
use SeoControlCenter\Domain\Analytics\BusinessEvent;
use SeoControlCenter\Domain\Analytics\BusinessMeasurement;
use SeoControlCenter\Domain\Shared\DateRange;

/** The five states of a business event (ADR-042); missing data is never zero. */
final class BusinessMeasurementTest extends TestCase
{
    private const array SETUP = ['credentials' => true, 'linked' => true];

    public function testEachStateHasItsOwnCondition(): void
    {
        $range = new DateRange('2026-09-25', '2026-10-22');
        $ok = ['from' => '2026-09-25', 'to' => '2026-10-22', 'timezone' => 'UTC', 'limited' => false, 'thresholded' => false, 'fetched_at' => '2026-10-23 06:00:00', 'error' => null, 'error_at' => null];

        self::assertSame(BusinessMeasurement::NOT_CONFIGURED, BusinessMeasurement::assess([], self::SETUP, $ok, [], $range, 500)['state']);
        self::assertSame(BusinessMeasurement::UNKNOWN, $this->state(['credentials' => false, 'linked' => true], $ok, [], $range));
        self::assertSame(BusinessMeasurement::UNKNOWN, $this->state(['credentials' => true, 'linked' => false], $ok, [], $range));
        self::assertSame(BusinessMeasurement::UNKNOWN, $this->state(self::SETUP, null, [], $range), 'Never imported.');
        self::assertSame(BusinessMeasurement::UNKNOWN, $this->state(self::SETUP, $ok, ['purchase' => ['organic' => 0, 'total' => 0, 'days' => 2]], $range), 'Two days of 28 are not a zero.');
        self::assertSame(BusinessMeasurement::NOT_OBSERVED, $this->state(self::SETUP, $ok, ['purchase' => ['organic' => 0, 'total' => 0, 'days' => 28]], $range));
        self::assertSame(BusinessMeasurement::OBSERVED, $this->state(self::SETUP, $ok, ['purchase' => ['organic' => 0, 'total' => 2, 'days' => 28]], $range));
        self::assertSame(BusinessMeasurement::FAILED, $this->state(self::SETUP, [...$ok, 'error' => 'HTTP 403', 'error_at' => '2026-10-23 06:00:01'], ['purchase' => ['organic' => 1, 'total' => 2, 'days' => 28]], $range), 'A newer failure wins over older counts.');
        self::assertSame(BusinessMeasurement::OBSERVED, $this->state(self::SETUP, [...$ok, 'error' => 'HTTP 403', 'error_at' => '2026-10-22 06:00:00'], ['purchase' => ['organic' => 1, 'total' => 2, 'days' => 28]], $range), 'An older failure is superseded by a later success.');
    }

    public function testNotObservedIsUncertaintyAndCountsAreHiddenWhenUnknown(): void
    {
        $range = new DateRange('2026-09-25', '2026-10-22');
        $ok = ['from' => '2026-09-25', 'to' => '2026-10-22', 'timezone' => 'UTC', 'limited' => true, 'thresholded' => false, 'fetched_at' => '2026-10-23 06:00:00', 'error' => null, 'error_at' => null];
        $result = BusinessMeasurement::assess([$this->event()], self::SETUP, $ok, ['purchase' => ['organic' => 0, 'total' => 0, 'days' => 28]], $range, 1120);

        self::assertStringContainsString('not zero business value', $result['events'][0]['reason']);
        self::assertStringContainsString('1,120 sessions', $result['events'][0]['reason']);
        self::assertStringStartsWith('Organic Search sessions exist', $result['summary']);
        self::assertCount(1, $result['caveats'], 'A row-limited report is flagged.');
        self::assertFalse(BusinessMeasurement::verified($result));

        $partial = BusinessMeasurement::assess([$this->event()], self::SETUP, $ok, ['purchase' => ['organic' => 0, 'total' => 0, 'days' => 5]], $range, null);
        self::assertNull($partial['events'][0]['total'], 'An unknown state shows no count that could read as zero.');
    }

    public function testEventNamesFollowGa4Rules(): void
    {
        self::assertTrue(BusinessEvent::validName('generate_lead'));
        self::assertFalse(BusinessEvent::validName('1st_order'));
        self::assertFalse(BusinessEvent::validName('order placed'));
        self::assertFalse(BusinessEvent::validName(str_repeat('a', 41)));
    }

    /**
     * @param array{credentials: bool, linked: bool} $setup
     * @param array{from: ?string, to: ?string, timezone: ?string, limited: bool, thresholded: bool, fetched_at: ?string, error: ?string, error_at: ?string}|null $import
     * @param array<string, array{organic: int, total: int, days: int}> $counts
     */
    private function state(array $setup, ?array $import, array $counts, DateRange $range): string
    {
        return BusinessMeasurement::assess([$this->event()], $setup, $import, $counts, $range, 500)['events'][0]['state'];
    }

    private function event(): BusinessEvent
    {
        return new BusinessEvent('01a16657-6810-70a7-b36d-4ab88df69f13', 1, BusinessEvent::PURCHASE, 'purchase', 'Order', '2026-10-01 00:00:00');
    }
}
