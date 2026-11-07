<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\Mcp\Server;

use SeoControlCenter\Core\Authorization\Authorizer;
use SeoControlCenter\Core\Support\ClientInfo;
use SeoControlCenter\Domain\Projects\Project;
use SeoControlCenter\Domain\Projects\ProjectRepository;
use SeoControlCenter\Modules\Mcp\McpAccess;

/**
 * Tenant boundary for tools: a project is available only when it is in the
 * token's workspace, allowed by the token and visible to the token's user.
 * Anything else answers "not found", like the web UI.
 */
final readonly class ToolContext
{
    public function __construct(
        public McpAccess $access,
        private ProjectRepository $projects,
        private Authorizer $authorizer,
        public ClientInfo $client = new ClientInfo(null, 'mcp', null),
    ) {}

    /** The token's name, recorded as `via` on what a write tool changes (ADR-036). */
    public function via(): string
    {
        return mb_substr($this->access->token->name, 0, 120);
    }

    /**
     * @param array<string, mixed> $arguments
     * @throws ToolError
     */
    public function project(array $arguments): Project
    {
        $publicId = $arguments['project_id'] ?? null;
        if (!is_string($publicId) || $publicId === '') {
            throw new ToolError('project_id is required. Call projects_list to find it.');
        }
        $project = $this->projects->findInWorkspace($this->access->token->workspaceId, $publicId);
        if ($project === null || !$this->allowed($project)) {
            throw new ToolError('Project not found.');
        }

        return $project;
    }

    /**
     * @return list<Project>
     */
    public function projects(): array
    {
        return array_values(array_filter(
            $this->projects->allInWorkspace($this->access->token->workspaceId),
            $this->allowed(...),
        ));
    }

    /**
     * @param array<string, mixed> $arguments
     */
    public static function int(array $arguments, string $name, int $default, int $min, int $max): int
    {
        $value = $arguments[$name] ?? $default;
        if (!is_int($value)) {
            throw new ToolError(sprintf('%s must be an integer.', $name));
        }

        return max($min, min($max, $value));
    }

    /**
     * @param array<string, mixed> $arguments
     * @param list<string> $allowed
     */
    public static function choice(array $arguments, string $name, string $default, array $allowed): string
    {
        $value = $arguments[$name] ?? $default;
        if (!is_string($value) || !in_array($value, $allowed, true)) {
            throw new ToolError(sprintf('%s must be one of: %s.', $name, implode(', ', $allowed)));
        }

        return $value;
    }

    private function allowed(Project $project): bool
    {
        return $this->access->token->coversProject($project->id)
            && $this->authorizer->canSeeProject($this->access->user->subject(), $project->workspaceId, $project->id);
    }
}
