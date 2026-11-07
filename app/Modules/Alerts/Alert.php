<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\Alerts;

/**
 * A detected event: what happened (title and a few lines of detail) and where
 * to look in the app (url, a local path). Workspace alerts have no project.
 */
final readonly class Alert
{
    /**
     * @param list<string> $lines
     */
    public function __construct(
        public int $id,
        public string $publicId,
        public int $workspaceId,
        public ?int $projectId,
        public AlertType $type,
        public string $title,
        public array $lines,
        public string $url,
        public string $createdAt,
        public ?string $sentAt,
    ) {}
}
