<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\Accounts\Persistence;

use SeoControlCenter\Core\Database\Connection;
use SeoControlCenter\Core\Support\Clock;
use SeoControlCenter\Domain\Accounts\MfaRepository;

final readonly class PdoMfaRepository implements MfaRepository
{
    public function __construct(
        private Connection $db,
        private Clock $clock,
    ) {}

    public function totpFor(int $userId): ?array
    {
        $row = $this->db->fetchOne("SELECT secret, last_used_step FROM user_mfa_methods WHERE user_id = ? AND type = 'totp'", [$userId]);
        if ($row === null) {
            return null;
        }

        return [
            'secret' => (string) $row['secret'],
            'last_used_step' => $row['last_used_step'] !== null ? (int) $row['last_used_step'] : null,
        ];
    }

    public function saveTotp(int $userId, string $encryptedSecret): void
    {
        $now = $this->now();
        $this->db->execute(
            "INSERT INTO user_mfa_methods (user_id, type, secret, last_used_step, confirmed_at, created_at)
             VALUES (?, 'totp', ?, NULL, ?, ?)
             ON DUPLICATE KEY UPDATE secret = VALUES(secret), last_used_step = NULL, confirmed_at = VALUES(confirmed_at)",
            [$userId, $encryptedSecret, $now, $now],
        );
    }

    public function markStepUsed(int $userId, int $step): bool
    {
        return $this->db->execute(
            "UPDATE user_mfa_methods SET last_used_step = ?
              WHERE user_id = ? AND type = 'totp' AND (last_used_step IS NULL OR last_used_step < ?)",
            [$step, $userId, $step],
        ) === 1;
    }

    public function deleteTotp(int $userId): void
    {
        $this->db->execute("DELETE FROM user_mfa_methods WHERE user_id = ? AND type = 'totp'", [$userId]);
    }

    public function replaceRecoveryCodes(int $userId, array $codeHashes): void
    {
        $this->db->transaction(function (Connection $db) use ($userId, $codeHashes): void {
            $db->execute('DELETE FROM user_recovery_codes WHERE user_id = ?', [$userId]);
            $now = $this->now();
            foreach ($codeHashes as $hash) {
                $db->execute('INSERT INTO user_recovery_codes (user_id, code_hash, created_at) VALUES (?, ?, ?)', [$userId, $hash, $now]);
            }
        });
    }

    public function consumeRecoveryCode(int $userId, string $codeHash): bool
    {
        return $this->db->execute(
            'UPDATE user_recovery_codes SET used_at = ? WHERE user_id = ? AND code_hash = ? AND used_at IS NULL',
            [$this->now(), $userId, $codeHash],
        ) === 1;
    }

    public function remainingRecoveryCodes(int $userId): int
    {
        return (int) $this->db->fetchValue('SELECT COUNT(*) FROM user_recovery_codes WHERE user_id = ? AND used_at IS NULL', [$userId]);
    }

    private function now(): string
    {
        return $this->clock->now()->format('Y-m-d H:i:s');
    }
}
