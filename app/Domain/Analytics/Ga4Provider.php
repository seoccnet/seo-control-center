<?php

declare(strict_types=1);

namespace SeoControlCenter\Domain\Analytics;

use SeoControlCenter\Domain\Shared\DateRange;

interface Ga4Provider
{
    public function report(#[\SensitiveParameter] Ga4Credentials $credentials, string $propertyId, string $domain, DateRange $range): Ga4Report;

    /**
     * Daily counts of the named events on the project's hosts, site-wide and
     * in Organic Search sessions (ADR-042). Read-only.
     *
     * @param list<string> $eventNames
     */
    public function events(#[\SensitiveParameter] Ga4Credentials $credentials, string $propertyId, string $domain, DateRange $range, array $eventNames): Ga4EventReport;

    /**
     * Sessions, key events and purchase revenue on the project's hosts per
     * day and session source, for sources that are AI assistants
     * (AiReferralSources, 0.43.0). Read-only.
     */
    public function aiReferrals(#[\SensitiveParameter] Ga4Credentials $credentials, string $propertyId, string $domain, DateRange $range): Ga4AiReferralReport;

    /**
     * Counts of the named events GA4 received in the last 30 minutes, for an
     * owner's own test conversion (ADR-042). Read-only; sends nothing.
     *
     * @param list<string> $eventNames
     * @return array<string, int>
     */
    public function realtime(#[\SensitiveParameter] Ga4Credentials $credentials, string $propertyId, array $eventNames): array;
}
