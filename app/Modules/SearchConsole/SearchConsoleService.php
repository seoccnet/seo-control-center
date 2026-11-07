<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\SearchConsole;

use SensitiveParameter;
use SeoControlCenter\Core\Audit\ActorType;
use SeoControlCenter\Core\Audit\AuditEvent;
use SeoControlCenter\Core\Audit\AuditLogger;
use SeoControlCenter\Core\Authorization\Authorizer;
use SeoControlCenter\Core\Authorization\Permission;
use SeoControlCenter\Core\Http\Exception\HttpException;
use SeoControlCenter\Core\Http\Exception\NotFoundHttpException;
use SeoControlCenter\Core\RateLimit\RateLimiter;
use SeoControlCenter\Core\Session\Session;
use SeoControlCenter\Core\Support\ClientInfo;
use SeoControlCenter\Core\Support\Clock;
use SeoControlCenter\Domain\Accounts\User;
use SeoControlCenter\Domain\Integrations\Connection;
use SeoControlCenter\Domain\Integrations\ConnectionRepository;
use SeoControlCenter\Domain\Integrations\IntegrationException;
use SeoControlCenter\Domain\Integrations\OAuthClientCredentials;
use SeoControlCenter\Domain\Integrations\OAuthClientRepository;
use SeoControlCenter\Domain\Projects\Project;
use SeoControlCenter\Domain\SearchConsole\ImportScheduler;
use SeoControlCenter\Domain\SearchConsole\ProjectPropertyRepository;
use SeoControlCenter\Domain\SearchConsole\SearchConsoleProperty;
use SeoControlCenter\Domain\SearchConsole\SearchPerformanceProvider;
use SeoControlCenter\Domain\Shared\ValidationException;
use SeoControlCenter\Domain\Workspaces\Workspace;
use SeoControlCenter\Modules\SearchConsole\Google\GoogleAccessTokens;
use SeoControlCenter\Modules\SearchConsole\Google\GoogleOAuth;

/**
 * Application service for the Google Search Console connection:
 * OAuth client settings, connect/disconnect, property discovery and
 * project linking. Authorization, tenant scoping and audit live here.
 */
