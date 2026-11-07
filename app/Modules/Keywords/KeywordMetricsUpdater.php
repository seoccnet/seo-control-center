<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\Keywords;

use Closure;
use Psr\Log\LoggerInterface;
use SeoControlCenter\Core\Support\Clock;
use SeoControlCenter\Domain\Integrations\BudgetExceededException;
use SeoControlCenter\Domain\Integrations\IntegrationException;
use SeoControlCenter\Domain\Keywords\Keyword;
use SeoControlCenter\Domain\Keywords\KeywordMetrics;
use SeoControlCenter\Domain\Keywords\KeywordMetricsProvider;
use SeoControlCenter\Domain\Keywords\KeywordRepository;
use SeoControlCenter\Domain\Keywords\KeywordSyncState;

/**
 * Fetches metrics for a project's keywords that have none or whose metrics
 * are older than the refresh window, one provider request per locale and
 * batch. Keywords the provider has no data for are stored empty, so they
 * are not requested (and paid for) again before the next refresh.
 */
final readonly class KeywordMetricsUpdater
{
    /** Google Ads volumes change monthly; newer metrics are reused. */
    public const int REFRESH_DAYS = 30;

    public function __construct(
        private KeywordRepository $keywords,
        private KeywordMetricsProvider $provider,
        private Clock $clock,
        private LoggerInterface $logger,
    ) {}

    /**
     * @param Closure(): bool $continue false when the run must stop (time budget)
     * @return bool true when all keywords are up to date, false when more work remains
     * @throws BudgetExceededException
     * @throws IntegrationException
     */
    public function run(int $projectId, int $workspaceId, Closure $continue): bool
    {
        if (!$this->provider->isAvailable($workspaceId)) {
            $this->keywords->setSyncState($projectId, KeywordSyncState::ERROR, 'DataForSEO is not connected for this workspace, or its credentials were rejected.');

            return true;
        }

        $staleBefore = $this->clock->now()->modify(sprintf('-%d days', self::REFRESH_DAYS));
        $batch = max(1, $this->provider->maxKeywordsPerRequest());
        $this->keywords->setSyncState($projectId, KeywordSyncState::RUNNING);

        try {
            while (true) {
                $groups = $this->keywords->needingMetrics($projectId, $workspaceId, $staleBefore, $batch * 5);
                if ($groups === []) {
                    $this->keywords->setSyncState($projectId, KeywordSyncState::OK);

                    return true;
                }
                foreach ($groups as ['locale' => $locale, 'keywords' => $keywords]) {
                    foreach (array_chunk($keywords, $batch) as $chunk) {
                        if (!$continue()) {
                            return false;
                        }
                        $fetched = [];
                        foreach ($this->provider->fetch($workspaceId, $projectId, $chunk, $locale) as $metrics) {
                            $fetched[Keyword::normalize($metrics->keyword)] = $metrics;
                        }
                        foreach ($chunk as $keyword) {
                            $fetched[$keyword] ??= KeywordMetrics::empty($keyword);
                        }
                        $this->keywords->storeMetrics($workspaceId, $locale, array_values($fetched), $this->clock->now());
                        $this->logger->info('Keyword metrics fetched', ['project_id' => $projectId, 'locale' => $locale->key(), 'keywords' => count($chunk)]);
                    }
                }
            }
        } catch (BudgetExceededException $e) {
            $this->keywords->setSyncState($projectId, KeywordSyncState::ERROR, 'Not fetched: ' . $e->getMessage());
            throw $e;
        } catch (IntegrationException $e) {
            $this->keywords->setSyncState($projectId, KeywordSyncState::ERROR, $e->retryable
                ? 'DataForSEO is temporarily unavailable; the update will be retried automatically.'
                : $e->getMessage());
            throw $e;
        }
    }
}
