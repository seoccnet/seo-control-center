<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\Alerts;

use SeoControlCenter\Core\Support\Clock;
use SeoControlCenter\Domain\Backlinks\ReferringDomain;
use SeoControlCenter\Domain\Integrations\SpendForecast;
use SeoControlCenter\Domain\Integrations\UsageLedger;
use SeoControlCenter\Domain\Rankings\RankingMove;
use SeoControlCenter\Domain\Rankings\RankingRepository;
use SeoControlCenter\Domain\SearchConsole\SearchPerformanceReader;
use SeoControlCenter\Domain\Shared\DateRange;
use SeoControlCenter\Modules\DataForSEO\Api\DataForSeoClient;
use SeoControlCenter\Modules\DataForSEO\DataForSeoProfileRepository;
use SeoControlCenter\Modules\DataForSEO\SpendWidget;
use SeoControlCenter\Modules\Research\ResearchRepository;
use SeoControlCenter\Modules\SiteAudit\IssueType;
use SeoControlCenter\Modules\SiteAudit\SiteAuditRepository;

/**
 * Finds alert-worthy events in the stored data (never a provider call).
 * Each event comes with a fingerprint, so it is recorded and sent once:
 *
 * - ranking drop: tracked keywords in the top 10 on the previous check and
 *   below it (or gone) on the latest check of the last two days;
 * - traffic drop: Search Console clicks of the latest 7 days at least 30%
 *   below the 7 days before, from at least 100 clicks; once per week;
 * - audit errors: a completed audit of the last week with more errors than
 *   the one before;
 * - lost links: referring domains with rank 30+ in a lost-links lookup of
 *   the last week;
 * - budget (per workspace): the spending forecast reaches a limit, or the
 *   DataForSEO balance lasts less than 7 days; once per month.
 *
 * @phpstan-type Event array{type: AlertType, fingerprint: string, title: string, lines: list<string>, url: string}
 */
