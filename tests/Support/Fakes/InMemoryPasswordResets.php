<?php

declare(strict_types=1);

namespace SeoControlCenter\Tests\Support\Fakes;

use SeoControlCenter\Domain\Accounts\PasswordResetRepository;

final class InMemoryPasswordResets implements PasswordResetRepository
{
    /** @var list<array{user_id: int, hash: string, expires_at: string, used_at: ?string}> */
    public array $rows = [];

    public function create(int $userId, string $tokenHash, string $expiresAt, ?string $ip): void
    {
        $this->rows[] = ['user_id' => $userId, 'hash' => $tokenHash, 'expires_at' => $expiresAt, 'used_at' => null];
    }

    public function findValid(string $tokenHash, string $now): ?int
    {
        foreach ($this->rows as $row) {
            if (hash_equals($row['hash'], $tokenHash) && $row['used_at'] === null && $row['expires_at'] > $now) {
                return $row['user_id'];
            }
        }

        return null;
    }

    public function invalidateForUser(int $userId, string $now): void
    {
        foreach ($this->rows as $i => $row) {
            if ($row['user_id'] === $userId && $row['used_at'] === null) {
                $this->rows[$i]['used_at'] = $now;
            }
        }
    }
}
