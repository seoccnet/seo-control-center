<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\SearchConsole\Import;

use Closure;
use DateTimeZone;
use Psr\Log\LoggerInterface;
use SeoControlCenter\Core\Support\Clock;
use SeoControlCenter\Domain\Integrations\Connection;
use SeoControlCenter\Domain\Integrations\ConnectionRepository;
use SeoControlCenter\Domain\Integrations\IntegrationException;
use SeoControlCenter\Domain\SearchConsole\PerformanceDimension;
use SeoControlCenter\Domain\SearchConsole\ProjectPropertyRepository;
use SeoControlCenter\Domain\SearchConsole\SearchConsoleExtras;
use SeoControlCenter\Domain\SearchConsole\SearchPerformanceProvider;
use SeoControlCenter\Domain\SearchConsole\SearchPerformanceStore;
use SeoControlCenter\Domain\SearchConsole\SearchType;
use SeoControlCenter\Domain\SearchConsole\SyncState;
use SeoControlCenter\Domain\Shared\DateRange;

/**
 * Imports Search Console data for one project in bounded steps
 * (INTEGRATIONS.md -> Search Console imports):
 *
 * 1. Every few hours, read the property's daily totals for the whole
 *    retention window in one request. New finalized days appear here.
 *    Preliminary days, the other Google surfaces (Discover, News, image,
 *    video) and the sitemaps are refreshed at the same time.
 * 2. For each day with impressions but no detail yet (newest first), read the
 *    query, page, device and country breakdowns and store them atomically.
 *
 * The caller decides when to stop ($continue) so a run fits the cron budget;
 * the remaining days are picked up by the next run.
 */
