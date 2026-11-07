<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\Assistant;

use SensitiveParameter;

interface AssistantSettingsRepository
{
    public function find(int $workspaceId): ?AssistantSettings;

    public function apiKey(int $workspaceId): ?string;

    public function save(int $workspaceId, string $provider, #[SensitiveParameter] string $apiKey, string $model, int $userId, ?string $providerWorkspace = null): void;

    public function setModel(int $workspaceId, string $model): void;

    public function setError(int $workspaceId, ?string $error): void;

    public function delete(int $workspaceId): void;
}
