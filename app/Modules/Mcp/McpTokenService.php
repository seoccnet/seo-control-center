<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\Mcp;

use SeoControlCenter\Core\Audit\ActorType;
use SeoControlCenter\Core\Audit\AuditEvent;
use SeoControlCenter\Core\Audit\AuditLogger;
use SeoControlCenter\Core\Authorization\Authorizer;
use SeoControlCenter\Core\Authorization\Permission;
use SeoControlCenter\Core\Http\Exception\NotFoundHttpException;
use SeoControlCenter\Core\Support\ClientInfo;
use SeoControlCenter\Core\Support\Clock;
use SeoControlCenter\Domain\Accounts\User;
use SeoControlCenter\Domain\Accounts\UserRepository;
use SeoControlCenter\Domain\Mcp\McpScope;
use SeoControlCenter\Domain\Mcp\McpToken;
use SeoControlCenter\Domain\Mcp\McpTokenRepository;
use SeoControlCenter\Domain\Projects\ProjectRepository;
use SeoControlCenter\Domain\Shared\ValidationException;
use SeoControlCenter\Domain\Workspaces\Workspace;

/**
 * MCP tokens: create (shown once), list, revoke (tokens.manage) and
 * authenticate bearer tokens. A token acts for its creator and stops
 * working when the creator is deactivated or leaves the workspace.
 */
final readonly class McpTokenService
{
    public const string PREFIX = 'scc_';
    public const int RATE_LIMIT_PER_MINUTE = 60;
    /** Expiry choices in days; 0 = never. */
    public const array EXPIRY_DAYS = [30, 90, 365, 0];

    public function __construct(
        private McpTokenRepository $tokens,
        private ProjectRepository $projects,
        private UserRepository $users,
        private Authorizer $authorizer,
        private AuditLogger $audit,
        private Clock $clock,
    ) {}

    /**
     * @return list<McpToken>
     */
    public function list(User $user, Workspace $workspace): array
    {
        $this->authorizer->authorize($user->subject(), Permission::TokensManage, $workspace->id);

        return $this->tokens->forWorkspace($workspace->id);
    }

    public function canManage(User $user, Workspace $workspace): bool
    {
        return $this->authorizer->can($user->subject(), Permission::TokensManage, $workspace->id);
    }

    /**
     * @param array<string, mixed> $input name, scopes[], projects ("all" or project public IDs[]), expires (days)
     * @return array{token: McpToken, plaintext: string}
     * @throws ValidationException
     */
    public function create(User $user, Workspace $workspace, array $input, ClientInfo $client): array
    {
        $this->authorizer->authorize($user->subject(), Permission::TokensManage, $workspace->id);
        $errors = [];

        $name = trim(is_string($input['name'] ?? null) ? $input['name'] : '');
        if ($name === '' || mb_strlen($name) > 120) {
            $errors['name'] = 'Give the token a name, e.g. "Claude Desktop".';
        }

        $scopes = [];
        foreach (is_array($input['scopes'] ?? null) ? $input['scopes'] : [] as $value) {
            $scope = is_string($value) ? McpScope::tryFrom($value) : null;
            if ($scope !== null && !in_array($scope, $scopes, true)) {
                $scopes[] = $scope;
            }
        }
        if ($scopes === []) {
            $errors['scopes'] = 'Choose at least one kind of data.';
        }

        $projectIds = null;
        if (($input['project_access'] ?? 'all') !== 'all') {
            $projectIds = [];
            foreach (is_array($input['projects'] ?? null) ? $input['projects'] : [] as $publicId) {
                $project = is_string($publicId) ? $this->projects->findInWorkspace($workspace->id, $publicId) : null;
                if ($project !== null) {
                    $projectIds[] = $project->id;
                }
            }
            if ($projectIds === []) {
                $errors['projects'] = 'Choose at least one project, or allow all projects.';
            }
        }

        $days = is_string($input['expires'] ?? null) && ctype_digit($input['expires']) ? (int) $input['expires'] : -1;
        if (!in_array($days, self::EXPIRY_DAYS, true)) {
            $errors['expires'] = 'Choose when the token expires.';
        }
        if ($errors !== []) {
            throw new ValidationException($errors);
        }

        $plaintext = self::PREFIX . rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $token = $this->tokens->create(
            $workspace->id,
            $user->id,
            $name,
            hash('sha256', $plaintext, true),
            substr($plaintext, 0, 12),
            $scopes,
            $projectIds,
            self::RATE_LIMIT_PER_MINUTE,
            $days > 0 ? $this->clock->now()->modify(sprintf('+%d days', $days))->format('Y-m-d H:i:s') : null,
        );
        $this->audit->record(new AuditEvent('mcp.token_created', ActorType::User, actorUserId: $user->id, actorLabel: $user->email, workspaceId: $workspace->id, targetType: 'mcp_token', targetId: $token->publicId, metadata: [
            'name' => $name,
            'scopes' => array_map(static fn(McpScope $s): string => $s->value, $scopes),
            'projects' => $projectIds === null ? 'all' : count($projectIds),
            'expires_at' => $token->expiresAt,
        ]), $client);

        return ['token' => $token, 'plaintext' => $plaintext];
    }

    public function revoke(User $user, Workspace $workspace, string $publicId, ClientInfo $client): McpToken
    {
        $this->authorizer->authorize($user->subject(), Permission::TokensManage, $workspace->id);
        $token = $this->tokens->findInWorkspace($workspace->id, $publicId) ?? throw new NotFoundHttpException();
        $this->tokens->revoke($token->id);
        $this->audit->record(new AuditEvent('mcp.token_revoked', ActorType::User, actorUserId: $user->id, actorLabel: $user->email, workspaceId: $workspace->id, targetType: 'mcp_token', targetId: $token->publicId, metadata: [
            'name' => $token->name,
        ]), $client);

        return $token;
    }

    /**
     * Resolves a bearer token; null when unknown, revoked, expired or its creator lost access.
     */
    public function authenticate(#[\SensitiveParameter] string $bearer): ?McpAccess
    {
        if (!str_starts_with($bearer, self::PREFIX) || strlen($bearer) > 200) {
            return null;
        }
        $token = $this->tokens->findByHash(hash('sha256', $bearer, true));
        if ($token === null || !$token->isUsable($this->clock->now())) {
            return null;
        }
        $user = $this->users->findById($token->createdBy);
        if ($user === null || !$user->active || !$this->authorizer->can($user->subject(), Permission::WorkspaceView, $token->workspaceId)) {
            return null;
        }
        $this->tokens->touch($token->id);

        return new McpAccess($token, $user);
    }
}
