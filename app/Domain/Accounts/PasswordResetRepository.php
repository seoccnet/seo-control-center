<?php

declare(strict_types=1);

namespace SeoControlCenter\Domain\Accounts;

/**
 * One-time password-reset tokens (ADR-027); only SHA-256 hashes are stored.
 */
interface PasswordResetRepository
{
    public function create(int $userId, string $tokenHash, string $expiresAt, ?string $ip): void;

    /**
     * The user ID of an unused, unexpired token, or null.
     */
    public function findValid(string $tokenHash, string $now): ?int;

    /**
     * Marks every open token of the user as used (after a reset or a new request).
     */
    public function invalidateForUser(int $userId, string $now): void;
}
