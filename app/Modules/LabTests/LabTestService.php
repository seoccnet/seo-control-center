<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\LabTests;

use SeoControlCenter\Core\Audit\ActorType;
use SeoControlCenter\Core\Audit\AuditEvent;
use SeoControlCenter\Core\Audit\AuditLogger;
use SeoControlCenter\Core\Authorization\Authorizer;
use SeoControlCenter\Core\Authorization\Permission;
use SeoControlCenter\Core\Features\Feature;
use SeoControlCenter\Core\Features\FeatureManager;
use SeoControlCenter\Core\Http\Exception\HttpException;
use SeoControlCenter\Core\Http\Exception\NotFoundHttpException;
use SeoControlCenter\Core\RateLimit\RateLimiter;
use SeoControlCenter\Core\Support\ClientInfo;
use SeoControlCenter\Core\Support\Clock;
use SeoControlCenter\Domain\Accounts\User;
use SeoControlCenter\Domain\Integrations\IntegrationException;
use SeoControlCenter\Domain\LabTests\LabProfile;
use SeoControlCenter\Domain\LabTests\LabRun;
use SeoControlCenter\Domain\LabTests\LabTestProviders;
use SeoControlCenter\Domain\LabTests\LabTestRepository;
use SeoControlCenter\Domain\Projects\Project;
use SeoControlCenter\Domain\Projects\ProjectRepository;
use SeoControlCenter\Modules\SiteAudit\Crawler\UrlNormalizer;
use SeoControlCenter\Modules\SiteAudit\SiteAuditService;
use SeoControlCenter\Modules\WebVitals\WebVitalsRepository;

/**
 * Saved lab test profiles and repeat runs (ADR-040, F2). Runs happen in
 * queued jobs, never in a browser request. PageSpeed Insights runs are free
 * (the workspace's Google API key, shared with Chrome UX Report); DataForSEO
 * runs are paid and need an explicit confirmation. Lab results are shown
 * beside CrUX field data and never merged with it.
 */
