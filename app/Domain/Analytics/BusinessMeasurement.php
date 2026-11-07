<?php

declare(strict_types=1);

namespace SeoControlCenter\Domain\Analytics;

use SeoControlCenter\Domain\Shared\DateRange;

/**
 * What SEOcc can and cannot say about a project's business events (ADR-042).
 * Missing data is never zero: an event GA4 did not record is "not observed"
 * only over fully imported days, and that is uncertainty, not zero value.
 */
final class BusinessMeasurement
{
    public const string NOT_CONFIGURED = 'not_configured';
    public const string NOT_OBSERVED = 'not_observed';
    public const string OBSERVED = 'observed';
    public const string FAILED = 'failed';
    public const string UNKNOWN = 'unknown';

    public const array LABELS = [
        self::NOT_CONFIGURED => 'Not configured',
        self::NOT_OBSERVED => 'Not observed',
        self::OBSERVED => 'Observed',
        self::FAILED => 'Import failed',
        self::UNKNOWN => 'Unknown',
    ];

    /** Precedence for the project summary: the least certain state wins. */
    private const array RANK = [self::FAILED => 4, self::UNKNOWN => 3, self::NOT_OBSERVED => 2, self::OBSERVED => 1];

    /** What the numbers mean and what SEOcc cannot check. */
    public const array LIMITATIONS = [
        'consent' => 'Consent: visitors who decline analytics cookies are missing or modelled in GA4, so counts can be lower than real conversions.',
        'timezone' => 'Time zones: GA4 days follow the property time zone; Search Console days follow Pacific Time; SEOcc stores UTC. Daily figures can shift by a day.',
        'currency' => 'Currency: revenue is in the GA4 property currency, converted by Google where needed.',
        'attribution' => 'Attribution: "Organic Search" is GA4\'s session channel for Google, Bing and other search engines. A Search Console click is not a GA4 session; the two are never matched one to one.',
        'tag' => 'Tag deployment: SEOcc reads GA4 reports only. It cannot see whether your site\'s tag fires on a page or which form or checkout sent an event.',
        'thresholds' => 'Privacy thresholds: Google can hide small counts; a thresholded or row-limited report is marked here.',
    ];

    /**
     * @param list<BusinessEvent> $events
     * @param array{credentials: bool, linked: bool} $setup
     * @param array{from: ?string, to: ?string, timezone: ?string, limited: bool, thresholded: bool, fetched_at: ?string, error: ?string, error_at: ?string}|null $import
     * @param array<string, array{organic: int, total: int, days: int}> $counts per event name within the range
     * @return array{state: string, label: string, summary: string, range: array{from: string, to: string, days: int}, organic_sessions: ?int, events: list<array<string, mixed>>, caveats: list<string>}
     */
    public static function assess(array $events, array $setup, ?array $import, array $counts, DateRange $range, ?int $organicSessions): array
    {
        $caveats = [];
        if ($import !== null && $import['limited']) {
            $caveats[] = 'GA4 limited the last event report (more rows than it returns); counts may be incomplete.';
        }
        if ($import !== null && $import['thresholded']) {
            $caveats[] = 'Google applied privacy thresholds to the last event report; small counts may be hidden.';
        }
        $rows = [];
        foreach ($events as $event) {
            [$state, $reason] = self::state($event, $setup, $import, $counts[$event->eventName] ?? null, $range, $organicSessions);
            $count = $counts[$event->eventName] ?? null;
            $rows[] = [
                'id' => $event->publicId,
                'kind' => $event->kind,
                'kind_label' => $event->kindLabel(),
                'event_name' => $event->eventName,
                'label' => $event->label,
                'state' => $state,
                'state_label' => self::LABELS[$state],
                'reason' => $reason,
                'total' => $state === self::OBSERVED || $state === self::NOT_OBSERVED ? $count['total'] ?? null : null,
                'organic' => $state === self::OBSERVED || $state === self::NOT_OBSERVED ? $count['organic'] ?? null : null,
                'covered_days' => $count['days'] ?? 0,
                'expected_days' => $range->days(),
            ];
        }
        $state = $rows === [] ? self::NOT_CONFIGURED : array_reduce($rows, static fn(string $carry, array $row): string => self::RANK[$row['state']] > self::RANK[$carry] ? $row['state'] : $carry, self::OBSERVED);

        return [
            'state' => $state,
            'label' => self::LABELS[$state],
            'summary' => self::summary($state, $rows, $organicSessions),
            'range' => ['from' => $range->fromString(), 'to' => $range->toString(), 'days' => $range->days()],
            'organic_sessions' => $organicSessions,
            'events' => $rows,
            'caveats' => $caveats,
        ];
    }

