<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\Alerts\Persistence;

use SeoControlCenter\Core\Crypto\Encrypter;
use SeoControlCenter\Core\Database\Connection;
use SeoControlCenter\Core\Support\Clock;
use SeoControlCenter\Modules\Alerts\AlertWebhook;
use SeoControlCenter\Modules\Alerts\WebhookRepository;

final readonly class PdoWebhookRepository implements WebhookRepository
{
    public function __construct(private Connection $db, private Encrypter $encrypter, private Clock $clock) {}
    public static function context(string $publicId, string $column): string
    {
        return 'project_alert_webhooks.' . $column . ':' . $publicId;
    }
    public function find(int $projectId): ?AlertWebhook
    {
        $r = $this->db->fetchOne('SELECT * FROM project_alert_webhooks WHERE project_id = ?', [$projectId]);
        return $r === null ? null : new AlertWebhook((string) $r['public_id'], $projectId, (int) $r['configured_by'], (bool) $r['enabled'], (string) $r['format'], $this->encrypter->decrypt((string) $r['destination'], self::context((string) $r['public_id'], 'destination')), is_string($r['bearer_token']) ? $this->encrypter->decrypt($r['bearer_token'], self::context((string) $r['public_id'], 'bearer_token')) : null);
    }
    public function save(AlertWebhook $webhook): void
    {
        $this->db->execute('INSERT INTO project_alert_webhooks (project_id, public_id, configured_by, enabled, format, destination, bearer_token) VALUES (?, ?, ?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE public_id = VALUES(public_id), configured_by = VALUES(configured_by), enabled = VALUES(enabled), format = VALUES(format), destination = VALUES(destination), bearer_token = VALUES(bearer_token)', [$webhook->projectId, $webhook->publicId, $webhook->configuredBy, $webhook->enabled ? 1 : 0, $webhook->format, $this->encrypter->encrypt($webhook->destination(), self::context($webhook->publicId, 'destination')), $webhook->bearerToken() === null ? null : $this->encrypter->encrypt($webhook->bearerToken(), self::context($webhook->publicId, 'bearer_token'))]);
    }
    public function remove(int $projectId): void
    {
        $this->db->execute('DELETE FROM project_alert_webhooks WHERE project_id = ?', [$projectId]);
    }
    public function claim(int $alertId, int $projectId, string $version): bool
    {
        return $this->db->execute('INSERT IGNORE INTO alert_webhook_deliveries (alert_id, project_id, webhook_public_id, status, created_at) VALUES (?, ?, ?, \'sending\', ?)', [$alertId, $projectId, $version, $this->clock->now()->format('Y-m-d H:i:s')]) === 1;
    }
    public function finish(int $alertId, string $status, ?int $httpStatus, string $message): void
    {
        $this->db->execute('UPDATE alert_webhook_deliveries SET status = ?, http_status = ?, message = ? WHERE alert_id = ?', [$status, $httpStatus, $message, $alertId]);
    }
    public function history(int $projectId): array
    {
        return array_map(static fn(array $r): array => ['status' => (string) $r['status'], 'http_status' => $r['http_status'] === null ? null : (int) $r['http_status'], 'message' => is_string($r['message']) ? $r['message'] : null, 'created_at' => (string) $r['created_at']], $this->db->fetchAll('SELECT status, http_status, message, created_at FROM alert_webhook_deliveries WHERE project_id = ? ORDER BY created_at DESC, alert_id DESC LIMIT 20', [$projectId]));
    }
}
