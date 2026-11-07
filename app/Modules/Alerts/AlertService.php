<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\Alerts;

use Psr\Log\LoggerInterface;
use SeoControlCenter\Core\Authorization\Authorizer;
use SeoControlCenter\Core\Authorization\Permission;
use SeoControlCenter\Core\Branding\CurrentBranding;
use SeoControlCenter\Core\Mail\Mailer;
use SeoControlCenter\Core\Mail\Message;
use SeoControlCenter\Core\Support\Clock;
use SeoControlCenter\Core\View\ViewRenderer;
use SeoControlCenter\Domain\Accounts\User;
use SeoControlCenter\Domain\Accounts\UserRepository;
use SeoControlCenter\Domain\Projects\Project;
use SeoControlCenter\Domain\Projects\ProjectRepository;
use SeoControlCenter\Domain\Workspaces\Workspace;
use SeoControlCenter\Domain\Workspaces\WorkspaceRepository;

/**
 * Instant alerts: the hourly scan records new events (once each) and queues
 * one e-mail per alert; the e-mail goes to the workspace members who can see
 * the project (owners and admins for budget alerts) and have not turned that
 * alert off. Also the notification settings of a user.
 */
final readonly class AlertService
{
    public const int RECENT_DAYS = 30;

    public function __construct(
        private AlertRepository $alerts,
        private AlertDetector $detector,
        private NotificationScheduler $scheduler,
        private WorkspaceRepository $workspaces,
        private UserRepository $users,
        private ProjectRepository $projects,
        private Authorizer $authorizer,
        private Mailer $mailer,
        private ViewRenderer $views,
        private CurrentBranding $branding,
        private Clock $clock,
        private LoggerInterface $logger,
        private string $appUrl,
        private \SeoControlCenter\Core\Audit\AuditLogger $audit,
        private WebhookService $webhooks,
    ) {}

    /**
     * Job (hourly): records new alerts and queues their e-mails.
     *
     * @return int new alerts
     */
    public function scan(): int
    {
        $new = 0;
        foreach ($this->alerts->projects() as $project) {
            try {
                $events = $this->detector->project($project['projectId'], $project['publicId'], $project['name']);
            } catch (\Throwable $e) {
                $this->logger->warning('Alert detection failed', ['project_id' => $project['projectId'], 'exception' => $e]);
                continue;
            }
            $new += $this->store($project['workspaceId'], $project['projectId'], $events);
        }
        foreach ($this->alerts->workspaces() as $workspaceId) {
            try {
                $new += $this->store($workspaceId, null, $this->detector->workspace($workspaceId));
            } catch (\Throwable $e) {
                $this->logger->warning('Alert detection failed', ['workspace_id' => $workspaceId, 'exception' => $e]);
            }
        }

        return $new;
    }

    /**
     * Job: e-mails one alert.
     *
     * @return int recipients
     */
    public function send(int $alertId): int
    {
        $alert = $this->alerts->find($alertId);
        if ($alert === null || $alert->sentAt !== null) {
            return 0;
        }
        $sent = 0;
        if ($this->mailer->isConfigured()) {
            $brand = $this->branding->get()->name();
            foreach ($this->recipients($alert) as $user) {
                try {
                    $this->mailer->send(new Message($user->email, '[' . $brand . '] ' . $alert->title, $this->text($alert, $brand), $user->name, $this->views->render('emails/alert', ['alert' => $alert, 'url' => $this->appUrl . $alert->url, 'settingsUrl' => $this->appUrl . '/account/notifications', 'productName' => $brand])));
                    ++$sent;
                } catch (\Throwable $e) {
                    $this->logger->warning('Alert e-mail failed', ['alert' => $alert->publicId, 'exception' => $e]);
                }
            }
        }
        // Marked even without mail: alerts are time-sensitive and stay visible in the app.
        $this->alerts->markSent($alert->id);

        return $sent;
    }

    /**
     * @return array{settings: NotificationSettings, types: list<AlertType>, projects: list<Project>, alerts: list<Alert>, projectNames: array<int, string>, mailConfigured: bool, projectThresholds: array<int, AlertThresholds>, editableProjects: list<int>}
     */
    public function page(User $user, Workspace $workspace): array
    {
        $projects = $this->visibleProjects($user, $workspace);
        $names = [];
        foreach ($projects as $project) {
            $names[$project->id] = $project->name;
        }
        $since = $this->clock->now()->modify('-' . self::RECENT_DAYS . ' days')->format('Y-m-d H:i:s');
        $alerts = array_values(array_filter(
            $this->alerts->recent($workspace->id, array_keys($names), $since, 50),
            fn(Alert $a): bool => $a->type !== AlertType::Budget || $this->authorizer->can($user->subject(), Permission::IntegrationsManage, $workspace->id),
        ));

        return [
            'settings' => $this->alerts->settings($user->id),
            'types' => AlertType::cases(),
            'projects' => $projects,
            'alerts' => $alerts,
            'projectNames' => $names,
            'mailConfigured' => $this->mailer->isConfigured(),
            'projectThresholds' => array_combine(array_keys($names), array_map(fn(Project $p): AlertThresholds => $this->alerts->thresholds($p->id), $projects)),
            'editableProjects' => array_values(array_map(static fn(Project $p): int => $p->id, array_filter($projects, fn(Project $p): bool => $this->authorizer->can($user->subject(), Permission::ProjectsEdit, $workspace->id, $p->id)))),
        ];
    }

    /** @param array<string, mixed> $input */
    public function saveThresholds(User $user, Workspace $workspace, string $publicId, array $input, \SeoControlCenter\Core\Support\ClientInfo $client): void
    {
        $project = $this->projects->findInWorkspace($workspace->id, $publicId);
        if ($project === null || !$this->authorizer->canSeeProject($user->subject(), $workspace->id, $project->id)) {
            throw new \SeoControlCenter\Core\Http\Exception\HttpException(404, 'Project not found.');
        }
        $this->authorizer->authorize($user->subject(), Permission::ProjectsEdit, $workspace->id, $project->id);
        $this->alerts->saveThresholds($project->id, AlertThresholds::fromInput($input));
        $this->audit->record(new \SeoControlCenter\Core\Audit\AuditEvent('alerts.thresholds_updated', \SeoControlCenter\Core\Audit\ActorType::User, actorUserId: $user->id, actorLabel: $user->email, workspaceId: $workspace->id, targetType: 'project', targetId: $project->publicId), $client);
    }

    /**
     * @param array<array-key, mixed> $input alerts, types[] (enabled alert types), digest, digest_ai, projects[] (public IDs of the projects that send alerts)
     */
    public function save(User $user, Workspace $workspace, array $input): void
    {
        $current = $this->alerts->settings($user->id);
        $enabled = array_filter(is_array($input['types'] ?? null) ? $input['types'] : [], is_string(...));
        $active = array_filter(is_array($input['projects'] ?? null) ? $input['projects'] : [], is_string(...));
        $here = [];
        $mutedHere = [];
        foreach ($this->visibleProjects($user, $workspace) as $project) {
            $here[] = $project->id;
            if (!in_array($project->publicId, $active, true)) {
                $mutedHere[] = $project->id;
            }
        }
        // Mutes of other workspaces stay as they are.
        $mutedProjects = array_values(array_unique([...array_diff($current->mutedProjects, $here), ...$mutedHere]));

        $this->alerts->saveSettings($user->id, new NotificationSettings(
            ($input['alerts'] ?? null) === '1',
            array_values(array_filter(AlertType::cases(), static fn(AlertType $t): bool => !in_array($t->value, $enabled, true))),
            $mutedProjects,
            ($input['digest'] ?? null) === '1',
            ($input['digest_ai'] ?? null) === '1',
        ));
    }

    /**
     * @return list<Project>
     */
    public function visibleProjects(User $user, Workspace $workspace): array
    {
        return array_values(array_filter(
            $this->projects->allInWorkspace($workspace->id),
            fn(Project $p): bool => $this->authorizer->canSeeProject($user->subject(), $workspace->id, $p->id),
        ));
    }

    /**
     * @param list<array{type: AlertType, fingerprint: string, title: string, lines: list<string>, url: string}> $events
     */
    private function store(int $workspaceId, ?int $projectId, array $events): int
    {
        $new = 0;
        foreach ($events as $event) {
            $alert = $this->alerts->record($workspaceId, $projectId, $event['type'], $event['fingerprint'], $event['title'], $event['lines'], $event['url']);
            if ($alert !== null) {
                $this->scheduler->sendAlert($alert->id, $workspaceId);
                try {
                    $this->webhooks->queue($alert);
                } catch (\Throwable) {
                    $this->logger->warning('Could not queue alert webhook', ['alert_id' => $alert->id]);
                }
                ++$new;
            }
        }

        return $new;
    }

    /**
     * @return list<User>
     */
    private function recipients(Alert $alert): array
    {
        $this->authorizer->forget();
        $users = [];
        foreach ($this->workspaces->members($alert->workspaceId) as $member) {
            $user = $member->userId > 0 && $member->status === 'active' ? $this->users->findById($member->userId) : null;
            if ($user === null || !$user->active || !$this->alerts->settings($user->id)->wants($alert->type, $alert->projectId)) {
                continue;
            }
            $allowed = $alert->projectId !== null
                ? $this->authorizer->canSeeProject($user->subject(), $alert->workspaceId, $alert->projectId)
                : $this->authorizer->can($user->subject(), Permission::IntegrationsManage, $alert->workspaceId);
            if ($allowed) {
                $users[$user->id] = $user;
            }
        }

        return array_values($users);
    }

    private function text(Alert $alert, string $brand): string
    {
        return implode("\n", [
            $alert->title,
            '',
            ...array_map(static fn(string $line): string => '- ' . $line, $alert->lines),
            '',
            'Open in ' . $brand . ': ' . $this->appUrl . $alert->url,
            '',
            '--',
            'You get this e-mail because alerts are on for your account.',
            'Choose what you receive: ' . $this->appUrl . '/account/notifications',
        ]);
    }
}
