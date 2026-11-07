<?php

declare(strict_types=1);

namespace SeoControlCenter\Domain\Workspaces;

/**
 * The workspace a request operates in, with the user's role there.
 * Resolved by middleware from the session and re-validated on every request.
 */
final readonly class CurrentWorkspace
{
    /**
     * @param list<WorkspaceMembershipView> $all every workspace the user belongs to
     */
    public function __construct(
        public WorkspaceMembershipView $current,
        public array $all,
    ) {}

    public function id(): int
    {
        return $this->current->workspace->id;
    }

    public function workspace(): Workspace
    {
        return $this->current->workspace;
    }
}
