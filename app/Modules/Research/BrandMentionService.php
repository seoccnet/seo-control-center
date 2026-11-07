<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\Research;

use Psr\Log\LoggerInterface;
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
use SeoControlCenter\Domain\Accounts\UserRepository;
use SeoControlCenter\Domain\Integrations\BudgetExceededException;
use SeoControlCenter\Domain\Integrations\IntegrationException;
use SeoControlCenter\Domain\Keywords\KeywordLocale;
use SeoControlCenter\Domain\Projects\Project;
use SeoControlCenter\Domain\Projects\ProjectRepository;
use SeoControlCenter\Domain\Research\AiMentionsProvider;
use SeoControlCenter\Domain\Shared\ValidationException;

final readonly class BrandMentionService
{
    public const string NOTICE = 'Name matches and domain citations may overlap; do not add their counts. Word matching can include unrelated brands with the same name. These are sampled provider data, not a complete view of every AI conversation or web mention.';
    public function __construct(private BrandMentionRepository $data, private BrandMentionScheduler $scheduler, private AiMentionsProvider $provider, private ProjectRepository $projects, private UserRepository $users, private Authorizer $auth, private FeatureManager $features, private RateLimiter $limiter, private AuditLogger $audit, private Clock $clock, private LoggerInterface $logger) {}
    /**
     * Project already authorized by ProjectService/ToolContext.
     * @return array{settings: ?BrandSettings, latest: ?BrandCheck, attempt: ?BrandCheck, history: list<BrandCheck>, cost: float, available: bool, featureOn: bool} */
    public function page(Project $project): array
    {
        $settings = $this->data->settings($project);
        $history = $settings === null || $settings->name === '' ? [] : $this->data->history($project, $settings->key($project), 12);
        $complete = array_values(array_filter($history, static fn(BrandCheck $c): bool => $c->status === 'completed'));
        return ['settings' => $settings, 'latest' => $complete[0] ?? null, 'attempt' => $history[0] ?? null, 'history' => $history, 'cost' => $this->provider->estimatedCost(4, 52), 'available' => $this->provider->isAvailable($project->workspaceId), 'featureOn' => $this->features->enabled(Feature::KeywordMetrics)];
    }
    /**
     * @param array<string, mixed> $input */
    public function save(User $user, Project $project, array $input, ClientInfo $client): void
    {
        $this->auth->authorize($user->subject(), Permission::ProjectsEdit, $project->workspaceId, $project->id);
        $name = is_string($input['brand'] ?? null) ? trim($input['brand']) : '';
        $weekly = ($input['weekly'] ?? null) === '1';
        if (!is_string($input['brand'] ?? null) || !mb_check_encoding($name, 'UTF-8') || mb_strlen($name) > 120 || preg_match('/[\x00-\x1f\x7f<>]/u', $name) || ($name === '' && $weekly)) {
            throw new ValidationException(['brand' => 'Use a plain brand name of at most 120 characters. A weekly check requires a name.']);
        }
        $locale = KeywordLocale::fromInput($input['location'] ?? null, $input['language'] ?? null);
        if ($weekly) {
            $this->auth->authorize($user->subject(), Permission::AnalysisExecute, $project->workspaceId, $project->id);
        }
        $this->data->save($project, new BrandSettings($name, $locale, $weekly, $user->id));
        $this->audit->record(new AuditEvent('research.brand_settings_saved', ActorType::User, actorUserId: $user->id, workspaceId: $project->workspaceId, targetType: 'project', targetId: $project->publicId, metadata: ['configured' => $name !== '', 'weekly' => $weekly, 'locale' => $locale->key()]), $client);
    }
    public function request(User $user, Project $project, ClientInfo $client): BrandCheck
    {
        $this->auth->authorize($user->subject(), Permission::AnalysisExecute, $project->workspaceId, $project->id);
        $this->features->require(Feature::KeywordMetrics);
        $settings = $this->data->settings($project);
        if ($settings === null || $settings->name === '') {
            throw new HttpException(409, 'Save a brand name first.');
        }
        if (!$this->provider->isAvailable($project->workspaceId)) {
            throw new HttpException(409, 'Connect DataForSEO first.');
        }
        if (!$this->limiter->hit('brand:project:' . $project->id, 6, 3600)->allowed) {
            throw new HttpException(429, 'Wait before starting another paid brand check.');
        }
        $check = $this->enqueue($project, $settings, $user->id, false);
        $this->audit->record(new AuditEvent('research.brand_requested', ActorType::User, actorUserId: $user->id, workspaceId: $project->workspaceId, targetType: 'brand_check', targetId: $check->publicId), $client);
        return $check;
    }
    public function startDue(): int
    {
        if (!$this->features->enabled(Feature::KeywordMetrics)) {
            return 0;
        }
        $count = 0;
        foreach ($this->data->due($this->clock->now()->modify('-7 days')->format('Y-m-d H:i:s')) as $row) {
            $project = $this->projects->findInWorkspace($row['workspaceId'], $row['projectPublicId']);
            if ($project === null) {
                continue;
            }
            $settings = $this->data->settings($project);
            if ($settings === null || $settings->name === '' || !$settings->weekly || !$this->allowed($settings->userId, $project) || !$this->provider->isAvailable($project->workspaceId)) {
                continue;
            }
            try {
                $this->enqueue($project, $settings, $settings->userId, true);
                ++$count;
            } catch (HttpException $e) {
                if ($e->statusCode() !== 409) {
                    throw $e;
                }
            }
        }
        return $count;
    }
    public function run(int $workspaceId, string $projectId, string $checkId, int $step): void
    {
        $project = $this->projects->findInWorkspace($workspaceId, $projectId);
        if ($project === null || !$this->features->enabled(Feature::KeywordMetrics)) {
            return;
        }
        $check = $this->data->find($project, $checkId);
        if ($check === null || $check->step !== $step || $check->status !== 'queued') {
            return;
        }
        $settings = $this->data->settings($project);
        if ($settings === null || $settings->name === '' || $check->key !== $settings->key($project) || ($check->scheduled && !$settings->weekly) || !$this->allowed($check->userId, $project)) {
            $this->data->fail($project, $checkId, 'Brand settings or project access changed. No further requests were made.');
            return;
        }
        if (!$this->data->claim($project, $check)) {
            return;
        }
        $platform = AiMentionsProvider::PLATFORMS[intdiv($step, 2)];
        $metrics = $check->metrics;
        $answers = $check->answers;
        try {
            if ($step % 2 === 0) {
                $metrics[$platform] = $this->provider->brandMetrics($workspaceId, $project->id, $settings->name, $platform, $settings->locale);
            } else {
                $answers[$platform] = $this->provider->brandAnswers($workspaceId, $project->id, $settings->name, ResearchService::bareDomain($project->primaryDomain), $platform, $settings->locale, 25);
            }
            $this->data->advance($project, $check, $metrics, $answers);
            if ($step < 3) {
                $this->scheduler->fetch($project, $checkId, $step + 1);
            }
        } catch (BudgetExceededException|IntegrationException $e) {
            $this->data->fail($project, $checkId, $e->getMessage());
        } catch (\Throwable $e) {
            $this->data->fail($project, $checkId, 'The brand check was interrupted. Review Jobs and spending before requesting a new paid check.');
            $this->logger->error('Brand check interrupted', ['check' => $checkId, 'exception' => $e]);
            throw $e;
        }
    }
    private function allowed(int $userId, Project $project): bool
    {
        // One cron process can handle several steps; never reuse an earlier membership decision.
        $this->auth->forget();
        $user = $this->users->findById($userId);
        return $user !== null && $user->active && $this->auth->can($user->subject(), Permission::AnalysisExecute, $project->workspaceId, $project->id);
    }
    private function enqueue(Project $project, BrandSettings $settings, int $userId, bool $scheduled): BrandCheck
    {
        $check = $this->data->create($project, $settings, $userId, $scheduled);
        $this->scheduler->fetch($project, $check->publicId, 0);
        return $check;
    }
}