    /**
     * Whether at least one business event has been observed, the proof the Quest Log asks for.
     *
     * @param array{events: list<array<string, mixed>>} $assessment
     */
    public static function verified(array $assessment): bool
    {
        foreach ($assessment['events'] as $event) {
            if (($event['state'] ?? null) === self::OBSERVED) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array{credentials: bool, linked: bool} $setup
     * @param array{from: ?string, to: ?string, timezone: ?string, limited: bool, thresholded: bool, fetched_at: ?string, error: ?string, error_at: ?string}|null $import
     * @param array{organic: int, total: int, days: int}|null $count
     * @return array{0: string, 1: string}
     */
    private static function state(BusinessEvent $event, array $setup, ?array $import, ?array $count, DateRange $range, ?int $organicSessions): array
    {
        if (!$setup['credentials']) {
            return [self::UNKNOWN, 'GA4 is not connected to this workspace, so SEOcc cannot see this event.'];
        }
        if (!$setup['linked']) {
            return [self::UNKNOWN, 'No GA4 property is linked to this project, so SEOcc cannot see this event.'];
        }
        if ($import !== null && $import['error'] !== null && ($import['fetched_at'] === null || (string) $import['error_at'] >= $import['fetched_at'])) {
            return [self::FAILED, 'The last GA4 event import failed: ' . $import['error']];
        }
        if ($import === null || $import['fetched_at'] === null) {
            return [self::UNKNOWN, 'Not imported yet. The next GA4 import (daily, or Import now) reads this event.'];
        }
        $days = $count['days'] ?? 0;
        if ($days === 0) {
            return [self::UNKNOWN, 'No imported days for this event in the period. If you added it after the last import, the next import reads it.'];
        }
        if ($days < $range->days()) {
            return [self::UNKNOWN, sprintf('Only %d of %d days are imported for this event, so a missing count is not zero.', $days, $range->days())];
        }
        $total = $count['total'] ?? 0;
        $organic = $count['organic'] ?? 0;
        if ($total > 0) {
            return [self::OBSERVED, $organic > 0
                ? sprintf('GA4 recorded %s in %d days, %s of them in Organic Search sessions.', number_format($total), $days, number_format($organic))
                : sprintf('GA4 recorded %s in %d days, none in Organic Search sessions.', number_format($total), $days)];
        }
        $sessions = $organicSessions !== null && $organicSessions > 0 ? sprintf(' Organic Search brought %s sessions in the same days.', number_format($organicSessions)) : '';

        return [self::NOT_OBSERVED, sprintf('GA4 recorded no "%s" event in %d fully imported days.%s That can mean nothing happened, or that the tag, consent banner or event name is not set up. It is measurement uncertainty, not zero business value.', $event->eventName, $days, $sessions)];
    }

    /** @param list<array<string, mixed>> $rows */
    private static function summary(string $state, array $rows, ?int $organicSessions): string
    {
        return match ($state) {
            self::NOT_CONFIGURED => 'No business events are defined. Until you name the GA4 events that count as success (a purchase, a lead, a sign-up), SEOcc cannot tell whether organic visits lead to business results.',
            self::FAILED => 'The last GA4 event import failed, so the business events below are not verified. Check the error and System → Jobs.',
            self::UNKNOWN => 'Some business events cannot be judged yet: GA4 is not connected, not linked or not fully imported for this period.',
            self::NOT_OBSERVED => ($organicSessions !== null && $organicSessions > 0 ? 'Organic Search sessions exist, but ' : '') . 'at least one business event was not recorded in this period. Treat business value as unverified, not zero, until a test conversion shows up.',
            default => count($rows) === 1 ? 'GA4 recorded the business event in this period.' : 'GA4 recorded every business event in this period.',
        };
    }
}
