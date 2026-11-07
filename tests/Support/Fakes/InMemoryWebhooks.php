<?php

declare(strict_types=1);

namespace SeoControlCenter\Tests\Support\Fakes;

use SeoControlCenter\Modules\Alerts\AlertWebhook;
use SeoControlCenter\Modules\Alerts\WebhookRepository;

final class InMemoryWebhooks implements WebhookRepository
{
    /** @var array<int, AlertWebhook> */
    private array $hooks = [];
    /** @var array<int, array{project: int, status: string, http_status: ?int, message: ?string, created_at: string}> */
    private array $deliveries = [];
    public function find(int $projectId): ?AlertWebhook
    {
        return $this->hooks[$projectId] ?? null;
    }
    public function save(AlertWebhook $webhook): void
    {
        $this->hooks[$webhook->projectId] = $webhook;
    }
    public function remove(int $projectId): void
    {
        unset($this->hooks[$projectId]);
    }
    public function claim(int $alertId, int $projectId, string $version): bool
    {
        if (isset($this->deliveries[$alertId])) {
            return false;
        }
        $this->deliveries[$alertId] = ['project' => $projectId, 'status' => 'sending', 'http_status' => null, 'message' => null, 'created_at' => '2026-10-05 12:00:00'];
        return true;
    }
    public function finish(int $alertId, string $status, ?int $httpStatus, string $message): void
    {
        if (!isset($this->deliveries[$alertId])) {
            return;
        }
        $this->deliveries[$alertId]['status'] = $status;
        $this->deliveries[$alertId]['http_status'] = $httpStatus;
        $this->deliveries[$alertId]['message'] = $message;
    }
    public function history(int $projectId): array
    {
        $rows = [];
        foreach (array_reverse($this->deliveries) as $row) {
            if ($row['project'] === $projectId) {
                unset($row['project']);
                $rows[] = $row;
            }
        }
        return array_slice($rows, 0, 20);
    }
}
