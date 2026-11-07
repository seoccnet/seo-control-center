<?php

declare(strict_types=1);

namespace SeoControlCenter\Domain\Quests;

use SeoControlCenter\Core\Authorization\Permission;
use SeoControlCenter\Core\Features\Feature;

/**
 * Which learning quests a user sees. A quest that teaches a feature this
 * installation doesn't have, or work the user's role can't do, is left out
 * silently: never shown locked, "Not included" or as an upgrade (ADR-035).
 * Features come only from FeatureManager, never from plan names (ADR-021).
 */
final readonly class QuestAccess
{
    /**
     * @param \Closure(Feature): bool $feature FeatureManager::enabled
     * @param \Closure(Permission, PermissionScope): bool $can the user's permission in the workspace or on any visible project
     */
    public function __construct(
        private \Closure $feature,
        private \Closure $can,
        private bool $assistant,
    ) {}

    public function allows(Quest $quest): bool
    {
        foreach ($quest->features as $feature) {
            if (!($this->feature)($feature)) {
                return false;
            }
        }
        if ($quest->assistant && !$this->assistant) {
            return false;
        }

        return $quest->permission === null || ($this->can)($quest->permission, $quest->scope);
    }
}
