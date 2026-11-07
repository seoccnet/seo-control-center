<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\Analytics;

use SeoControlCenter\Domain\Analytics\AiReferralSources;
use SeoControlCenter\Domain\Analytics\Ga4Credentials;
use SeoControlCenter\Domain\Analytics\Ga4Provider;
use SeoControlCenter\Domain\Integrations\IntegrationException;
use SeoControlCenter\Domain\Projects\Project;
use SeoControlCenter\Domain\Shared\DateRange;

/**
 * AI referral traffic (0.43.0): GA4 sessions that AI assistants send to the
 * site, imported with every GA4 import (one extra read-only report) and
 * shown on the AI visibility page as the last WINDOW_DAYS against the
 * WINDOW_DAYS before.
 */
final readonly class AiReferralService
{
    public const int WINDOW_DAYS = 28;

    public function __construct(
        private AiReferralRepository $referrals,
        private Ga4Repository $ga4,
        private Ga4Provider $provider,
    ) {}

    /** Runs after a GA4 import; a failure is recorded and never fails the import. */
    public function import(Project $project, #[\SensitiveParameter] Ga4Credentials $credentials, string $propertyId, DateRange $range): void
    {
        // Always both comparison windows, so the first import already has a "before".
        $range = DateRange::endingOn($range->toString(), max(2 * self::WINDOW_DAYS, $range->days()));
        try {
            $report = $this->provider->aiReferrals($credentials, $propertyId, $project->primaryDomain, $range);
        } catch (IntegrationException $e) {
            $this->referrals->recordFailure($project->id, $e->getMessage());

            return;
        }
        if ($this->ga4->propertyId($project->id) === $propertyId) {
            $this->referrals->save($project->id, $range, $report);
        }
    }

    /**
     * @return array{
     *     linked: bool,
     *     import: array{from: ?string, to: ?string, currency: ?string, limited: bool, fetched_at: ?string, error: ?string, error_at: ?string}|null,
     *     current: ?DateRange,
     *     previous: ?DateRange,
     *     comparable: bool,
     *     totals: array{sessions: int, previous: int, key_events: float, revenue: float},
     *     assistants: list<array{assistant: string, sources: list<string>, sessions: int, previous: int, key_events: float, revenue: float}>
     * }
     */
    public function page(Project $project): array
    {
        $import = $this->referrals->lastImport($project->id);
        $result = ['linked' => $this->ga4->propertyId($project->id) !== null, 'import' => $import, 'current' => null, 'previous' => null, 'comparable' => false,
            'totals' => ['sessions' => 0, 'previous' => 0, 'key_events' => 0.0, 'revenue' => 0.0], 'assistants' => []];
        if ($import === null || $import['to'] === null || $import['from'] === null) {
            return $result;
        }
        $current = DateRange::endingOn($import['to'], self::WINDOW_DAYS);
        $previous = $current->previous();
        $assistants = [];
        foreach (['now' => $current, 'before' => $previous] as $window => $range) {
            foreach ($this->referrals->bySource($project->id, $range) as $row) {
                $name = AiReferralSources::assistant($row['source']);
                if ($name === null) {
                    continue;
                }
                $assistants[$name] ??= ['assistant' => $name, 'sources' => [], 'sessions' => 0, 'previous' => 0, 'key_events' => 0.0, 'revenue' => 0.0];
                if ($window === 'now') {
                    $assistants[$name]['sessions'] += $row['sessions'];
                    $assistants[$name]['key_events'] += $row['key_events'];
                    $assistants[$name]['revenue'] += $row['revenue'];
                    $assistants[$name]['sources'][] = $row['source'];
                } else {
                    $assistants[$name]['previous'] += $row['sessions'];
                }
            }
        }
        usort($assistants, static fn(array $a, array $b): int => [$b['sessions'], $b['previous']] <=> [$a['sessions'], $a['previous']]);
        foreach ($assistants as $row) {
            $result['totals']['sessions'] += $row['sessions'];
            $result['totals']['previous'] += $row['previous'];
            $result['totals']['key_events'] += $row['key_events'];
            $result['totals']['revenue'] += $row['revenue'];
        }

        return [...$result, 'current' => $current, 'previous' => $previous, 'comparable' => $import['from'] <= $previous->fromString(), 'assistants' => $assistants];
    }
}
