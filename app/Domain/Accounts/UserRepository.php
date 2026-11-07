<?php

declare(strict_types=1);

namespace SeoControlCenter\Domain\Accounts;

interface UserRepository
{
    public function findById(int $id): ?User;

    public function findByEmail(Email $email): ?User;

    public function credentialsByEmail(Email $email): ?UserCredentials;

    public function create(Email $email, string $name, string $passwordHash, SystemRole $systemRole): User;

    public function updatePasswordHash(int $userId, string $passwordHash): void;

    /**
     * Changes the display name and e-mail address (the caller checks uniqueness).
     */
    public function updateProfile(int $userId, string $name, Email $email): void;

    public function recordLogin(int $userId): void;

    /**
     * Every user with workspaces, two-factor status and last sign-in (System → Users).
     *
     * @return list<UserOverview>
     */
    public function overview(): array;

    public function setActive(int $userId, bool $active): void;

    public function setSystemRole(int $userId, SystemRole $role): void;

    /**
     * Active users with the system role admin.
     */
    public function countActiveAdmins(): int;
}