final readonly class SearchConsoleService
{
    public const string PROVIDER = 'google';
    private const string PENDING_KEY = 'google_oauth';
    private const int PENDING_TTL = 600;
    private const string READONLY_SCOPE = 'https://www.googleapis.com/auth/webmasters.readonly';

    public function __construct(
        private OAuthClientRepository $clients,
        private ConnectionRepository $connections,
        private ProjectPropertyRepository $links,
        private GoogleOAuth $oauth,
        private GoogleAccessTokens $tokens,
        private SearchPerformanceProvider $provider,
        private Authorizer $authorizer,
        private RateLimiter $rateLimiter,
        private AuditLogger $audit,
        private Clock $clock,
        private string $appUrl,
        private ImportScheduler $imports,
    ) {}

    // ---- Installation settings (system administrators) ----------------------

    public function clientId(): ?string
    {
        return $this->clients->get(self::PROVIDER)?->clientId;
    }

    public function redirectUri(): string
    {
        return rtrim($this->appUrl, '/') . '/integrations/google/callback';
    }

    public function saveClient(User $user, string $clientId, #[SensitiveParameter] string $clientSecret, ClientInfo $client): void
    {
        $this->authorizer->authorize($user->subject(), Permission::SystemSettingsManage);
        $clientId = trim($clientId);
        $clientSecret = trim($clientSecret);
        $errors = [];
        if (preg_match('/^[0-9]+-[a-z0-9]+\.apps\.googleusercontent\.com$/D', $clientId) !== 1) {
            $errors['client_id'] = 'Enter the OAuth client ID from Google Cloud (ends with .apps.googleusercontent.com).';
        }
        if ($clientSecret === '' && $this->clients->get(self::PROVIDER) === null) {
            $errors['client_secret'] = 'Enter the client secret.';
        }
        if ($errors !== []) {
            throw new ValidationException($errors);
        }
        // An empty secret field keeps the stored secret.
        $secret = $clientSecret !== '' ? $clientSecret : ($this->clients->get(self::PROVIDER)->clientSecret ?? '');
        $this->clients->save(self::PROVIDER, new OAuthClientCredentials($clientId, $secret), $user->id);
        $this->audit->record(new AuditEvent('integration.google.client_updated', ActorType::User, actorUserId: $user->id, actorLabel: $user->email, metadata: [
            'client_id' => $clientId,
            'secret_changed' => $clientSecret !== '',
        ]), $client);
    }

    // ---- Workspace connections ---------------------------------------------

    /**
     * @return list<array{connection: Connection, properties: list<SearchConsoleProperty>}>
     */
    public function overview(User $user, Workspace $workspace): array
    {
        $this->authorizer->authorize($user->subject(), Permission::WorkspaceView, $workspace->id);
        $result = [];
        foreach ($this->connections->forWorkspace($workspace->id) as $connection) {
            if ($connection->provider !== self::PROVIDER) {
                continue;
            }
            $result[] = [
                'connection' => $connection,
                'properties' => array_map(
                    static fn(array $p): SearchConsoleProperty => new SearchConsoleProperty($p['site_url'], $p['permission_level']),
                    $this->connections->properties($connection->id),
                ),
            ];
        }

        return $result;
    }

    public function canManage(User $user, Workspace $workspace): bool
    {
        return $this->authorizer->can($user->subject(), Permission::IntegrationsManage, $workspace->id);
    }

    /**
     * Starts the OAuth flow; returns the Google URL to redirect to.
     */
    public function startConnect(User $user, Workspace $workspace, Session $session): string
    {
        $this->authorizer->authorize($user->subject(), Permission::IntegrationsManage, $workspace->id);
        $client = $this->clients->get(self::PROVIDER) ?? throw new HttpException(409, 'Google is not configured yet. A system administrator must add the OAuth client under System → Integrations.');

        $state = bin2hex(random_bytes(32));
        $verifier = GoogleOAuth::newCodeVerifier();
        $session->set(self::PENDING_KEY, [
            'state' => $state,
            'verifier' => $verifier,
            'workspace_id' => $workspace->id,
            'expires' => $this->clock->now()->getTimestamp() + self::PENDING_TTL,
        ]);

        return $this->oauth->authorizationUrl($client, $this->redirectUri(), $state, $verifier);
    }

    /**
     * Completes the OAuth flow from Google's callback.
     *
     * @param array<string, mixed> $query callback query parameters
     */
    public function completeConnect(User $user, Workspace $workspace, Session $session, array $query, ClientInfo $client): Connection
    {
        $limit = $this->rateLimiter->hit('oauth:google:user:' . $user->id, 20, 600);
        if (!$limit->allowed) {
            throw new HttpException(429, 'Too many connection attempts. Try again in a few minutes.');
        }

        $pending = $session->get(self::PENDING_KEY);
        $session->remove(self::PENDING_KEY); // single use
        $state = is_string($query['state'] ?? null) ? $query['state'] : '';
        if (!is_array($pending) || !is_string($pending['state'] ?? null) || !hash_equals($pending['state'], $state)
            || ($pending['expires'] ?? 0) < $this->clock->now()->getTimestamp()) {
            throw new HttpException(400, 'The Google sign-in link expired or was not started here. Please try connecting again.');
        }
        if (($pending['workspace_id'] ?? null) !== $workspace->id) {
            throw new HttpException(400, 'You switched workspaces during the Google sign-in. Please try again.');
        }
        $this->authorizer->authorize($user->subject(), Permission::IntegrationsManage, $workspace->id);

        if (is_string($query['error'] ?? null)) {
            throw new HttpException(400, $query['error'] === 'access_denied'
                ? 'Access was not granted in Google.'
                : 'Google returned an error: ' . mb_substr($query['error'], 0, 100));
        }
        $code = is_string($query['code'] ?? null) ? $query['code'] : '';
        $credentials = $this->clients->get(self::PROVIDER) ?? throw new HttpException(409, 'Google is not configured.');

        $tokens = $this->oauth->exchangeCode($credentials, $code, $this->redirectUri(), (string) $pending['verifier']);
        if (!in_array(self::READONLY_SCOPE, explode(' ', $tokens->scope), true)) {
            throw new HttpException(400, 'Search Console access was not granted. Please connect again and allow access to Search Console data.');
        }
        $email = $this->oauth->accountEmail($tokens->accessToken);

        $connection = $this->connections->upsert($workspace->id, self::PROVIDER, $email, $tokens->scope, $user->id);
        if ($tokens->refreshToken === null && ($this->connections->tokens($connection->id)['refresh'] ?? null) === null) {
            $this->connections->setStatus($connection->id, 'error', 'Google did not grant offline access.');
            throw new HttpException(400, 'Google did not grant offline access. Remove "SEO Control Center" under Google Account → Security → Third-party access, then connect again.');
        }
        $this->tokens->store($connection->id, $tokens);

        $this->audit->record(new AuditEvent('integration.google.connected', ActorType::User, actorUserId: $user->id, actorLabel: $user->email, workspaceId: $workspace->id, targetType: 'connection', targetId: $connection->publicId, metadata: [
            'account' => $email,
        ]), $client);

        $this->discover($connection);

        return $connection;
    }

    public function refreshProperties(User $user, Workspace $workspace, string $connectionPublicId): int
    {
        $connection = $this->manageableConnection($user, $workspace, $connectionPublicId);

        return count($this->discover($connection));
    }

    public function disconnect(User $user, Workspace $workspace, string $connectionPublicId, ClientInfo $client): void
    {
        $connection = $this->manageableConnection($user, $workspace, $connectionPublicId);
        $this->tokens->revokeAndForget($connection);
        $unlinked = $this->links->unlinkConnection($connection->id);
        $this->connections->replaceProperties($connection->id, []);
        $this->connections->setStatus($connection->id, 'revoked', null);

        $this->audit->record(new AuditEvent('integration.google.disconnected', ActorType::User, actorUserId: $user->id, actorLabel: $user->email, workspaceId: $workspace->id, targetType: 'connection', targetId: $connection->publicId, metadata: [
            'account' => $connection->accountEmail,
            'projects_unlinked' => $unlinked,
        ]), $client);
    }

    // ---- Project linking ---------------------------------------------------

    /**
     * @return array{connection: Connection, property: SearchConsoleProperty, linked_at: string}|null
     */
    public function projectLink(Project $project): ?array
    {
        $link = $this->links->linkFor($project->id);
        if ($link === null) {
            return null;
        }
        $connection = $this->connections->findById($link->connectionId);
        if ($connection === null || $connection->workspaceId !== $project->workspaceId) {
            return null;
        }
        $level = 'unknown';
        foreach ($this->connections->properties($connection->id) as $p) {
            if ($p['site_url'] === $link->siteUrl) {
                $level = $p['permission_level'];
            }
        }

        return ['connection' => $connection, 'property' => new SearchConsoleProperty($link->siteUrl, $level), 'linked_at' => $link->linkedAt];
    }

    public function linkProject(User $user, Project $project, string $connectionPublicId, string $siteUrl, ClientInfo $client): void
    {
        $this->authorizer->authorize($user->subject(), Permission::IntegrationsManage, $project->workspaceId, $project->id);
        $connection = $this->connections->findInWorkspace($project->workspaceId, $connectionPublicId);
        if ($connection === null || !$connection->isActive()) {
            throw new ValidationException(['property' => 'Choose a property from an active Google connection in this workspace.']);
        }
        $property = null;
        foreach ($this->connections->properties($connection->id) as $p) {
            if ($p['site_url'] === $siteUrl) {
                $property = new SearchConsoleProperty($p['site_url'], $p['permission_level']);
            }
        }
        if ($property === null || !$property->canReadPerformance()) {
            throw new ValidationException(['property' => 'This Google account cannot read performance data for that property.']);
        }

        $this->links->link($project->id, $connection->id, $property->siteUrl, $user->id);
        // Start the first import right away; the queue collapses repeated requests.
        $this->imports->requestImport($project->id, $project->workspaceId);
        $this->audit->record(new AuditEvent('project.search_console_linked', ActorType::User, actorUserId: $user->id, actorLabel: $user->email, workspaceId: $project->workspaceId, targetType: 'project', targetId: $project->publicId, metadata: [
            'property' => $property->siteUrl,
            'account' => $connection->accountEmail,
        ]), $client);
    }

    public function unlinkProject(User $user, Project $project, ClientInfo $client): void
    {
        $this->authorizer->authorize($user->subject(), Permission::IntegrationsManage, $project->workspaceId, $project->id);
        $this->links->unlink($project->id);
        $this->audit->record(new AuditEvent('project.search_console_unlinked', ActorType::User, actorUserId: $user->id, actorLabel: $user->email, workspaceId: $project->workspaceId, targetType: 'project', targetId: $project->publicId), $client);
    }

    /**
     * Properties available for linking in the workspace, grouped by connection.
     *
     * @return list<array{connection: Connection, properties: list<SearchConsoleProperty>}>
     */
    public function linkableProperties(Workspace $workspace): array
    {
        $result = [];
        foreach ($this->connections->forWorkspace($workspace->id) as $connection) {
            if ($connection->provider !== self::PROVIDER || !$connection->isActive()) {
                continue;
            }
            $properties = [];
            foreach ($this->connections->properties($connection->id) as $p) {
                $property = new SearchConsoleProperty($p['site_url'], $p['permission_level']);
                if ($property->canReadPerformance()) {
                    $properties[] = $property;
                }
            }
            $result[] = ['connection' => $connection, 'properties' => $properties];
        }

        return $result;
    }

    // ---- internals ---------------------------------------------------------

    /**
     * @return list<SearchConsoleProperty>
     */
    private function discover(Connection $connection): array
    {
        $properties = $this->provider->listProperties($connection);
        $this->connections->replaceProperties($connection->id, array_map(
            static fn(SearchConsoleProperty $p): array => ['site_url' => $p->siteUrl, 'permission_level' => $p->permissionLevel],
            $properties,
        ));

        return $properties;
    }

    private function manageableConnection(User $user, Workspace $workspace, string $publicId): Connection
    {
        $this->authorizer->authorize($user->subject(), Permission::IntegrationsManage, $workspace->id);
        $connection = $this->connections->findInWorkspace($workspace->id, $publicId);
        if ($connection === null || $connection->provider !== self::PROVIDER) {
            throw new NotFoundHttpException('Connection not found.');
        }

        return $connection;
    }

    /**
     * Public so controllers can show a friendly message for provider failures.
     */
    public static function describe(IntegrationException $e): string
    {
        return $e->authorizationLost
            ? 'Google access for this account is no longer valid. Please reconnect it.'
            : ($e->retryable ? 'Google is temporarily unavailable. Please try again in a few minutes.' : $e->getMessage());
    }
}
