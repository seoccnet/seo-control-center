<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\AiVisibility;

interface AiCrawlerScheduler
{
    public function check(int $projectId, int $workspaceId, string $publicId): void;
}
