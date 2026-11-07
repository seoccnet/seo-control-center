<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\WebVitals;

interface WebVitalsScheduler
{
    public function fetch(int $projectId, int $workspaceId, string $projectPublicId, string $url, string $formFactor = 'PHONE'): void;
}
