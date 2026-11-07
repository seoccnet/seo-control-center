<?php

declare(strict_types=1);

namespace SeoControlCenter\Core\Session;

use DateTimeImmutable;
use DateTimeZone;
use JsonException;
use SeoControlCenter\Core\Database\Connection;
use SeoControlCenter\Core\Support\ClientInfo;
use SeoControlCenter\Core\Support\Clock;

/**
 * Stores sessions in the `sessions` table. Only SHA-256 hashes of tokens are stored.
 */
final class PdoSessionStore implements SessionStore
{
    public function __construct(
        private readonly Connection $db,
        private readonly SessionConfig $config,
        private readonly Clock $clock,
    ) {}

    public function load(string $token): ?Session
    {
        if (preg_match('/^[0-9a-f]{64}$/D', $token) !== 1) {
            return null;
        }
        $id = hash('sha256', $token);

        $row = $this->db->fetchOne(
            'SELECT user_id, data, created_at, last_activity_at, expires_at FROM sessions WHERE id = ?',
            [$id],
        );
        if ($row === null) {
            return null;
        }

        $now = $this->clock->now();
        $idleLimit = $this->utc((string) $row['last_activity_at'])->modify(sprintf('+%d seconds', $this->config->idleTimeoutSeconds));
        if ($this->utc((string) $row['expires_at']) <= $now || $idleLimit <= $now) {
            $this->db->execute('DELETE FROM sessions WHERE id = ?', [$id]);

            return null;
        }

        try {
            $data = json_decode((string) $row['data'], true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            $data = [];
        }

        $session = new Session(
            $token,
            is_array($data) ? $data : [],
            $row['user_id'] !== null ? (int) $row['user_id'] : null,
            $this->utc((string) $row['created_at']),
        );
        $session->setLastActivity($this->utc((string) $row['last_activity_at']));

        return $session;
    }

    public function save(Session $session, ClientInfo $client): string
    {
        $now = $this->format($this->clock->now());
        $data = json_encode($session->all(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);

        $oldToken = $session->token();
        if ($oldToken === null || $session->needsRegeneration()) {
            $token = bin2hex(random_bytes(32));
            $createdAt = $session->createdAt() ?? $this->clock->now();
            $expires = $createdAt->modify(sprintf('+%d seconds', $this->config->absoluteLifetimeSeconds));

            $this->db->transaction(function (Connection $db) use ($oldToken, $token, $session, $data, $client, $createdAt, $expires, $now): void {
                if ($oldToken !== null) {
                    $db->execute('DELETE FROM sessions WHERE id = ?', [hash('sha256', $oldToken)]);
                }
                $db->execute(
                    'INSERT INTO sessions (id, user_id, data, ip_address, user_agent, created_at, last_activity_at, expires_at)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
                    [
                        hash('sha256', $token), $session->userId(), $data, $client->ip, $client->userAgent,
                        $this->format($createdAt), $now, $this->format($expires),
                    ],
                );
            });
            $session->markPersisted($token);

            return $token;
        }

        $this->db->execute(
            'UPDATE sessions SET user_id = ?, data = ?, last_activity_at = ? WHERE id = ?',
            [$session->userId(), $data, $now, hash('sha256', $oldToken)],
        );
        $session->markPersisted($oldToken);

        return $oldToken;
    }

    public function touch(Session $session): void
    {
        $token = $session->token();
        $last = $session->lastActivity();
        if ($token === null || $last === null) {
            return;
        }
        $now = $this->clock->now();
        if ($now->getTimestamp() - $last->getTimestamp() < $this->config->touchIntervalSeconds) {
            return;
        }
        $this->db->execute('UPDATE sessions SET last_activity_at = ? WHERE id = ?', [$this->format($now), hash('sha256', $token)]);
    }

    public function destroy(Session $session): void
    {
        $token = $session->token();
        if ($token !== null) {
            $this->db->execute('DELETE FROM sessions WHERE id = ?', [hash('sha256', $token)]);
        }
    }

    public function destroyForUser(int $userId, ?Session $except = null): int
    {
        $exceptToken = $except?->token();
        if ($exceptToken !== null) {
            return $this->db->execute('DELETE FROM sessions WHERE user_id = ? AND id <> ?', [$userId, hash('sha256', $exceptToken)]);
        }

        return $this->db->execute('DELETE FROM sessions WHERE user_id = ?', [$userId]);
    }

    private function utc(string $value): DateTimeImmutable
    {
        return new DateTimeImmutable($value, new DateTimeZone('UTC'));
    }

    private function format(DateTimeImmutable $time): string
    {
        return $time->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }
}
