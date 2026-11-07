<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\Assistant;

/**
 * A workspace's AI assistant setup (without the API key).
 */
final readonly class AssistantSettings
{
    public function __construct(
        public int $workspaceId,
        public string $provider,
        public string $model,
        public string $status,
        public ?string $lastError,
        public string $createdAt,
        public ?string $providerWorkspace = null,
    ) {}

    public function isActive(): bool
    {
        return $this->status === 'active';
    }
}