final readonly class LabTestService
{
    public const int MAX_PROFILES = 10;
    public const int KEEP_RUNS = 50;
    public const int SHOWN_RUNS = 8;
    /** DataForSEO tasks are collected once a minute, at most this often. */
    public const int MAX_POLLS = 20;
    public const int POLL_SECONDS = 60;

    public function __construct(
        private LabTestRepository $tests,
        private LabTestProviders $providers,
        private LabTestScheduler $scheduler,
        private WebVitalsRepository $vitals,
        private ProjectRepository $projects,
        private Authorizer $auth,
        private FeatureManager $features,
        private RateLimiter $limiter,
        private AuditLogger $audit,
        private Clock $clock,
    ) {}

    /**
     * @return array{profiles: list<array{profile: LabProfile, runs: list<LabRun>, field: ?\SeoControlCenter\Domain\WebVitals\WebVitalRecord}>, can_edit: bool, has_key: bool, limit: int}
     */
    public function overview(User $user, Project $project): array
    {
        $runs = $this->tests->recentRuns($project->id, self::SHOWN_RUNS);
        $rows = [];
        foreach ($this->tests->profiles($project) as $profile) {
            $rows[] = ['profile' => $profile, 'runs' => $runs[$profile->id] ?? [], 'field' => $this->vitals->find($project->id, $profile->url, $profile->device === LabProfile::DESKTOP ? 'DESKTOP' : 'PHONE')];
        }

        return ['profiles' => $rows, 'can_edit' => $this->auth->can($user->subject(), Permission::ProjectsEdit, $project->workspaceId, $project->id), 'has_key' => $this->vitals->hasKey($project->workspaceId), 'limit' => self::MAX_PROFILES];
    }

    public function addProfile(User $user, Project $project, string $url, string $device, string $source, bool $weekly, ClientInfo $client): LabProfile
    {
        $this->auth->authorize($user->subject(), Permission::ProjectsEdit, $project->workspaceId, $project->id);
        $this->features->require(Feature::SiteAudit);
        $url = $this->pageUrl($project, $url);
        if (!in_array($device, LabProfile::DEVICES, true) || !in_array($source, LabProfile::SOURCES, true)) {
            throw new HttpException(422, 'Choose mobile or desktop and a test source.');
        }
        if ($source === LabProfile::PAGESPEED && !$this->vitals->hasKey($project->workspaceId)) {
            throw new HttpException(409, 'Add a Google API key under Integrations → Core Web Vitals first; enable the PageSpeed Insights API and allow it in the key\'s API restrictions.');
        }
        if ($this->tests->countProfiles($project->id) >= self::MAX_PROFILES) {
            throw new HttpException(409, sprintf('This project already has %d lab test profiles. Delete one first.', self::MAX_PROFILES));
        }
        $now = $this->now();
        $profile = $this->tests->createProfile($project, $url, $device, $source, $weekly, $weekly ? $now : null, $user->id, $now)
            ?? throw new HttpException(409, 'This page already has a profile for this device and source.');
        $this->audit->record(new AuditEvent('lab_tests.profile_added', ActorType::User, actorUserId: $user->id, workspaceId: $project->workspaceId, targetType: 'project', targetId: $project->publicId, metadata: ['profile' => $profile->publicId, 'device' => $device, 'source' => $source, 'weekly' => $weekly]), $client);

        return $profile;
    }

    public function deleteProfile(User $user, Project $project, string $profileId, ClientInfo $client): void
    {
        $this->auth->authorize($user->subject(), Permission::ProjectsEdit, $project->workspaceId, $project->id);
        if (!$this->tests->deleteProfile($project, $profileId)) {
            throw new NotFoundHttpException();
        }
        $this->audit->record(new AuditEvent('lab_tests.profile_deleted', ActorType::User, actorUserId: $user->id, workspaceId: $project->workspaceId, targetType: 'project', targetId: $project->publicId, metadata: ['profile' => $profileId]), $client);
    }

    public function setWeekly(User $user, Project $project, string $profileId, bool $weekly, ClientInfo $client): void
    {
        $this->auth->authorize($user->subject(), Permission::ProjectsEdit, $project->workspaceId, $project->id);
        if (!$this->tests->setWeekly($project, $profileId, $weekly, $weekly ? $this->now() : null)) {
            throw new NotFoundHttpException();
        }
        $this->audit->record(new AuditEvent('lab_tests.weekly_saved', ActorType::User, actorUserId: $user->id, workspaceId: $project->workspaceId, targetType: 'project', targetId: $project->publicId, metadata: ['profile' => $profileId, 'weekly' => $weekly]), $client);
    }

    /** Queues one run; a paid profile needs $confirmPaid. */
    public function runNow(User $user, Project $project, string $profileId, bool $confirmPaid, ClientInfo $client): LabRun
    {
        $this->auth->authorize($user->subject(), Permission::ProjectsEdit, $project->workspaceId, $project->id);
        $this->features->require(Feature::SiteAudit);
        $profile = $this->tests->profile($project, $profileId) ?? throw new NotFoundHttpException();
        if ($profile->paid() && !$confirmPaid) {
            throw new HttpException(422, sprintf('A DataForSEO Lighthouse run is paid (about $%.3f). Confirm the paid run.', LabTestClients::PAID_COST));
        }
        $this->tests->failStale($this->clock->now()->modify('-2 hours')->format('Y-m-d H:i:s'), $this->now());
        if ($this->tests->hasActiveRun($profile->id)) {
            throw new HttpException(409, 'A run of this profile is already waiting or running.');
        }
        if (!$this->limiter->hit('lab_tests:project:' . $project->id, 20, 3600)->allowed) {
            throw new HttpException(429, 'This project started 20 lab runs in the last hour. Try again later.');
        }
        $run = $this->tests->createRun($profile, 'manual', $user->id, $this->now());
        $this->scheduler->queue($run->id, $project->workspaceId);
        $this->audit->record(new AuditEvent('lab_tests.run_requested', ActorType::User, actorUserId: $user->id, workspaceId: $project->workspaceId, targetType: 'project', targetId: $project->publicId, metadata: ['profile' => $profile->publicId, 'source' => $profile->source, 'estimated_cost' => $profile->paid() ? LabTestClients::PAID_COST : 0.0]), $client);

        return $run;
    }

    /**
     * Works on one queued run: a PageSpeed run in one request (up to a
     * minute, within the job's 120-second reservation); a paid run is
     * submitted, then collected by later jobs. Returns the delay of the next
     * job for this run, or null when it is finished.
     */
    public function execute(int $runId): ?int
    {
        $run = $this->tests->run($runId);
        $profile = $run !== null ? $this->tests->profileById($run->profileId) : null;
        if ($run === null || $profile === null || !$run->active()) {
            return null;
        }
        $project = $this->project($profile);
        if ($project === null || !$this->features->enabled(Feature::SiteAudit)) {
            $this->tests->fail($run->id, 'The project or the site audit feature is no longer available.', $this->now());

            return null;
        }
        try {
            if ($profile->source === LabProfile::PAGESPEED) {
                $key = $this->vitals->apiKey($project->workspaceId) ?? throw new IntegrationException('The workspace has no Google API key any more.', false);
                $this->tests->markRunning($run->id);
                $this->complete($run, $profile, $this->providers->pageSpeed($profile->url, $profile->device, $key));

                return null;
            }
            if ($run->taskId === null) {
                $this->tests->markRunning($run->id);
                $this->tests->saveTask($run->id, $this->providers->submitPaid($project->workspaceId, $project->id, $profile->url, $profile->device), LabTestClients::PAID_COST);

                return self::POLL_SECONDS;
            }
            $result = $this->providers->collectPaid($project->workspaceId, $run->taskId, $profile->url, $profile->device);
            if ($result === null) {
                if ($run->polls + 1 >= self::MAX_POLLS) {
                    $this->tests->fail($run->id, 'DataForSEO did not finish the Lighthouse task in time.', $this->now());

                    return null;
                }
                $this->tests->polled($run->id);

                return self::POLL_SECONDS;
            }
            $this->complete($run, $profile, $result);

            return null;
        } catch (IntegrationException $e) {
            if ($e->retryable && $run->polls + 1 < self::MAX_POLLS && !$e->chargeUncertain) {
                $this->tests->polled($run->id);

                return self::POLL_SECONDS;
            }
            $this->tests->fail($run->id, mb_substr($e->getMessage(), 0, 500), $this->now());

            return null;
        }
    }

    /** Queues the runs of weekly profiles that are due; returns how many. */
    public function schedule(): int
    {
        if (!$this->features->enabled(Feature::SiteAudit)) {
            return 0;
        }
        $now = $this->clock->now();
        $this->tests->failStale($now->modify('-2 hours')->format('Y-m-d H:i:s'), $now->format('Y-m-d H:i:s'));
        $count = 0;
        foreach ($this->tests->due($now->format('Y-m-d H:i:s'), 100) as $profile) {
            $this->tests->scheduleNext($profile->id, $now->modify('+7 days')->format('Y-m-d H:i:s'));
            if ($this->tests->hasActiveRun($profile->id) || ($profile->source === LabProfile::PAGESPEED && !$this->vitals->hasKey($profile->workspaceId))) {
                continue;
            }
            $run = $this->tests->createRun($profile, 'weekly', null, $now->format('Y-m-d H:i:s'));
            $this->scheduler->queue($run->id, $profile->workspaceId);
            ++$count;
        }

        return $count;
    }

    private function complete(LabRun $run, LabProfile $profile, \SeoControlCenter\Domain\LabTests\LabResult $result): void
    {
        $this->tests->finish($run->id, $result, $this->now());
        $this->tests->prune($profile->id, self::KEEP_RUNS);
    }

    private function pageUrl(Project $project, string $url): string
    {
        $normalized = UrlNormalizer::normalize(trim($url));
        if ($normalized === null || parse_url($normalized, PHP_URL_SCHEME) !== 'https' || !in_array(UrlNormalizer::host($normalized), SiteAuditService::hosts(strtolower($project->primaryDomain)), true)) {
            throw new HttpException(422, 'Enter an HTTPS page address on this project’s domain.');
        }

        return $normalized;
    }

    private function project(LabProfile $profile): ?Project
    {
        foreach ($this->projects->allInWorkspace($profile->workspaceId) as $project) {
            if ($project->id === $profile->projectId) {
                return $project;
            }
        }

        return null;
    }

    private function now(): string
    {
        return $this->clock->now()->format('Y-m-d H:i:s');
    }
}
