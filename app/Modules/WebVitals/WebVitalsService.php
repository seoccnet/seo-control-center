<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\WebVitals;

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
use SeoControlCenter\Domain\Projects\Project;
use SeoControlCenter\Domain\Projects\ProjectRepository;
use SeoControlCenter\Domain\Shared\DateRange;
use SeoControlCenter\Domain\WebVitals\WebVitalsProvider;
use SeoControlCenter\Modules\Pages\PageRepository;

final readonly class WebVitalsService
{
    public function __construct(
        private WebVitalsRepository $data,
        private WebVitalsProvider $provider,
        private WebVitalsScheduler $scheduler,
        private PageRepository $pages,
        private ProjectRepository $projects,
        private Authorizer $auth,
        private FeatureManager $features,
        private RateLimiter $limiter,
        private AuditLogger $audit,
        private Clock $clock
    ) {}

    public function saveKey(User $user, int $workspaceId, #[\SensitiveParameter] string $key, ClientInfo $client): void
    {
        $this->auth->authorize($user->subject(), Permission::IntegrationsManage, $workspaceId);
        $key = trim($key);
        if ($key !== '' && preg_match('/^[A-Za-z0-9_-]{20,256}$/D', $key) !== 1) {
            throw new HttpException(422, 'Enter a valid Google Cloud API key.');
        }
        if ($key === '') {
            $this->data->deleteKey($workspaceId);
        } else {
            $this->data->saveKey($workspaceId, $key);
        }
        $this->audit->record(new AuditEvent('crux.key_saved', ActorType::User, actorUserId: $user->id, workspaceId: $workspaceId, metadata: ['configured' => $key !== '']), $client);
    }

    public function request(User $user, Project $project, string $url, ClientInfo $client, string $formFactor = 'PHONE'): void
    {
        $this->auth->authorize($user->subject(), Permission::ProjectsEdit, $project->workspaceId, $project->id);
        $this->features->require(Feature::SiteAudit);
        self::device($formFactor);
        if (!$this->data->hasKey($project->workspaceId)) {
            throw new HttpException(409, 'Add a Chrome UX Report API key under Integrations first.');
        }
        if (!$this->knownPage($project, $url)) {
            throw new HttpException(404, 'Choose a known page in this project.');
        }
        if (!$this->limiter->hit('crux:project:' . $project->id, 20, 60)->allowed) {
            throw new HttpException(429, 'Wait a minute before requesting more Web Vitals.');
        }
        $this->scheduler->fetch($project->id, $project->workspaceId, $project->publicId, $url, $formFactor);
        $this->audit->record(new AuditEvent('crux.fetch_requested', ActorType::User, actorUserId: $user->id, workspaceId: $project->workspaceId, targetType: 'project', targetId: $project->publicId), $client);
    }

    public function run(int $projectId, int $workspaceId, string $publicId, string $url, string $formFactor = 'PHONE'): void
    {
        self::device($formFactor);
        $project = $this->projects->findInWorkspace($workspaceId, $publicId);
        if ($project === null || $project->id !== $projectId || !$this->features->enabled(Feature::SiteAudit)
            || !$this->knownPage($project, $url)) {
            return;
        }
        $key = $this->data->apiKey($workspaceId);
        if ($key === null) {
            return;
        }
        $this->data->save($projectId, $this->provider->fetch($url, $key, $formFactor));
        $this->data->attempted($projectId, $url, $formFactor, $this->clock->now()->format('Y-m-d H:i:s'));
    }

    public function watch(User $user, Project $project, string $url, string $formFactor, bool $enabled, ClientInfo $client): void
    {
        $this->auth->authorize($user->subject(), Permission::ProjectsEdit, $project->workspaceId, $project->id);
        $this->features->require(Feature::SiteAudit);
        self::device($formFactor);
        if (!$this->knownPage($project, $url)) {
            throw new HttpException(404, 'Choose a known page in this project.');
        }
        if ($enabled && !$this->data->watching($project->id, $url, $formFactor) && $this->data->watchCount($project->id) >= 50) {
            throw new HttpException(409, 'This project already has 50 page/device weekly watches.');
        }
        $this->data->watch($project->id, $url, $formFactor, $enabled);
        $this->audit->record(new AuditEvent('crux.watch_saved', ActorType::User, actorUserId: $user->id, workspaceId: $project->workspaceId, targetType: 'project', targetId: $project->publicId, metadata: ['url' => $url, 'form_factor' => $formFactor, 'weekly' => $enabled]), $client);
    }

    public function dispatchDue(): int
    {
        if (!$this->features->enabled(Feature::SiteAudit)) {
            return 0;
        }
        $count = 0;
        foreach ($this->data->due($this->clock->now()->modify('-7 days')->format('Y-m-d H:i:s')) as $row) {
            $this->scheduler->fetch($row['project_id'], $row['workspace_id'], $row['project_public_id'], $row['url'], $row['form_factor']);
            $this->data->attempted($row['project_id'], $row['url'], $row['form_factor'], $this->clock->now()->format('Y-m-d H:i:s'));
            ++$count;
        }
        return $count;
    }

    private function knownPage(Project $project, string $url): bool
    {
        return strlen($url) <= 2048 && in_array(parse_url($url, PHP_URL_SCHEME), ['http', 'https'], true)
            && in_array(\SeoControlCenter\Modules\SiteAudit\Crawler\UrlNormalizer::host($url), \SeoControlCenter\Modules\SiteAudit\SiteAuditService::hosts(strtolower($project->primaryDomain)), true)
            && $this->pages->find($project->id, $url, DateRange::endingOn($this->clock->now()->format('Y-m-d'), 28)) !== null;
    }

    private static function device(string $formFactor): void
    {
        if (!in_array($formFactor, ['PHONE', 'DESKTOP'], true)) {
            throw new HttpException(422, 'Choose mobile or desktop field data.');
        }
    }
}