final readonly class AlertDetector
{
    public const float TRAFFIC_DROP = 0.3;
    public const int TRAFFIC_MIN_CLICKS = 100;
    public const int MIN_LOST_RANK = 30;
    private const int RECENT_DAYS = 7;

    public function __construct(
        private RankingRepository $rankings,
        private SearchPerformanceReader $searchConsole,
        private SiteAuditRepository $siteAudits,
        private ResearchRepository $research,
        private DataForSeoProfileRepository $dataForSeo,
        private UsageLedger $ledger,
        private Clock $clock,
        private AlertRepository $alerts,
        private \SeoControlCenter\Modules\Indexing\IndexingRepository $indexing,
        private \SeoControlCenter\Modules\Indexing\IndexingService $inspections,
        private ?\SeoControlCenter\Modules\PageMonitoring\PageChangeReader $pageMonitoring = null,
        private ?\SeoControlCenter\Domain\SerpHistory\SerpEventRepository $serpEvents = null,
    ) {}

    /**
     * @return list<Event>
     */
    public function project(int $projectId, string $projectPublicId, string $name): array
    {
        $base = '/projects/' . $projectPublicId;

        $thresholds = $this->alerts->thresholds($projectId);
        $events = array_values(array_filter([
            $this->rankingDrop($projectId, $name, $base, $thresholds),
            $this->trafficDrop($projectId, $name, $base, $thresholds),
            $this->auditErrors($projectId, $name, $base),
            $this->lostLinks($projectId, $name, $base, $thresholds),
            $this->serpChange($projectId, $name, $base),
        ]));
        $binding = $this->inspections->binding($projectId);
        foreach ($binding === null ? [] : $this->indexing->changes($projectId, $binding, $this->clock->now()->modify('-2 days')->format('Y-m-d H:i:s')) as $change) {
            $events[] = ['type' => AlertType::IndexingChange, 'fingerprint' => 'indexing:' . $projectId . ':' . $change['id'], 'title' => $name . ': Google indexing evidence changed', 'lines' => [$change['url'], 'Reported verdict: ' . $change['before'] . ' → ' . $change['after'] . '.', $change['canonical_changed'] ? 'Google’s selected canonical changed; review the saved result.' : 'Google’s selected canonical did not change.', \SeoControlCenter\Modules\Indexing\Inspection::NOTICE], 'url' => $base . '/indexing?q=' . rawurlencode($change['url'])];
        }
        foreach ($this->pageMonitoring?->changes($projectId, since: $this->clock->now()->modify('-7 days')->format('Y-m-d H:i:s')) ?? [] as $change) {
            $before = \SeoControlCenter\Modules\PageMonitoring\PageSnapshot::decode((string) $change['before_snapshot']);
            $after = \SeoControlCenter\Modules\PageMonitoring\PageSnapshot::decode((string) $change['after_snapshot']);
            $lines = [(string) $change['url'], 'Observed between ' . (string) $change['before_at'] . ' and ' . (string) $change['detected_at'] . ' UTC; the actual edit time is unknown.'];
            foreach ($before->changes($after) as $field => $values) {
                $lines[] = $field . ': ' . mb_substr(\SeoControlCenter\Modules\PageMonitoring\PageSnapshot::display($values['before']), 0, 120) . ' → ' . mb_substr(\SeoControlCenter\Modules\PageMonitoring\PageSnapshot::display($values['after']), 0, 120);
            }
            $events[] = ['type' => AlertType::PageChange, 'fingerprint' => 'page-change:' . $projectId . ':' . (string) $change['public_id'], 'title' => $name . ': important page SEO settings changed', 'lines' => $lines, 'url' => $base . '/page-monitoring?change=' . (string) $change['public_id'] . '#change-' . (string) $change['public_id']];
        }
        return $events;
    }

    /**
     * @return list<Event>
     */
    public function workspace(int $workspaceId): array
    {
        $profile = $this->dataForSeo->find($workspaceId);
        if ($profile === null) {
            return [];
        }
        $now = $this->clock->now();
        $forecast = SpendForecast::from(
            $this->ledger->dailySpend($workspaceId, DataForSeoClient::PROVIDER, $now->modify('first day of last month')->format('Y-m-d')),
            $now,
            $this->ledger->budget($workspaceId, DataForSeoClient::PROVIDER),
            $profile->balance,
            SpendWidget::category(...),
        );
        $money = static fn(float $amount): string => '$' . number_format($amount, 2);
        $lines = [];
        if ($forecast->budget->monthlyLimit !== null && $forecast->projectedMonth >= $forecast->budget->monthlyLimit) {
            $lines[] = sprintf('At the current pace, this month will cost about %s; the monthly budget is %s.', $money($forecast->projectedMonth), $money($forecast->budget->monthlyLimit));
        }
        $days = $forecast->balanceDays();
        if ($days !== null && $days < self::RECENT_DAYS) {
            $lines[] = sprintf('The DataForSEO balance of %s lasts about %d more days at the current pace. Top it up at DataForSEO.', $money((float) $forecast->balance), $days);
        }
        if ($lines === []) {
            return [];
        }
        $lines[] = sprintf('Spent so far this month: %s.', $money($forecast->month));

        return [[
            'type' => AlertType::Budget,
            'fingerprint' => 'budget:' . $now->format('Y-m') . ':' . ($days !== null && $days < self::RECENT_DAYS ? 'balance' : 'limit'),
            'title' => 'DataForSEO budget or balance running low',
            'lines' => $lines,
            'url' => '/integrations/dataforseo',
        ]];
    }

    /**
     * Important results-page changes of the last two days (ADR-039, E1).
     *
     * @return Event|null
     */
    private function serpChange(int $projectId, string $name, string $base): ?array
    {
        if ($this->serpEvents === null) {
            return null;
        }
        $D = \SeoControlCenter\Domain\SerpHistory\SerpChangeDetector::class;
        $device = $this->rankings->settings($projectId)->device->value;
        $events = array_values(array_filter(
            $this->serpEvents->list($projectId, $device, ['kinds' => [$D::FEATURE_ADDED, $D::OWN_LEFT, $D::COMPETITOR_ENTERED], 'from' => $this->clock->now()->modify('-2 days')->format('Y-m-d')], 200, 0),
            static fn(\SeoControlCenter\Domain\SerpHistory\SerpEvent $e): bool => match ($e->kind) {
                $D::FEATURE_ADDED => $e->subject === 'ai_overview' && is_int($e->detail['own_position'] ?? null) && $e->detail['own_position'] <= $D::TOP,
                $D::COMPETITOR_ENTERED => ($e->detail['above_you'] ?? false) === true,
                default => true,
            },
        ));
        if ($events === []) {
            return null;
        }
        $lines = array_map(static fn(\SeoControlCenter\Domain\SerpHistory\SerpEvent $e): string => \SeoControlCenter\Domain\SerpHistory\SerpEventText::summary($e) . ' (' . $e->checkedOn . ').', array_slice($events, 0, 8));
        if (count($events) > 8) {
            $lines[] = sprintf('And %d more.', count($events) - 8);
        }
        $lines[] = 'Compared with the previous rank check on the same device; a change on the results page is not proof of its cause.';

        return ['type' => AlertType::SerpChange, 'fingerprint' => 'serp:' . $projectId . ':' . $events[0]->checkedOn . ':' . count($events), 'title' => $name . ': results pages of tracked keywords changed', 'lines' => $lines, 'url' => $base . '/rankings/changes'];
    }

    /**
     * @return Event|null
     */
    private function rankingDrop(int $projectId, string $name, string $base, AlertThresholds $thresholds): ?array
    {
        $device = $this->rankings->settings($projectId)->device;
        $latest = $this->rankings->latestDay($projectId, $device);
        if ($latest === null || $latest->date < $this->clock->now()->modify('-2 days')->format('Y-m-d')) {
            return null;
        }
        $previous = $this->rankings->dayOnOrBefore($projectId, $device, (new \DateTimeImmutable($latest->date))->modify('-1 day')->format('Y-m-d'));
        if ($previous === null) {
            return null;
        }
        $drops = array_values(array_filter(
            $this->rankings->moves($projectId, $device, $previous->date, $latest->date),
            static fn(RankingMove $m): bool => $m->from !== null && $m->from <= $thresholds->rankingCutoff && ($m->to === null || $m->to > $thresholds->rankingCutoff),
        ));
        if ($drops === []) {
            return null;
        }
        usort($drops, static fn(RankingMove $a, RankingMove $b): int => [$a->from, $a->keyword] <=> [$b->from, $b->keyword]);
        $lines = array_map(static fn(RankingMove $m): string => sprintf('“%s”: from #%d to %s', $m->keyword, (int) $m->from, $m->to === null ? 'not in the top 100' : '#' . $m->to), array_slice($drops, 0, 8));
        if (count($drops) > 8) {
            $lines[] = sprintf('and %d more', count($drops) - 8);
        }

        return [
            'type' => AlertType::RankingDrop,
            'fingerprint' => 'rank:' . $projectId . ':' . $latest->date,
            'title' => sprintf('%s: %d keyword%s dropped out of the top %d', $name, count($drops), count($drops) === 1 ? '' : 's', $thresholds->rankingCutoff),
            'lines' => [sprintf('Rank check of %s compared with %s:', $latest->date, $previous->date), ...$lines],
            'url' => $base . '/rankings',
        ];
    }

    /**
     * @return Event|null
     */
    private function trafficDrop(int $projectId, string $name, string $base, AlertThresholds $thresholds): ?array
    {
        $span = $this->searchConsole->dataSpan($projectId);
        if ($span === null || $span['last'] < $this->clock->now()->modify('-' . self::RECENT_DAYS . ' days')->format('Y-m-d')) {
            return null;
        }
        $range = DateRange::endingOn($span['last'], 7);
        if ($span['first'] > $range->previous()->fromString()) {
            return null;
        }
        $now = $this->searchConsole->totals($projectId, $range)->clicks;
        $before = $this->searchConsole->totals($projectId, $range->previous())->clicks;
        if ($before < $thresholds->minimumClicks || $now > $before * (1 - $thresholds->trafficDrop / 100)) {
            return null;
        }
        $drop = (int) round(($before - $now) / $before * 100);

        return [
            'type' => AlertType::TrafficDrop,
            'fingerprint' => 'traffic:' . $projectId . ':' . (new \DateTimeImmutable($span['last']))->format('o-\WW'),
            'title' => sprintf('%s: clicks down %d%% in the last 7 days', $name, $drop),
            'lines' => [
                sprintf('%s clicks from %s to %s, against %s in the 7 days before.', number_format($now), $range->fromString(), $range->toString(), number_format($before)),
                'Check the pages that lost the most under Performance and the open opportunities.',
            ],
            'url' => $base . '/performance',
        ];
    }

    /**
     * @return Event|null
     */
    private function auditErrors(int $projectId, string $name, string $base): ?array
    {
        $completed = array_values(array_filter($this->siteAudits->history($projectId, 5), static fn($a): bool => $a->status === 'completed'));
        $latest = $completed[0] ?? null;
        if ($latest === null || $latest->errors === 0 || (string) $latest->finishedAt < $this->clock->now()->modify('-' . self::RECENT_DAYS . ' days')->format('Y-m-d H:i:s')) {
            return null;
        }
        $previous = $completed[1] ?? null;
        if ($previous !== null && $latest->errors <= $previous->errors) {
            return null;
        }
        $before = $previous !== null ? $this->siteAudits->issueCounts($previous->id) : [];
        $lines = [];
        foreach ($this->siteAudits->issueCounts($latest->id) as $value => $count) {
            $type = IssueType::tryFrom($value);
            if ($type !== null && $type->severity() === 'error' && $count > ($before[$value] ?? 0)) {
                $lines[] = sprintf('%s: %d page%s%s', $type->label(), $count, $count === 1 ? '' : 's', isset($before[$value]) ? sprintf(' (was %d)', $before[$value]) : '');
            }
        }

        return [
            'type' => AlertType::AuditErrors,
            'fingerprint' => 'audit:' . $latest->publicId,
            'title' => sprintf('%s: the site audit found %d error%s', $name, $latest->errors, $latest->errors === 1 ? '' : 's'),
            'lines' => [...($lines !== [] ? $lines : [sprintf('%d errors, %s before.', $latest->errors, $previous !== null ? (string) $previous->errors : 'no audit')]), sprintf('Health score: %s.', $latest->healthScore ?? '—')],
            'url' => $base . '/site-audit',
        ];
    }

    /**
     * @return Event|null
     */
    private function lostLinks(int $projectId, string $name, string $base, AlertThresholds $thresholds): ?array
    {
        $result = $this->research->find($projectId, 'bl_lost');
        if ($result === null || $result->fetchedAt === null || $result->fetchedAt < $this->clock->now()->modify('-' . self::RECENT_DAYS . ' days')->format('Y-m-d H:i:s')) {
            return null;
        }
        $strong = array_values(array_filter($result->referringDomains(), static fn(ReferringDomain $d): bool => $d->rank !== null && $d->rank >= $thresholds->lostDomainRank));
        if ($strong === []) {
            return null;
        }
        usort($strong, static fn(ReferringDomain $a, ReferringDomain $b): int => ($b->rank ?? 0) <=> ($a->rank ?? 0));

        return [
            'type' => AlertType::LostLinks,
            'fingerprint' => 'lost:' . $projectId . ':' . $result->fetchedAt,
            'title' => sprintf('%s: %d strong backlink%s lost', $name, count($strong), count($strong) === 1 ? '' : 's'),
            'lines' => [
                ...array_map(static fn(ReferringDomain $d): string => sprintf('%s (domain rank %d), lost %s', $d->domain, (int) $d->rank, $d->lostDate ?? 'recently'), array_slice($strong, 0, 8)),
                'Ask the sites to restore the links, or redirect URLs that changed.',
            ],
            'url' => $base . '/backlinks',
        ];
    }
}
