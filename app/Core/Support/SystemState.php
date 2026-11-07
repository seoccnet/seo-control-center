<?php

declare(strict_types=1);

namespace SeoControlCenter\Core\Support;

use SeoControlCenter\Core\Database\Connection;

/**
 * Installation-level key/value state (heartbeats, installation ID, …) in `system_state`.
 * Never store secrets here.
 */
final readonly class SystemState
{
    public function __construct(
        private Connection $db,
        private Clock $clock,
    ) {}

    public function get(string $name, mixed $default = null): mixed
    {
        $value = $this->db->fetchValue('SELECT value FROM system_state WHERE name = ?', [$name]);
        if (!is_string($value)) {
            return $default;
        }

        return json_decode($value, true, 32, JSON_THROW_ON_ERROR);
    }

    public function set(string $name, mixed $value): void
    {
        $this->db->execute(
            'INSERT INTO system_state (name, value, updated_at) VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE value = VALUES(value), updated_at = VALUES(updated_at)',
            [$name, json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES), $this->clock->now()->format('Y-m-d H:i:s')],
        );
    }

    public function installationId(): string
    {
        $id = $this->get('installation_id');
        if (!is_string($id) || $id === '') {
            $id = bin2hex(random_bytes(16));
            $this->set('installation_id', $id);
        }

        return $id;
    }
}
