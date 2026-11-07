<?php

declare(strict_types=1);

namespace SeoControlCenter\Domain\Workspaces;

final readonly class MemberView
{
    public function __construct(
        public string $userPublicId,
        public string $name,
        public string $email,
        public string $roleLabel,
        public string $status,
        public string $joinedAt,
        public int $userId = 0,
        public string $roleKey = '',
    ) {}
}
