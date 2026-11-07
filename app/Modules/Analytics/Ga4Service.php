<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\Analytics;

use SeoControlCenter\Core\Audit\ActorType;
use SeoControlCenter\Core\Audit\AuditEvent;
use SeoControlCenter\Core\Audit\AuditLogger;
use SeoControlCenter\Core\Authorization\Authorizer;
use SeoControlCenter\Core\Authorization\Permission;
use SeoControlCenter\Core\Features\Feature;
use SeoControlCenter\Core\Features\FeatureManager;
use SeoControlCenter\Core\Http\Exception\HttpException;
use SeoControlCenter\Core\RateLimit\RateLimiter;
use SeoControlCenter\Core\Support\ClientInfo;
use SeoControlCenter\Core\Support\Clock;
use SeoControlCenter\Domain\Accounts\User;
use SeoControlCenter\Domain\Analytics\Ga4Credentials;
use SeoControlCenter\Domain\Analytics\Ga4Provider;
use SeoControlCenter\Domain\Projects\Project;
use SeoControlCenter\Domain\Projects\ProjectRepository;
use SeoControlCenter\Domain\Shared\DateRange;

final readonly class Ga4Service
{
    public function __construct(
        private Ga4Repository $data,
        private Ga4Provider $provider,
        private Ga4Scheduler $scheduler,
        private ProjectRepository $projects,
        private Authorizer $auth,
        private FeatureManager $features,
        private RateLimiter $limiter,
        private AuditLogger $audit,
        private Clock $clock,
        /** @var ?\Closure(Project, Ga4Credentials, string, DateRange): void the business-event import (ADR-042) */
        private ?\Closure $afterImport = null,
    ) {}
    public function saveCredentials(User $user, int $workspaceId, #[\SensitiveParameter] string $json, ClientInfo $client): void
    {
        $this->auth->authorize($user->subject(), Permission::IntegrationsManage, $workspaceId);
        if (trim($json) === '') {
            $this->data->deleteCredentials($workspaceId);
        } else {
            try {
                $credentials = Ga4Credentials::parse($json);
            } catch (\InvalidArgumentException $e) {
                throw new HttpException(422, $e->getMessage());
            }
            $this->data->saveCredentials($workspaceId, $credentials);
        }
        $this->audit->record(new AuditEvent('ga4.credentials_saved', ActorType::User, actorUserId: $user->id, workspaceId: $workspaceId, metadata: ['configured' => trim($json) !== '']), $client);
    }
    public function link(User $user, Project $project, string $propertyId, ClientInfo $client): void
    {
        $this->auth->authorize($user->subject(), Permission::IntegrationsManage, $project->workspaceId, $project->id);
        $this->features->require(Feature::SearchConsole);
        if (preg_match('/^[1-9][0-9]{0,19}$/D', $propertyId) !== 1) {
            throw new HttpException(422, 'Use the numeric GA4 property ID, not the G- measurement ID.');
        }
        if (!$this->data->hasCredentials($project->workspaceId)) {
            throw new HttpException(409, 'Save a GA4 service-account credential under Integrations first.');
        }
        $this->data->link($project->id, $propertyId);
        $this->request($user, $project, $client);
        $this->audit->record(new AuditEvent('ga4.property_linked', ActorType::User, actorUserId: $user->id, workspaceId: $project->workspaceId, targetType: 'project', targetId: $project->publicId, metadata: ['property_id' => $propertyId]), $client);
    }
    public function request(User $user, Project $project, ClientInfo $client, int $days = 28): void
    {
        $this->auth->authorize($user->subject(), Permission::IntegrationsManage, $project->workspaceId, $project->id);
        $this->features->require(Feature::SearchConsole);
        if (!in_array($days, [28, 90, 180, 365], true)) {
            throw new HttpException(422, 'Choose 28, 90, 180 or 365 days to import.');
        }
        if ($this->data->propertyId($project->id) === null || !$this->data->hasCredentials($project->workspaceId)) {
            throw new HttpException(409, 'Link a GA4 property and save credentials first.');
        }
        if (!$this->limiter->hit('ga4:project:' . $project->id, 6, 600)->allowed) {
            throw new HttpException(429, 'GA4 has been requested several times. Wait ten minutes.');
        }
        $this->scheduler->import($project->id, $project->workspaceId, $project->publicId, $days);
        $this->audit->record(new AuditEvent('ga4.import_requested', ActorType::User, actorUserId: $user->id, workspaceId: $project->workspaceId, targetType: 'project', targetId: $project->publicId), $client);
    }
    public function run(int $projectId, int $workspaceId, string $publicId, int $days = 28): void
    {
        $project = $this->projects->findInWorkspace($workspaceId, $publicId);
        if ($project === null || $project->id !== $projectId || !$this->features->enabled(Feature::SearchConsole)) {
            return;
        }
        $credentials = $this->data->credentials($workspaceId);
        $property = $this->data->propertyId($projectId);
        if ($credentials === null || $property === null) {
            return;
        }
        if (!in_array($days, [28, 90, 180, 365], true)) {
            throw new \InvalidArgumentException('Invalid GA4 history window.');
        }
        $range = DateRange::endingOn($this->clock->now()->modify('-1 day')->format('Y-m-d'), $days);
        $report = $this->provider->report($credentials, $property, $project->primaryDomain, $range);
        if ($this->data->propertyId($projectId) === $property && $this->projects->findInWorkspace($workspaceId, $publicId) !== null) {
            $this->data->saveReport($projectId, $range, $report, $property);
            if ($this->afterImport !== null) {
                ($this->afterImport)($project, $credentials, $property, $range);
            }
        }
    }
    public function schedule(): void
    {
        if (!$this->features->enabled(Feature::SearchConsole)) {
            return;
        }
        foreach ($this->data->linkedProjects() as $project) {
            $this->scheduler->import($project['project_id'], $project['workspace_id'], $project['project_public_id']);
        }
    }
}
