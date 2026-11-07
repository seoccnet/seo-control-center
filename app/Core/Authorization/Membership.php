<?php

declare(strict_types=1);

namespace SeoControlCenter\Core\Authorization;

/**
 * A user's role in a workspace or project, with the permissions it grants.
 */
final readonly class Membership
{
    /**
     * @param list<string> $permissions permission names
     */
    public function __construct(
        public string $roleKey,
        public array $permissions,
    ) {}

    public function grants(Permission $permission): bool
    {
        return in_array($permission->value, $this->permissions, true);
    }

    /**
     * Owners and admins see every project in the workspace (ADR-013).
     */
    public function coversAllProjects(): bool
    {
        return $this->roleKey === 'owner' || $this->roleKey === 'admin';
    }
}
