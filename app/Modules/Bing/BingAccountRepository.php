<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\Bing;

use SensitiveParameter;

/**
 * Bing connections, one per workspace. The API key is encrypted at rest by
 * the implementation and only returned through apiKey().
 */
interface BingAccountRepository
{
    public function find(int $workspaceId): ?BingAccount;

    public function apiKey(int $workspaceId): ?string;

    /**
     * @param list<string> $sites
     */
    public function save(int $workspaceId, #[SensitiveParameter] string $apiKey, array $sites, int $userId): void;

    /**
     * @param list<string> $sites
     */
    public function updateSites(int $workspaceId, array $sites): void;

    public function setError(int $workspaceId, string $error): void;

    public function delete(int $workspaceId): void;
}
