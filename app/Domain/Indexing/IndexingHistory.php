<?php

declare(strict_types=1);

namespace SeoControlCenter\Domain\Indexing;

/**
 * One URL's indexing history (ADR-042, 0.37.0): six separate states, each
 * with its evidence, and a timeline of what was observed when. A recorded
 * indexing request never makes a page "indexed"; only a Google check does.
 * Times are UTC; a request's day is the project's local day.
 *
 * @phpstan-type Crawl array{status_code: ?int, noindex: bool, canonical: ?string, redirect_to: ?string, error: ?string, crawled_at: ?string}
 * @phpstan-type Sitemap array{first_seen_at: string, submitted_at: ?string}
 * @phpstan-type Check array{checked_at: string, verdict: string, coverage: string, indexing_state: string, robots: string, last_crawl: ?string, google_canonical: string, user_canonical: string}
 * @phpstan-type Request array{public_id: string, channel: string, requested_on: string, evidence: string, note: string, created_at: string}
 * @phpstan-type State array{key: string, label: string, value: string, evidence: string, at: ?string}
 * @phpstan-type Event array{at: string, kind: string, title: string, detail: string}
 */
final readonly class IndexingHistory
{
    public const array CHANNELS = ['search_console' => 'Search Console: Request indexing', 'other' => 'Another way (described in the evidence)'];
    public const array VALUES = ['yes' => 'Yes', 'no' => 'No', 'unknown' => 'Unknown', 'not_applicable' => 'Not applicable'];
    private const array VERDICTS = ['PASS' => 'indexed', 'FAIL' => 'not indexed (error)', 'NEUTRAL' => 'not indexed', 'PARTIAL' => 'indexed with warnings'];

    /**
     * @param list<State> $states
     * @param list<Event> $events
     */
    private function __construct(public array $states, public array $events) {}

    /**
     * @param Crawl|null $crawl the latest completed site audit's fetch of this URL
     * @param Sitemap|null $sitemap the URL as read from the project's sitemap for IndexNow
     * @param list<Check> $checks successful Google checks, newest first
     * @param list<Request> $requests recorded indexing requests, newest first
     */
    public static function build(string $url, ?array $crawl, ?array $sitemap, array $checks, array $requests, string $timezone): self
    {
        $latest = $checks[0] ?? null;
        $request = $requests[0] ?? null;
        $public = self::public($crawl);
        $states = [
            $public,
            self::indexable($url, $crawl, $public['value'], $latest),
            self::known($latest),
            self::indexed($latest, $request),
            $request === null
                ? self::state('request', 'Request submitted', 'no', 'No request recorded. SEOcc never submits indexing requests to Google; record one you made in Search Console.', null)
                : self::state('request', 'Request submitted', 'yes', sprintf('Recorded: %s on %s. Evidence: %s', self::CHANNELS[$request['channel']] ?? $request['channel'], $request['requested_on'], $request['evidence']), $request['requested_on']),
            self::recrawl($checks, $request, $timezone),
        ];

        return new self($states, self::events($crawl, $sitemap, $checks, $requests));
    }

    /** @return State */
    private static function state(string $key, string $label, string $value, string $evidence, ?string $at): array
    {
        return ['key' => $key, 'label' => $label, 'value' => $value, 'evidence' => $evidence, 'at' => $at];
    }

    /**
     * @param Crawl|null $crawl
     * @return State
     */
    private static function public(?array $crawl): array
    {
        $label = 'Public';
        if ($crawl === null) {
            return self::state('public', $label, 'unknown', 'No completed site audit fetched this URL.', null);
        }
        $code = $crawl['status_code'];
        return match (true) {
            $code === null => self::state('public', $label, 'unknown', 'The latest site audit could not fetch it' . ($crawl['error'] !== null ? ': ' . $crawl['error'] : '.'), $crawl['crawled_at']),
            $code >= 200 && $code < 300 => self::state('public', $label, 'yes', 'HTTP ' . $code . ' in the latest site audit.', $crawl['crawled_at']),
            $code >= 300 && $code < 400 => self::state('public', $label, 'no', 'Redirects (HTTP ' . $code . ')' . ($crawl['redirect_to'] !== null ? ' to ' . $crawl['redirect_to'] : '') . ' in the latest site audit.', $crawl['crawled_at']),
            default => self::state('public', $label, 'no', 'HTTP ' . $code . ' in the latest site audit.', $crawl['crawled_at']),
        };
    }

    /**
     * @param Crawl|null $crawl
     * @param Check|null $latest
     * @return State
     */
    private static function indexable(string $url, ?array $crawl, string $public, ?array $latest): array
    {
        $label = 'Technically indexable';
        if ($latest !== null && $latest['robots'] === 'DISALLOWED') {
            return self::state('indexable', $label, 'no', 'Google reported that robots.txt blocks it.', $latest['checked_at']);
        }
        if ($crawl === null || $public !== 'yes') {
            return self::state('indexable', $label, $public === 'no' ? 'no' : 'unknown', $public === 'no' ? 'It is not public, so it cannot be indexed as it is.' : 'Unknown until a site audit fetches it.', $crawl['crawled_at'] ?? null);
        }
        if ($crawl['noindex']) {
            return self::state('indexable', $label, 'no', 'The page says noindex.', $crawl['crawled_at']);
        }
        if ($crawl['canonical'] !== null && $crawl['canonical'] !== $url) {
            return self::state('indexable', $label, 'no', 'Its canonical points to ' . $crawl['canonical'] . '.', $crawl['crawled_at']);
        }

        return self::state('indexable', $label, 'yes', 'Public, no noindex, and no canonical to another address in the latest site audit.', $crawl['crawled_at']);
    }

    /**
     * @param Check|null $latest
     * @return State
     */
    private static function known(?array $latest): array
    {
        if ($latest === null) {
            return self::state('known', 'Known to Google', 'unknown', 'No Google check saved. Request a Google check.', null);
        }
        $unknown = stripos($latest['coverage'], 'unknown to Google') !== false;

        return self::state('known', 'Known to Google', $unknown ? 'no' : 'yes', 'Google: ' . ($latest['coverage'] !== '' ? $latest['coverage'] : 'no coverage description') . '.', $latest['checked_at']);
    }

    /**
     * @param Check|null $latest
     * @param Request|null $request
     * @return State
     */
    private static function indexed(?array $latest, ?array $request): array
    {
        if ($latest === null) {
            return self::state('indexed', 'Indexed', 'unknown', 'No Google check saved' . ($request !== null ? '; a recorded request does not show that Google indexed the page' : '') . '. Request a Google check.', null);
        }
        $value = match ($latest['verdict']) {
            'PASS' => 'yes',
            'FAIL', 'NEUTRAL' => 'no',
            default => 'unknown',
        };
        $evidence = 'Google check: ' . (self::VERDICTS[$latest['verdict']] ?? 'verdict not given') . '.';
        if ($request !== null && $latest['checked_at'] < $request['created_at']) {
            $evidence .= ' This check is older than the latest recorded request; request a Google check to see its effect.';
        }

        return self::state('indexed', 'Indexed', $value, $evidence, $latest['checked_at']);
    }

    /**
     * @param list<Check> $checks
     * @param Request|null $request
     * @return State
     */
    private static function recrawl(array $checks, ?array $request, string $timezone): array
    {
        $label = 'Recrawl observed';
        if ($request === null) {
            return self::state('recrawl', $label, 'not_applicable', 'Followed only after a recorded request.', null);
        }
        $crawl = null;
        $checkedAfter = null;
        foreach ($checks as $check) {
            if ($check['last_crawl'] !== null && ($crawl === null || $check['last_crawl'] > $crawl)) {
                $crawl = $check['last_crawl'];
            }
            if ($check['checked_at'] > $request['created_at'] && $checkedAfter === null) {
                $checkedAfter = $check['checked_at'];
            }
        }
        $day = $crawl === null ? null : self::localDay($crawl, $timezone);
        // A request recorded later than it was made is known to come before the recording, so a crawl after the recording counts.
        if ($crawl !== null && ($crawl > $request['created_at'] || $day > $request['requested_on'])) {
            return self::state('recrawl', $label, 'yes', 'Google last crawled it on ' . $crawl . ' UTC, after the request.', $crawl);
        }
        if ($checkedAfter === null) {
            return self::state('recrawl', $label, 'unknown', 'No Google check since the request. Request a Google check to see whether Google crawled it again.', null);
        }
        if ($day === $request['requested_on']) {
            return self::state('recrawl', $label, 'unknown', 'Google crawled it on ' . $crawl . ' UTC, the day of the request; it may have been before the request.', $crawl);
        }

        return self::state('recrawl', $label, 'no', 'Google’s last crawl (' . ($crawl ?? 'none reported') . ') is not after the request, as of the check on ' . $checkedAfter . ' UTC.', $checkedAfter);
    }

    /**
     * @param Crawl|null $crawl
     * @param Sitemap|null $sitemap
     * @param list<Check> $checks
     * @param list<Request> $requests
     * @return list<Event>
     */
    private static function events(?array $crawl, ?array $sitemap, array $checks, array $requests): array
    {
        $events = [];
        $crawls = [];
        foreach ($checks as $i => $check) {
            $older = $checks[$i + 1] ?? null;
            $verdict = self::VERDICTS[$check['verdict']] ?? 'verdict not given';
            $title = $older !== null && $older['verdict'] !== $check['verdict']
                ? 'Google check: changed from ' . (self::VERDICTS[$older['verdict']] ?? 'verdict not given') . ' to ' . $verdict
                : 'Google check: ' . $verdict;
            $detail = $check['coverage'];
            if ($older !== null && $older['google_canonical'] !== $check['google_canonical']) {
                $detail .= ($detail !== '' ? ' · ' : '') . 'Google-selected canonical changed to ' . ($check['google_canonical'] !== '' ? $check['google_canonical'] : 'none');
            }
            $events[] = ['at' => $check['checked_at'], 'kind' => 'check', 'title' => $title, 'detail' => $detail];
            if ($check['last_crawl'] !== null && !isset($crawls[$check['last_crawl']])) {
                $crawls[$check['last_crawl']] = true;
                $events[] = ['at' => $check['last_crawl'], 'kind' => 'google_crawl', 'title' => 'Googlebot crawled the page', 'detail' => 'As reported by the Google check of ' . $check['checked_at'] . ' UTC.'];
            }
        }
        foreach ($requests as $request) {
            $events[] = ['at' => $request['requested_on'], 'kind' => 'request', 'title' => 'Indexing request: ' . (self::CHANNELS[$request['channel']] ?? $request['channel']), 'detail' => 'Evidence: ' . $request['evidence'] . ($request['note'] !== '' ? ' · ' . $request['note'] : '') . ' (recorded ' . $request['created_at'] . ' UTC)'];
        }
        if ($sitemap !== null) {
            $events[] = ['at' => $sitemap['first_seen_at'], 'kind' => 'sitemap', 'title' => 'SEOcc first read it in your sitemap', 'detail' => 'The day the IndexNow sitemap check first listed it; it may have been in the sitemap earlier, so this is not a publication date.'];
            if ($sitemap['submitted_at'] !== null) {
                $events[] = ['at' => $sitemap['submitted_at'], 'kind' => 'indexnow', 'title' => 'Submitted to IndexNow', 'detail' => 'IndexNow reaches Bing, Yandex and other engines, not Google.'];
            }
        }
        if ($crawl !== null && $crawl['crawled_at'] !== null) {
            $events[] = ['at' => $crawl['crawled_at'], 'kind' => 'site_audit', 'title' => 'Site audit fetched it', 'detail' => $crawl['status_code'] !== null ? 'HTTP ' . $crawl['status_code'] . ($crawl['noindex'] ? ', noindex' : '') : ($crawl['error'] ?? 'No response')];
        }
        usort($events, static fn(array $a, array $b): int => [$b['at'], $a['kind']] <=> [$a['at'], $b['kind']]);

        return $events;
    }

    private static function localDay(string $utc, string $timezone): string
    {
        try {
            return (new \DateTimeImmutable($utc, new \DateTimeZone('UTC')))->setTimezone(new \DateTimeZone($timezone))->format('Y-m-d');
        } catch (\Exception) {
            return substr($utc, 0, 10);
        }
    }
}
