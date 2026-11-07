<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\Alerts;

use SeoControlCenter\Core\Audit\ActorType;
use SeoControlCenter\Core\Audit\AuditEvent;
use SeoControlCenter\Core\Audit\AuditLogger;
use SeoControlCenter\Core\Authorization\Authorizer;
use SeoControlCenter\Core\Authorization\Permission;
use SeoControlCenter\Core\Http\Client\HostResolver;
use SeoControlCenter\Core\Http\Client\HttpClient;
use SeoControlCenter\Core\Http\Client\SystemHostResolver;
use SeoControlCenter\Core\Http\Exception\HttpException;
use SeoControlCenter\Core\Support\ClientInfo;
use SeoControlCenter\Core\Support\Clock;
use SeoControlCenter\Core\Support\Uuid;
use SeoControlCenter\Domain\Accounts\User;
use SeoControlCenter\Domain\Accounts\UserRepository;
use SeoControlCenter\Domain\Projects\Project;
use SeoControlCenter\Domain\Projects\ProjectRepository;
use SeoControlCenter\Domain\Shared\ValidationException;
use SeoControlCenter\Domain\Workspaces\Workspace;

final readonly class WebhookService
{
    public function __construct(private WebhookRepository $repository, private AlertRepository $alerts, private NotificationScheduler $scheduler, private ProjectRepository $projects, private UserRepository $users, private Authorizer $auth, private AuditLogger $audit, private HttpClient $http, private HostResolver $resolver, private Clock $clock, private string $appUrl) {}
    /** @param list<Project> $projects
     * @return array{projectWebhooks: array<int, AlertWebhook|null>, webhookHistory: array<int, list<array{status: string, http_status: ?int, message: ?string, created_at: string}>>, webhookProjects: list<int>}
     */
    public function page(User $user, array $projects): array
    {
        $configs = $history = $ids = [];
        foreach ($projects as $project) {
            if (!$this->auth->can($user->subject(), Permission::IntegrationsManage, $project->workspaceId, $project->id)) {
                continue;
            }
            $ids[] = $project->id;
            $configs[$project->id] = $this->repository->find($project->id);
            $history[$project->id] = $this->repository->history($project->id);
        }
        return ['projectWebhooks' => $configs, 'webhookHistory' => $history, 'webhookProjects' => $ids];
    }
    /** @param array<string, mixed> $input */
    public function save(User $user, Workspace $workspace, string $publicId, #[\SensitiveParameter] array $input, ClientInfo $client): void
    {
        $project = $this->projects->findInWorkspace($workspace->id, $publicId);
        if ($project === null || !$this->auth->canSeeProject($user->subject(), $workspace->id, $project->id)) {
            throw new HttpException(404, 'Project not found.');
        }
        $this->auth->authorize($user->subject(), Permission::IntegrationsManage, $workspace->id, $project->id);
        if (($input['remove_webhook'] ?? null) === '1') {
            $this->repository->remove($project->id);
        } else {
            $current = $this->repository->find($project->id);
            $url = is_string($input['webhook_url'] ?? null) ? trim($input['webhook_url']) : '';
            $url = $url !== '' ? $url : ($current?->destination() ?? '');
            $format = is_string($input['webhook_format'] ?? null) ? $input['webhook_format'] : '';
            $parts = parse_url($url);
            $host = is_array($parts) ? ($parts['host'] ?? '') : '';
            if (strlen($url) > 2048 || preg_match('/[\x00-\x20\\\\]/', $url) === 1 || !is_array($parts) || ($parts['scheme'] ?? null) !== 'https' || !str_contains($host, '.') || filter_var($host, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) === false || filter_var($host, FILTER_VALIDATE_IP) !== false || isset($parts['user']) || isset($parts['pass']) || isset($parts['port']) || isset($parts['fragment']) || !in_array($format, ['json', 'slack'], true)) {
                throw new ValidationException(['webhook' => 'Use a valid HTTPS webhook on a public hostname, without credentials, ports or fragments. Choose its payload format.']);
            }
            if ($format === 'slack' && (!in_array($host, ['hooks.slack.com', 'hooks.slack-gov.com'], true) || !str_starts_with($parts['path'] ?? '', '/services/') || isset($parts['query']))) {
                throw new ValidationException(['webhook' => 'Use the incoming webhook URL supplied by Slack.']);
            }
            $token = is_string($input['webhook_token'] ?? null) ? trim($input['webhook_token']) : '';
            if (strlen($token) > 4096 || preg_match('/[^\x21-\x7e]/', $token) === 1) {
                throw new ValidationException(['webhook' => 'Use a bearer token without spaces or control characters.']);
            }
            $token = ($input['clear_webhook_token'] ?? null) === '1' ? null : ($token !== '' ? $token : $current?->bearerToken());
            $this->repository->save(new AlertWebhook(Uuid::v7($this->clock), $project->id, $user->id, ($input['webhook_enabled'] ?? null) === '1', $format, $url, $format === 'slack' ? null : $token));
        }
        $this->audit->record(new AuditEvent('alerts.webhook_updated', ActorType::User, actorUserId: $user->id, workspaceId: $workspace->id, targetType: 'project', targetId: $project->publicId), $client);
    }
    public function queue(Alert $alert): void
    {
        $hook = $alert->projectId === null ? null : $this->repository->find($alert->projectId);
        if ($hook !== null && $hook->enabled) {
            $this->scheduler->sendWebhook($alert->id, $alert->workspaceId, $hook->publicId);
        }
    }
    public function deliver(int $alertId, string $version): void
    {
        $alert = $this->alerts->find($alertId);
        $hook = $alert?->projectId === null ? null : $this->repository->find($alert->projectId);
        if ($alert === null || $hook === null || !$hook->enabled || $hook->publicId !== $version) {
            return;
        }
        if (!$this->repository->claim($alertId, $hook->projectId, $version)) {
            return;
        }
        $user = $this->users->findById($hook->configuredBy);
        $this->auth->forget();
        $active = array_filter($this->projects->allInWorkspace($alert->workspaceId), static fn(Project $p): bool => $p->id === $hook->projectId);
        if ($user === null || !$user->active || $active === [] || !$this->auth->can($user->subject(), Permission::IntegrationsManage, $alert->workspaceId, $hook->projectId)) {
            $this->repository->finish($alertId, 'skipped', null, 'The configuring user no longer has permission.');
            return;
        }
        try {
            if (!SystemHostResolver::allPublic($this->resolver->addresses($hook->host()))) {
                $this->repository->finish($alertId, 'failed', null, 'Destination did not resolve exclusively to public addresses.');
                return;
            }
            $url = rtrim($this->appUrl, '/') . $alert->url;
            $plain = mb_substr(implode("\n", [$alert->title, ...$alert->lines, $url]), 0, 2900);
            $body = $hook->format === 'slack' ? ['text' => $plain, 'mrkdwn' => false, 'parse' => 'none', 'unfurl_links' => false, 'unfurl_media' => false, 'blocks' => [['type' => 'section', 'text' => ['type' => 'plain_text', 'text' => $plain]]]] : ['event' => 'scc.alert', 'id' => $alert->publicId, 'type' => $alert->type->value, 'title' => $alert->title, 'lines' => $alert->lines, 'url' => $url, 'created_at' => $alert->createdAt];
            $headers = ['Content-Type' => 'application/json', 'X-SCC-Event-ID' => $alert->publicId];
            if ($hook->bearerToken() !== null) {
                $headers['Authorization'] = 'Bearer ' . $hook->bearerToken();
            }
            $response = $this->http->send('POST', $hook->destination(), $headers, json_encode($body, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE), [$hook->host()], 4096);
            $success = $response->status >= 200 && $response->status < 300 && ($hook->format !== 'slack' || trim($response->body) === 'ok');
            $this->repository->finish($alertId, $success ? 'delivered' : 'failed', $response->status, $success ? 'Destination accepted the notification.' : 'Destination refused the notification; verify its configuration.');
        } catch (\Throwable) {
            // Do not log URLs, tokens, transport exception messages or response bodies.
            $this->repository->finish($alertId, 'failed', null, 'Delivery could not be confirmed. Not retried automatically to avoid duplicates.');
        }
    }
}
