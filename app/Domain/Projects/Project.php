<?php

declare(strict_types=1);

namespace SeoControlCenter\Domain\Projects;

final readonly class Project
{
    public function __construct(
        public int $id,
        public string $publicId,
        public int $workspaceId,
        public string $name,
        public string $primaryDomain,
        public string $timezone,
        public string $createdAt,
        public string $updatedAt,
    ) {}
}
