<?php

declare(strict_types=1);

namespace SeoControlCenter\Domain\Accounts;

interface MfaRepository
{
    /**
     * @return array{secret: string, last_used_step: ?int}|null encrypted secret envelope
     */
    public function totpFor(int $userId): ?array;

    public function saveTotp(int $userId, string $encryptedSecret): void;

    public function markStepUsed(int $userId, int $step): bool;

    public function deleteTotp(int $userId): void;

    /**
     * @param list<string> $codeHashes
     */
    public function replaceRecoveryCodes(int $userId, array $codeHashes): void;

    /**
     * Marks an unused recovery code as used. Returns false if it does not exist or was used.
     */
    public function consumeRecoveryCode(int $userId, string $codeHash): bool;

    public function remainingRecoveryCodes(int $userId): int;
}
