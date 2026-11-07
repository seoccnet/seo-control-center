<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\Alerts;

/**
 * What a user receives. Without a stored row, alerts and the weekly digest
 * are on and the AI summary is off.
 */
final readonly class NotificationSettings
{
    /**
     * @param list<AlertType> $mutedTypes
     * @param list<int> $mutedProjects project ids
     */
    public function __construct(
        public bool $alerts = true,
        public array $mutedTypes = [],
        public array $mutedProjects = [],
        public bool $digest = true,
        public bool $digestAi = false,
    ) {}

    public function wants(AlertType $type, ?int $projectId): bool
    {
        return $this->alerts && !in_array($type, $this->mutedTypes, true) && ($projectId === null || !in_array($projectId, $this->mutedProjects, true));
    }
}
