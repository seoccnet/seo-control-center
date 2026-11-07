<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\DataForSEO\Adapters;

use SeoControlCenter\Domain\Backlinks\AnchorText;
use SeoControlCenter\Domain\Backlinks\BacklinkProfile;
use SeoControlCenter\Domain\Backlinks\BacklinksProvider;
use SeoControlCenter\Domain\Backlinks\PageBacklink;
use SeoControlCenter\Domain\Backlinks\ReferringDomain;
use SeoControlCenter\Domain\Integrations\IntegrationException;
use SeoControlCenter\Modules\DataForSEO\Api\DataForSeoClient;
use SeoControlCenter\Modules\DataForSEO\Api\DataForSeoErrors;
use SeoControlCenter\Modules\DataForSEO\DataForSeoProfileRepository;

/**
 * Backlinks from DataForSEO's Backlinks API (live, rank on a 0-100 scale).
 * DataForSEO bills $0.024 per request plus $0.000036 per row; the settled
 * cost is what DataForSEO reports. Some accounts have to activate the
 * Backlinks API in the DataForSEO dashboard first.
 */
final readonly class DataForSeoBacklinks implements BacklinksProvider
{
    public const string SUMMARY = '/v3/backlinks/summary/live';
    public const string REFERRING_DOMAINS = '/v3/backlinks/referring_domains/live';
    public const string ANCHORS = '/v3/backlinks/anchors/live';
    public const string LINKS = '/v3/backlinks/backlinks/live';
    public const string INTERSECTION = '/v3/backlinks/domain_intersection/live';
    public const float COST_PER_REQUEST = 0.024;
    public const float COST_PER_ROW = 0.000036;
    private const int MAX_LIMIT = 1000;
    /** domain_intersection takes up to 20 targets. */
    private const int MAX_COMPETITORS = 20;

    public function __construct(
        private DataForSeoClient $client,
        private DataForSeoProfileRepository $profiles,
    ) {}

    public function isAvailable(int $workspaceId): bool
    {
        return $this->profiles->find($workspaceId)?->isActive() ?? false;
    }

    public function estimatedCost(int $requests, int $rows = 0): float
    {
        return round(self::COST_PER_REQUEST * max(1, $requests) + self::COST_PER_ROW * max(0, $rows), 6);
    }

    public function profiles(int $workspaceId, ?int $projectId, array $domains): array
    {
        $profiles = [];
        foreach (array_values(array_unique($domains)) as $domain) {
            try {
                $task = $this->task($workspaceId, $projectId, 'backlinks_summary', self::SUMMARY, [
                    'target' => $domain,
                    'include_subdomains' => true,
                    'backlinks_status_type' => 'live',
                    'rank_scale' => 'one_hundred',
                ], $this->estimatedCost(1, 1));
            } catch (IntegrationException $e) {
                if ($e->providerStatusCode !== 40501) {
                    if ($e->retryable && $profiles !== []) {
                        throw new IntegrationException('The backlink lookup partially completed. Automatic retry stopped to avoid paying for completed domains again. ' . $e->getMessage(), false, $e->authorizationLost, providerStatusCode: $e->providerStatusCode);
                    }
                    throw $e;
                }
                continue; // A domain DataForSEO rejects is left out; the others still count.
            }
            $r = $task['result'][0] ?? null;
            if (!is_array($r)) {
                throw new IntegrationException('DataForSEO returned an invalid backlink summary. Previous data was retained.', false);
            }
            if (array_key_exists('target', $r) && (!is_string($r['target']) || strtolower(trim($r['target'])) !== strtolower(trim($domain)))) {
                throw new IntegrationException('DataForSEO returned a backlink summary for another target. Previous data was retained.', false);
            }
            $rank = self::int($r['rank'] ?? null);
            $referringDomains = self::int($r['referring_domains'] ?? null);
            // backlink_history stores rank as SMALLINT UNSIGNED and domains as INT UNSIGNED.
            if (($rank !== null && $rank > 65535) || ($referringDomains !== null && $referringDomains > 4294967295)) {
                throw new IntegrationException('DataForSEO returned a backlink profile outside storage limits. Previous data was retained.', false);
            }
            $profiles[] = new BacklinkProfile(
                $domain,
                $rank,
                self::int($r['backlinks'] ?? null),
                $referringDomains,
                self::int($r['referring_domains_nofollow'] ?? null),
                self::int($r['backlinks_spam_score'] ?? null),
                self::int($r['broken_backlinks'] ?? null),
                self::date($r['first_seen'] ?? null),
            );
        }

        return $profiles;
    }

    public function pageProfiles(int $workspaceId, ?int $projectId, array $urls): array
    {
        $profiles = [];
        foreach (array_values(array_unique($urls)) as $url) {
            if (!str_starts_with($url, 'https://') && !str_starts_with($url, 'http://')) {
                continue;
            }
            try {
                $task = $this->task($workspaceId, $projectId, 'backlinks_summary', self::SUMMARY, [
                    'target' => $url,
                    'backlinks_status_type' => 'live',
                    'rank_scale' => 'one_hundred',
                ], $this->estimatedCost(1, 1));
            } catch (IntegrationException $e) {
                if ($e->providerStatusCode !== 40501) {
                    if ($e->retryable && $profiles !== []) {
                        throw new IntegrationException('The backlink lookup partially completed. Automatic retry stopped to avoid paying for completed pages again. ' . $e->getMessage(), false, $e->authorizationLost, providerStatusCode: $e->providerStatusCode);
                    }
                    throw $e;
                }
                continue; // A page DataForSEO rejects is left out; the others still count.
            }
            $r = $task['result'][0] ?? null;
            if (!is_array($r)) {
                throw new IntegrationException('DataForSEO returned an invalid backlink summary. Previous data was retained.', false);
            }
            // DataForSEO may echo the page with or without a trailing slash or scheme.
            $echo = is_string($r['target'] ?? null) ? self::pageKey($r['target']) : null;
            if ($echo !== null && $echo !== self::pageKey($url)) {
                throw new IntegrationException('DataForSEO returned a backlink summary for another page. Previous data was retained.', false);
            }
            $profiles[] = new BacklinkProfile(
                $url,
                self::int($r['rank'] ?? null),
                self::int($r['backlinks'] ?? null),
                self::int($r['referring_domains'] ?? null),
                self::int($r['referring_domains_nofollow'] ?? null),
                self::int($r['backlinks_spam_score'] ?? null),
                self::int($r['broken_backlinks'] ?? null),
                self::date($r['first_seen'] ?? null),
            );
        }

        return $profiles;
    }

    private static function pageKey(string $url): string
    {
        return rtrim((string) preg_replace('#^(https?://)?(www\.)?#i', '', strtolower(trim($url))), '/');
    }

    public function referringDomains(int $workspaceId, ?int $projectId, string $domain, int $limit, ?string $since = null, bool $lost = false): array
    {
        $limit = self::limit($limit);
        $body = [
            'target' => $domain,
            'limit' => $limit,
            'backlinks_status_type' => $lost ? 'lost' : 'live',
            'rank_scale' => 'one_hundred',
            'order_by' => [$since !== null ? ($lost ? 'lost_date,desc' : 'first_seen,desc') : 'rank,desc'],
        ];
        if ($since !== null) {
            $body['filters'] = [$lost ? 'lost_date' : 'first_seen', '>=', $since . ' 00:00:00 +00:00'];
        }
        $task = $this->task($workspaceId, $projectId, $lost ? 'backlinks_lost_domains' : 'backlinks_referring_domains', self::REFERRING_DOMAINS, $body, $this->estimatedCost(1, $limit));

        $domains = [];
        foreach (self::items($task) as $item) {
            if (!is_string($item['domain'] ?? null) || $item['domain'] === '') {
                continue;
            }
            $domains[] = new ReferringDomain(
                strtolower($item['domain']),
                self::int($item['rank'] ?? null),
                self::int($item['backlinks'] ?? null),
                self::int($item['backlinks_spam_score'] ?? null),
                self::date($item['first_seen'] ?? null),
                self::date($item['lost_date'] ?? null),
            );
        }

        return $domains;
    }

    public function anchors(int $workspaceId, ?int $projectId, string $domain, int $limit): array
    {
        $limit = self::limit($limit);
        $task = $this->task($workspaceId, $projectId, 'backlinks_anchors', self::ANCHORS, [
            'target' => $domain,
            'limit' => $limit,
            'order_by' => ['backlinks,desc'],
        ], $this->estimatedCost(1, $limit));

        $anchors = [];
        foreach (self::items($task) as $item) {
            if (!is_string($item['anchor'] ?? null)) {
                continue;
            }
            $anchors[] = new AnchorText(
                mb_substr(trim($item['anchor']), 0, 300),
                self::int($item['backlinks'] ?? null) ?? 0,
                self::int($item['referring_domains'] ?? null),
                self::date($item['first_seen'] ?? null),
            );
        }

        return $anchors;
    }

    public function pageLinks(int $workspaceId, ?int $projectId, string $domain, int $limit, ?string $since = null, bool $lost = false): array
    {
        $limit = self::limit($limit);
        $body = ['target' => $domain, 'limit' => $limit, 'mode' => 'as_is', 'include_subdomains' => true,
            'exclude_internal_backlinks' => true, 'backlinks_status_type' => $lost ? 'lost' : 'live', 'rank_scale' => 'one_hundred',
            // Individual backlinks have no lost_date (only referring domains do; DataForSEO rejects it
            // as an invalid order_by/filter field). last_seen is the last crawl that still found the link.
            'order_by' => [$lost ? 'last_seen,desc' : 'domain_from_rank,desc']];
        if ($since !== null) {
            $body['filters'] = [$lost ? 'last_seen' : 'first_seen', '>=', $since . ' 00:00:00 +00:00'];
        }
        $task = $this->task($workspaceId, $projectId, $lost ? 'backlinks_lost_links' : 'backlinks_page_links', self::LINKS, $body, $this->estimatedCost(1, $limit));
        $links = [];
        foreach (self::items($task) as $item) {
            $link = PageBacklink::fromArray(['source_url' => $item['url_from'] ?? null, 'target_url' => $item['url_to'] ?? null,
                'anchor' => $item['anchor'] ?? '', 'domain_rank' => self::int($item['domain_from_rank'] ?? null), 'dofollow' => $item['dofollow'] ?? false,
                'first_seen' => self::date($item['first_seen'] ?? null), 'lost_on' => $lost ? self::date($item['lost_date'] ?? $item['last_seen'] ?? null) : null]);
            if ($link !== null) {
                $links[] = $link;
            }
        }
        return $links;
    }

    public function linkGap(int $workspaceId, ?int $projectId, string $domain, array $competitors, int $limit): array
    {
        $competitors = array_values(array_slice(array_unique(array_filter($competitors, static fn(string $c): bool => $c !== '' && $c !== $domain)), 0, self::MAX_COMPETITORS));
        if ($competitors === []) {
            return [];
        }
        $limit = self::limit($limit);
        $targets = [];
        foreach ($competitors as $i => $competitor) {
            $targets[(string) ($i + 1)] = $competitor;
        }
        $task = $this->task($workspaceId, $projectId, 'backlinks_link_gap', self::INTERSECTION, [
            'targets' => $targets,
            'exclude_targets' => [$domain],
            'limit' => $limit,
            'rank_scale' => 'one_hundred',
        ], $this->estimatedCost(1, $limit));

        $domains = [];
        foreach (self::items($task) as $item) {
            $per = is_array($item['domain_intersection'] ?? null) ? array_filter($item['domain_intersection'], is_array(...)) : [];
            $name = null;
            $rank = $backlinks = $spam = null;
            $first = null;
            $linked = 0;
            foreach ($per as $entry) {
                $name ??= is_string($entry['target'] ?? null) && $entry['target'] !== '' ? strtolower($entry['target']) : (is_string($entry['domain'] ?? null) ? strtolower($entry['domain']) : null);
                $count = self::int($entry['backlinks'] ?? null) ?? 0;
                $total = $backlinks ?? 0;
                if ($count > PHP_INT_MAX - $total) {
                    throw new IntegrationException('DataForSEO returned an overflowing backlink total. Previous data was retained.', false);
                }
                if ($count > 0) {
                    $linked++;
                }
                $rank = max($rank ?? 0, self::int($entry['rank'] ?? null) ?? 0);
                $backlinks = $total + $count;
                $spam ??= self::int($entry['backlinks_spam_score'] ?? null);
                $date = self::date($entry['first_seen'] ?? null);
                $first = $date !== null && ($first === null || $date < $first) ? $date : $first;
            }
            if ($name !== null && !isset($domains[$name])) {
                $domains[$name] = new ReferringDomain($name, $rank, $backlinks, $spam, $first, null, $linked);
            }
        }
        $domains = array_values($domains);
        usort($domains, static fn(ReferringDomain $a, ReferringDomain $b): int => [$b->targets ?? 0, $b->rank ?? 0] <=> [$a->targets ?? 0, $a->rank ?? 0]);

        return $domains;
    }

    /**
     * @param array<string, mixed> $body
     * @return array<mixed>
     * @throws IntegrationException
     */
    private function task(int $workspaceId, ?int $projectId, string $operation, string $path, array $body, float $estimate): array
    {
        $tasks = $this->client->post($workspaceId, $projectId, $operation, $path, [$body], $estimate);
        $task = $tasks[0] ?? throw new IntegrationException('DataForSEO returned no task.', true);
        $error = DataForSeoErrors::taskError($task);
        if ($error !== null) {
            throw $error;
        }
        if (($task['status_code'] ?? null) !== DataForSeoErrors::OK) {
            throw new IntegrationException('DataForSEO returned an incomplete backlink task. Previous data was retained.', false);
        }

        return $task;
    }

    /**
     * @param array<mixed> $task
     * @return list<array<mixed>>
     */
    private static function items(array $task): array
    {
        return DataForSeoErrors::items($task);
    }

    private static function limit(int $limit): int
    {
        return max(1, min(self::MAX_LIMIT, $limit));
    }

    private static function int(mixed $value): ?int
    {
        if ($value === null) {
            return null;
        }
        if ((!is_int($value) && !is_float($value)) || !is_finite((float) $value) || $value < 0 || (is_float($value) && $value >= PHP_INT_MAX)) {
            throw new IntegrationException('DataForSEO returned an invalid numeric backlink metric. Previous data was retained.', false);
        }
        return is_int($value) ? $value : (int) round($value);
    }

    /**
     * "2024-01-15 10:00:00 +00:00" → "2024-01-15".
     */
    private static function date(mixed $value): ?string
    {
        return is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}/', $value) === 1 ? substr($value, 0, 10) : null;
    }
}
