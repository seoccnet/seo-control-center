<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\Indexing;

interface InspectionScheduler
{
    public function inspect(int $projectId, int $workspaceId, string $publicId, string $binding, string $url): void;
}
