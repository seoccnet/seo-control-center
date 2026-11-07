<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\DataForSEO\Jobs;

use Psr\Log\LoggerInterface;
use SeoControlCenter\Core\Queue\JobContext;
use SeoControlCenter\Core\Queue\JobHandler;
use SeoControlCenter\Core\Support\Clock;
use SeoControlCenter\Domain\Integrations\ProviderResponseStore;
use SeoControlCenter\Domain\Integrations\UsageLedger;

/**
 * Hourly: deletes expired raw provider responses and closes usage
 * reservations left open by a crashed worker (charged at their estimate,
 * because the request may have been billed).
 */
final readonly class DataForSeoCleanupJob implements JobHandler
{
    public const string TYPE = 'dataforseo.cleanup';

    /** Longer than any single request can take, including cron budget and retries. */
    private const string STALE_AFTER = '-1 hour';

    public function __construct(
        private ProviderResponseStore $responses,
        private UsageLedger $ledger,
        private Clock $clock,
        private LoggerInterface $logger,
    ) {}

    public function handle(array $payload, JobContext $context): void
    {
        $now = $this->clock->now();
        $counts = [
            'provider_responses' => $this->responses->purgeExpired($now),
            'stale_reservations' => $this->ledger->closeStale($now->modify(self::STALE_AFTER)),
        ];
        if ($counts['stale_reservations'] > 0) {
            $this->logger->warning('Closed stale provider usage reservations', $counts);
        } else {
            $this->logger->info('Provider cleanup finished', $counts);
        }
    }
}
