<?php

declare(strict_types=1);

namespace SeoControlCenter\Domain\Workspaces;

/**
 * A workspace as seen by one user (for selectors and navigation).
 */
final readonly class WorkspaceMembershipView
{
    public function __construct(
        public Workspace $workspace,
        public string $roleKey,
        public string $roleLabel,
    ) {}
}