final readonly class SearchConsoleImporter
{
    /** Search Console dates are Pacific Time. */
    private const string GOOGLE_TIME_ZONE = 'America/Los_Angeles';
    /** Preliminary data usually covers the last two or three days; ten leave room for slow finalization. */
    private const int FRESH_DAYS = 10;

    public function __construct(
        private ProjectPropertyRepository $links,
        private ConnectionRepository $connections,
        private SearchPerformanceProvider $provider,
        private SearchPerformanceStore $store,
        private SearchConsoleExtras $extras,
        private Clock $clock,
        private LoggerInterface $logger,
        private int $backfillDays,
        private int $maxRowsPerDay,
        private int $totalsRefreshHours,
    ) {}

    /**
     * @param Closure(): bool $continue asked before each day; false stops the run
     * @throws IntegrationException
     */
    public function run(int $projectId, Closure $continue): ImportOutcome
    {
        $link = $this->links->linkFor($projectId);
        if ($link === null) {
            return ImportOutcome::skipped();
        }

        $state = $this->store->syncState($projectId);
        if ($state !== null && $state->siteUrl !== $link->siteUrl) {
            // A different property now feeds this project: its old data would mix with the new.
            $this->store->purge($projectId);
            $this->logger->info('Search Console property changed; previous imported data removed', ['project_id' => $projectId]);
            $state = null;
        }
        $state ??= SyncState::fresh($projectId, $link->siteUrl);

        $connection = $this->connections->findById($link->connectionId);
        if ($connection === null || !$connection->isActive()) {
            $this->store->saveSyncState($state->with(status: SyncState::ERROR, lastError: 'The Google connection needs to be reconnected (Integrations).'));

            return ImportOutcome::skipped();
        }

        $now = $this->clock->now();
        $this->store->saveSyncState($state = $state->with(status: SyncState::IMPORTING));

        try {
            if ($this->totalsDue($state)) {
                $yesterday = $now->setTimezone(new DateTimeZone(self::GOOGLE_TIME_ZONE))->modify('-1 day')->format('Y-m-d');
                $range = DateRange::endingOn($yesterday, $this->backfillDays);
                $this->store->upsertDailyTotals($projectId, $this->provider->dailyTotals($connection, $link->siteUrl, $range));
                $this->refreshExtras($projectId, $connection, $link->siteUrl, $range);
                $state = $state->with(totalsRefreshedAt: $now->format('Y-m-d H:i:s'));
            }

            $imported = 0;
            while ($continue()) {
                $days = $this->store->pendingDays($projectId, 1);
                if ($days === []) {
                    break;
                }
                $date = $days[0];
                $rows = [];
                foreach (PerformanceDimension::cases() as $dimension) {
                    $rows[$dimension->value] = $this->provider->dayBreakdown($connection, $link->siteUrl, $date, $dimension, $this->maxRowsPerDay);
                }
                $this->store->replaceDay($projectId, $date, $rows);
                if (in_array($date, $this->store->pendingQueryPageDays($projectId, 28), true)) {
                    $this->store->replaceQueryPages($projectId, $date, $this->provider->dayQueryPages($connection, $link->siteUrl, $date, $this->maxRowsPerDay));
                }
                ++$imported;
            }
            // Joint data also backfills the latest 28 days on existing installations.
            while ($continue()) {
                $date = $this->store->pendingQueryPageDays($projectId, 1)[0] ?? null;
                if ($date === null) {
                    break;
                }
                $this->store->replaceQueryPages($projectId, $date, $this->provider->dayQueryPages($connection, $link->siteUrl, $date, $this->maxRowsPerDay));
            }
            // Monthly rollups for long date ranges; months left over are rebuilt by the next run.
            $this->store->refreshRollups($projectId, $continue);
        } catch (IntegrationException $e) {
            $this->store->saveSyncState($state->with(status: SyncState::ERROR, lastError: mb_substr($e->getMessage(), 0, 500)));
            if ($e->authorizationLost) {
                $this->connections->setStatus($connection->id, 'error', mb_substr($e->getMessage(), 0, 500));
            }
            throw $e;
        }

        $remaining = $this->store->countPendingDays($projectId) + count($this->store->pendingQueryPageDays($projectId, 28));
        $this->store->saveSyncState($state->with(
            status: $remaining > 0 ? SyncState::IMPORTING : SyncState::OK,
            lastSuccessAt: $this->clock->now()->format('Y-m-d H:i:s'),
            clearError: true,
        ));

        return new ImportOutcome($imported, $remaining);
    }

    /**
     * Preliminary days, the other Google surfaces and sitemaps. Best effort:
     * a failure here never holds up the web search import and is retried
     * with the next totals refresh, unless Google access itself was lost.
     *
     * @throws IntegrationException when the authorization was lost
     */
    private function refreshExtras(int $projectId, Connection $connection, string $siteUrl, DateRange $range): void
    {
        $today = $this->clock->now()->setTimezone(new DateTimeZone(self::GOOGLE_TIME_ZONE))->format('Y-m-d');
        $steps = [
            // Google does not always name its first incomplete day; today (Pacific) is never complete.
            'fresh' => fn() => $this->extras->replaceFreshDays($projectId, array_map(static fn(\SeoControlCenter\Domain\SearchConsole\FreshDay $d): \SeoControlCenter\Domain\SearchConsole\FreshDay => $d->date >= $today && $d->complete ? new \SeoControlCenter\Domain\SearchConsole\FreshDay($d->date, $d->metrics, false) : $d, $this->provider->freshDailyTotals($connection, $siteUrl, DateRange::endingOn($today, self::FRESH_DAYS)))),
            'sitemaps' => fn() => $this->extras->replaceSitemaps($projectId, $this->provider->sitemaps($connection, $siteUrl)),
        ];
        foreach (SearchType::cases() as $type) {
            $steps[$type->value] = fn() => $this->extras->upsertSearchTypeDays($projectId, $type, $this->provider->searchTypeDailyTotals($connection, $siteUrl, $type, $range));
        }
        foreach ($steps as $part => $step) {
            try {
                $step();
            } catch (IntegrationException $e) {
                if ($e->authorizationLost) {
                    throw $e;
                }
                $this->logger->warning('Search Console extra data not imported', ['project_id' => $projectId, 'part' => $part, 'retryable' => $e->retryable]);
            }
        }
    }

    private function totalsDue(SyncState $state): bool
    {
        if ($state->totalsRefreshedAt === null) {
            return true;
        }
        $last = new \DateTimeImmutable($state->totalsRefreshedAt, new DateTimeZone('UTC'));

        return $this->clock->now()->getTimestamp() - $last->getTimestamp() >= $this->totalsRefreshHours * 3600;
    }
}
