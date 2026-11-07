<?php

declare(strict_types=1);

namespace SeoControlCenter\Tests\Support\Fakes;

use SeoControlCenter\Domain\Accounts\MfaRepository;

final class InMemoryMfaRepository implements MfaRepository
{
    /** @var array<int, array{secret: string, last_used_step: ?int}> */
    public array $totp = [];

    /** @var array<int, array<string, bool>> userId => hash => used */
    public array $codes = [];

    public function totpFor(int $userId): ?array
    {
        return $this->totp[$userId] ?? null;
    }

    public function saveTotp(int $userId, string $encryptedSecret): void
    {
        $this->totp[$userId] = ['secret' => $encryptedSecret, 'last_used_step' => null];
    }

    public function markStepUsed(int $userId, int $step): bool
    {
        $current = $this->totp[$userId]['last_used_step'] ?? null;
        if (!isset($this->totp[$userId]) || ($current !== null && $current >= $step)) {
            return false;
        }
        $this->totp[$userId]['last_used_step'] = $step;

        return true;
    }

    public function deleteTotp(int $userId): void
    {
        unset($this->totp[$userId]);
    }

    public function replaceRecoveryCodes(int $userId, array $codeHashes): void
    {
        $this->codes[$userId] = array_fill_keys($codeHashes, false);
    }

    public function consumeRecoveryCode(int $userId, string $codeHash): bool
    {
        if (($this->codes[$userId][$codeHash] ?? true) === true) {
            return false;
        }
        $this->codes[$userId][$codeHash] = true;

        return true;
    }

    public function remainingRecoveryCodes(int $userId): int
    {
        return count(array_filter($this->codes[$userId] ?? [], static fn(bool $used): bool => !$used));
    }
}
