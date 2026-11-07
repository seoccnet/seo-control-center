<?php

declare(strict_types=1);

namespace SeoControlCenter\Domain\Accounts;

final readonly class UserCredentials
{
    public function __construct(
        public User $user,
        public string $passwordHash,
    ) {}
}
