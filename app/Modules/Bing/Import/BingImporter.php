<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\Bing\Import;

use Psr\Log\LoggerInterface;
use SeoControlCenter\Domain\Integrations\IntegrationException;
use SeoControlCenter\Domain\SearchConsole\PerformanceDimension;
use SeoControlCenter\Modules\Bing\Api\BingWebmasterClient;
use SeoControlCenter\Modules\Bing\BingAccountRepository;
use SeoControlCenter\Modules\Bing\BingDataRepository;
use SeoControlCenter\Modules\Bing\BingSiteLink;

/**
 * Imports one project's Bing data: daily totals plus weekly queries and
 * pages, then crawl statistics and inbound link counts. Bing returns its
 * whole history with every call (about six months), so the stored data is
 * replaced in one transaction; five to nine API calls per run.
 */
final readonly class BingImporter
{
    public function __construct(
        private BingDataRepository $data,
        private BingAccountRepository $accounts,
        private BingWebmasterClient $client,
        private LoggerInterface $logger,
    ) {}

    /**
     * @throws IntegrationException when the failure is temporary (the job retries)
     */
    public function run(int $projectId): void
    {
        $link = $this->data->link($projectId);
        if ($link === null) {
            return;
        }
        $apiKey = $this->accounts->apiKey($link->workspaceId);
        if ($apiKey === null) {
            $this->data->setStatus($projectId, BingSiteLink::ERROR, 'Bing is not connected in this workspace. Connect it under Integrations → Bing.');

            return;
        }

        $this->data->setStatus($projectId, BingSiteLink::IMPORTING);
        try {
            $days = $this->client->daily($apiKey, $link->siteUrl);
            $rows = [
                ...$this->client->weekly($apiKey, $link->siteUrl, PerformanceDimension::Query),
                ...$this->client->weekly($apiKey, $link->siteUrl, PerformanceDimension::Page),
            ];
        } catch (IntegrationException $e) {
            if ($e->authorizationLost) {
                $this->accounts->setError($link->workspaceId, $e->getMessage());
            }
            $this->data->setStatus($projectId, BingSiteLink::ERROR, $e->getMessage());
            $this->logger->warning('Bing import failed', ['project_id' => $projectId, 'retryable' => $e->retryable, 'error' => $e->getMessage()]);
            if ($e->retryable) {
                throw $e;
            }

            return;
        }

        $this->data->replaceData($projectId, $days, $rows);
        $this->importCrawlData($projectId, $apiKey, $link->siteUrl);
        $this->data->setStatus($projectId, BingSiteLink::OK);
        $this->logger->info('Bing import finished', ['project_id' => $projectId, 'days' => count($days), 'rows' => count($rows)]);
    }

    /**
     * Crawl statistics and inbound link counts. Best effort: the performance
     * data above is already stored, and the next import tries again.
     */
    private function importCrawlData(int $projectId, #[\SensitiveParameter] string $apiKey, string $siteUrl): void
    {
        try {
            $this->data->replaceCrawlData($projectId, $this->client->crawlStats($apiKey, $siteUrl), $this->client->linkCounts($apiKey, $siteUrl));
        } catch (IntegrationException $e) {
            $this->logger->warning('Bing crawl data not imported', ['project_id' => $projectId, 'retryable' => $e->retryable, 'error' => $e->getMessage()]);
        }
    }
}
