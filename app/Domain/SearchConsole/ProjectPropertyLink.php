<?php

declare(strict_types=1);

namespace SeoControlCenter\Domain\SearchConsole;

final readonly class ProjectPropertyLink
{
    public function __construct(
        public int $projectId,
        public int $connectionId,
        public string $siteUrl,
        public string $linkedAt,
    ) {}
}
