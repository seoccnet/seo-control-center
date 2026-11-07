<?php

declare(strict_types=1);

namespace SeoControlCenter\Domain\Workspaces;

final readonly class Invitation
{
    public function __construct(
        public int $id,
        public string $publicId,
        public int $workspaceId,
        public string $workspaceName,
        public string $email,
        public string $roleKey,
        public ?string $invitedByName,
        public string $createdAt,
        public string $expiresAt,
    ) {}
}
