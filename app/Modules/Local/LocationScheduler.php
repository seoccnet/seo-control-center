<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\Local;

interface LocationScheduler
{
    public function dispatch(string $mode, int $projectId, int $workspaceId, string $projectPublicId, string $location, int $delay = 0, ?string $gridId = null): void;
}
