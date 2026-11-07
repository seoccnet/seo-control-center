<?php

declare(strict_types=1);

namespace SeoControlCenter\Domain\Accounts;

/**
 * A row of System → Users.
 */
final readonly class UserOverview
{
    /**
     * @param list<string> $workspaces names of the user's (not deleted) workspaces
     */
    public function __construct(
        public User $user,
        public ?string $lastLoginAt,
        public bool $mfaEnabled,
        public array $workspaces,
    ) {}
}
