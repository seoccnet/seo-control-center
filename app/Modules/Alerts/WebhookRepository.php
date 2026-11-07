<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\Alerts;

interface WebhookRepository
{
    public function find(int $projectId): ?AlertWebhook;
    public function save(AlertWebhook $webhook): void;
    public function remove(int $projectId): void;
    public function claim(int $alertId, int $projectId, string $version): bool;
    public function finish(int $alertId, string $status, ?int $httpStatus, string $message): void;
    /** @return list<array{status: string, http_status: ?int, message: ?string, created_at: string}> */
    public function history(int $projectId): array;
}
