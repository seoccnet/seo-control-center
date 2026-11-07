<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\DataForSEO\Persistence;

use SeoControlCenter\Core\Database\Connection;
use SeoControlCenter\Core\Support\Clock;
use SeoControlCenter\Domain\Integrations\ProviderResponseStore;

/**
 * Raw responses in `provider_responses`, gzip-compressed.
 */
final readonly class PdoProviderResponseStore implements ProviderResponseStore
{
    private const int PURGE_BATCH = 2000;

    public function __construct(
        private Connection $db,
        private Clock $clock,
    ) {}

    public function store(int $workspaceId, ?int $usageId, string $provider, string $operation, int $httpStatus, string $body, \DateTimeImmutable $expiresAt): void
    {
        $this->db->execute(
            'INSERT INTO provider_responses (workspace_id, api_usage_id, provider, operation, http_status, body, created_at, expires_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $workspaceId,
                $usageId,
                $provider,
                $operation,
                $httpStatus,
                (string) gzencode($body, 6),
                $this->clock->now()->format('Y-m-d H:i:s'),
                $expiresAt->format('Y-m-d H:i:s'),
            ],
        );
    }

    public function purgeExpired(\DateTimeImmutable $now): int
    {
        return $this->db->execute(
            'DELETE FROM provider_responses WHERE expires_at < ? LIMIT ' . self::PURGE_BATCH,
            [$now->format('Y-m-d H:i:s')],
        );
    }
}
