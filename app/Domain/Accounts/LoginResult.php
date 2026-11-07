<?php

declare(strict_types=1);

namespace SeoControlCenter\Domain\Accounts;

final readonly class LoginResult
{
    public function __construct(
        public LoginOutcome $outcome,
        public ?User $user = null,
        public int $retryAfterSeconds = 0,
    ) {}
}
