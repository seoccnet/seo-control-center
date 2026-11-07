<?php

declare(strict_types=1);

namespace SeoControlCenter\Domain\Workspaces;

final readonly class Workspace
{
    public function __construct(
        public int $id,
        public string $publicId,
        public string $name,
    ) {}
}
