<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\DataForSEO;

/**
 * DataForSEO accounts, one per workspace. The password is encrypted at rest
 * by the implementation and only returned through credentials().
 */
interface DataForSeoProfileRepository
{
    public function find(int $workspaceId): ?DataForSeoProfile;

    public function credentials(int $workspaceId): ?DataForSeoCredentials;

    public function save(int $workspaceId, DataForSeoCredentials $credentials, ?float $balance, int $userId): void;

    public function recordCheck(int $workspaceId, ?float $balance): void;

    public function setError(int $workspaceId, string $error): void;

    public function delete(int $workspaceId): void;
}
