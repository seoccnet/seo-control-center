<?php

declare(strict_types=1);

namespace SeoControlCenter\Tests\Support\Fakes;

use SeoControlCenter\Domain\Analytics\Ga4AiReferralReport;
use SeoControlCenter\Domain\Analytics\Ga4Credentials;
use SeoControlCenter\Domain\Analytics\Ga4EventReport;
use SeoControlCenter\Domain\Analytics\Ga4Provider;
use SeoControlCenter\Domain\Analytics\Ga4Report;
use SeoControlCenter\Domain\Shared\DateRange;

final class FakeGa4Provider implements Ga4Provider
{
    public ?Ga4Report $result = null;
    /** @var list<array{property: string, domain: string, range: DateRange}> */
    public array $calls = [];
    public function report(#[\SensitiveParameter] Ga4Credentials $credentials, string $propertyId, string $domain, DateRange $range): Ga4Report
    {
        $this->calls[] = ['property' => $propertyId, 'domain' => $domain, 'range' => $range];
        return $this->result ?? new Ga4Report([], 'USD', 'UTC', false, false);
    }

    /** @var array<string, array{organic: int, total: int}> per event name, the same every day */
    public array $eventsPerDay = [];

    public ?\Throwable $eventsError = null;

    /** @var array<string, int> */
    public array $realtimeCounts = [];

    public function events(#[\SensitiveParameter] Ga4Credentials $credentials, string $propertyId, string $domain, DateRange $range, array $eventNames): Ga4EventReport
    {
        if ($this->eventsError !== null) {
            throw $this->eventsError;
        }
        $rows = [];
        foreach ($range->eachDay() as $day) {
            foreach ($eventNames as $name) {
                $rows[] = ['date' => $day, 'event_name' => $name, 'organic' => $this->eventsPerDay[$name]['organic'] ?? 0, 'total' => $this->eventsPerDay[$name]['total'] ?? 0];
            }
        }

        return new Ga4EventReport($rows, 'UTC', false, false);
    }

    /** @var array<string, array{sessions: int, key_events: float, revenue: float}> per AI source, the same every day */
    public array $aiReferralsPerDay = [];

    public ?\Throwable $aiReferralsError = null;

    /** @var list<DateRange> */
    public array $aiReferralCalls = [];

    public function aiReferrals(#[\SensitiveParameter] Ga4Credentials $credentials, string $propertyId, string $domain, DateRange $range): Ga4AiReferralReport
    {
        $this->aiReferralCalls[] = $range;
        if ($this->aiReferralsError !== null) {
            throw $this->aiReferralsError;
        }
        $rows = [];
        foreach ($range->eachDay() as $day) {
            foreach ($this->aiReferralsPerDay as $source => $metrics) {
                $rows[] = ['date' => $day, 'source' => $source, ...$metrics];
            }
        }

        return new Ga4AiReferralReport($rows, 'EUR', false);
    }

    public function realtime(#[\SensitiveParameter] Ga4Credentials $credentials, string $propertyId, array $eventNames): array
    {
        return array_map(fn(string $name): int => $this->realtimeCounts[$name] ?? 0, array_combine($eventNames, $eventNames));
    }
}
