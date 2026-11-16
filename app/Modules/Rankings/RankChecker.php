<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\Rankings;

use Closure;
use Psr\Log\LoggerInterface;
use SeoControlCenter\Domain\Integrations\BudgetExceededException;
use SeoControlCenter\Domain\Integrations\IntegrationException;
use SeoControlCenter\Domain\Rankings\RankingRepository;
use SeoControlCenter\Domain\Rankings\RankingSettings;
use SeoControlCenter\Domain\Rankings\RankingSyncState;
use SeoControlCenter\Domain\Rankings\RankSnapshot;
use SeoControlCenter\Domain\Rankings\SerpDevice;
use SeoControlCenter\Domain\Rankings\SerpObserver;
use SeoControlCenter\Domain\Rankings\SerpProvider;

/**
 * Checks a project's keywords for one day: one SERP request per keyword
 * not yet checked that day (so re-runs never pay twice), then updates the
 * daily aggregate. Works in small batches within the cron time budget.
 */
final readonly class RankChecker
{
    private const int BATCH = 20;

    public function __construct(
        private RankingRepository $rankings,
        private SerpProvider $serp,
        private LoggerInterface $logger,
        private ?SerpObserver $observer = null,
    ) {}

    /**
     * @param Closure(): bool $continue false when the run must stop (time budget)
     * @return bool true when the day is complete (or nothing is to be done), false when more work remains
     * @throws BudgetExceededException
     * @throws IntegrationException
     */
    public function run(int $projectId, int $workspaceId, string $date, Closure $continue): bool
    {
        $settings = $this->rankings->settings($projectId);
        $domain = $this->rankings->projectDomain($projectId, $workspaceId);
        if (!$settings->enabled || $domain === null) {
            return true; // tracking switched off or project deleted: spend nothing
        }
        if (!$this->serp->isAvailable($workspaceId)) {
            $this->rankings->setSyncState($projectId, RankingSyncState::ERROR, 'DataForSEO is not connected for this workspace, or its credentials were rejected.');

            return true;
        }

        $this->rankings->setSyncState($projectId, RankingSyncState::RUNNING);
        try {
            // The primary device first; the other one only when both are tracked (1.6.0). Competitors,
            // results-page changes and AI Overviews follow the primary device only.
            foreach ($settings->devices() as $device) {
                $primary = $device === $settings->device;
                while (true) {
                    $batch = $this->rankings->unchecked($projectId, $device, $date, self::BATCH);
                    if ($batch === []) {
                        $this->aggregate($projectId, $workspaceId, $device, $date, $primary);
                        break;
                    }
                    foreach ($batch as $keyword) {
                        if (!$continue()) {
                            $this->aggregate($projectId, $workspaceId, $device, $date, $primary);

                            return false;
                        }
                        $serp = $this->serp->organic($workspaceId, $projectId, $keyword['keyword'], $keyword['locale'], $device, RankingSettings::DEPTH);
                        $this->rankings->storeSnapshot(RankSnapshot::fromSerp($keyword['id'], $projectId, $domain, $date, $serp));
                        if ($primary) {
                            $this->observer?->observe($projectId, $keyword['id'], $date, $serp);
                        }
                    }
                    $this->logger->info('Rankings checked', ['project_id' => $projectId, 'date' => $date, 'device' => $device->value, 'keywords' => count($batch)]);
                }
            }
            $this->rankings->setSyncState($projectId, RankingSyncState::OK, checkedOn: $date);

            return true;
        } catch (BudgetExceededException $e) {
            $this->aggregate($projectId, $workspaceId, $settings->device, $date);
            $this->rankings->setSyncState($projectId, RankingSyncState::ERROR, 'Stopped: ' . $e->getMessage());
            throw $e;
        } catch (IntegrationException $e) {
            $this->aggregate($projectId, $workspaceId, $settings->device, $date);
            // Keep the provider's reason visible; a generic message hid what actually failed.
            $this->rankings->setSyncState($projectId, RankingSyncState::ERROR, $e->retryable
                ? mb_substr($e->getMessage(), 0, 400) . ' The check will be retried automatically.'
                : $e->getMessage());
            throw $e;
        }
    }

    private function aggregate(int $projectId, int $workspaceId, SerpDevice $device, string $date, bool $notify = true): void
    {
        $this->rankings->aggregate($projectId, $workspaceId, $device, $date);
        if ($notify) {
            $this->observer?->dayChecked($projectId, $workspaceId, $device, $date);
        }
    }
}
