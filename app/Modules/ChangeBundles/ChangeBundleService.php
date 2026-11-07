<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\ChangeBundles;

use SeoControlCenter\Core\Audit\ActorType;
use SeoControlCenter\Core\Audit\AuditEvent;
use SeoControlCenter\Core\Audit\AuditLogger;
use SeoControlCenter\Core\Authorization\Authorizer;
use SeoControlCenter\Core\Authorization\Permission;
use SeoControlCenter\Core\Features\Feature;
use SeoControlCenter\Core\Features\FeatureManager;
use SeoControlCenter\Core\Http\Exception\HttpException;
use SeoControlCenter\Core\Http\Exception\NotFoundHttpException;
use SeoControlCenter\Core\Support\ClientInfo;
use SeoControlCenter\Core\Support\Clock;
use SeoControlCenter\Core\Support\Uuid;
use SeoControlCenter\Domain\Accounts\User;
use SeoControlCenter\Domain\ChangeBundles\BundleRules;
use SeoControlCenter\Domain\Projects\Project;
use SeoControlCenter\Domain\Projects\ProjectRepository;
use SeoControlCenter\Modules\Annotations\AnnotationService;
use SeoControlCenter\Modules\PageMonitoring\PageMonitoringService;

/**
 * Change bundles (ADR-042, 0.40.0): record, verify and guard; SEOcc never
 * edits the live site. A bundle's `data` holds problem, evidence, edits,
 * expected, validation, baseline (+ baseline_at), approved_hash/_at/_by,
 * guard, deployment, verification, rollback, annotation_id, pending and
 * check_error. Reads require a project authorized by ProjectService or the
 * MCP ToolContext; writes authorize here.
 *
 * @phpstan-import-type Bundle from ChangeBundleRepository
 * @phpstan-import-type Edit from BundleRules
 */
final readonly class ChangeBundleService
{
    public const array PURPOSES = ['baseline', 'guard', 'verify'];
    public const int MAX_BUNDLES = 200;

    public function __construct(private ChangeBundleRepository $bundles, private BundleCheckScheduler $checks, private LivePageReader $pages, private ProjectRepository $projects, private AnnotationService $annotations, private Authorizer $auth, private AuditLogger $audit, private Clock $clock, private FeatureManager $features, private ?BundleDrafts $drafts = null, private ?\SeoControlCenter\Modules\Annotations\MeasurementPlanService $plans = null) {}

    /** @return list<Bundle> */
    public function list(Project $project): array
    {
        return $this->bundles->list($project, 50);
    }

    /**
     * @return array{bundle: Bundle, edits: list<Edit>, events: list<array{kind: string, detail: string, created_at: string}>, hash: string, block: ?string, today: string}
     */
    public function show(Project $project, string $id): array
    {
        $bundle = $this->get($project, $id);

        return ['bundle' => $bundle, 'edits' => self::edits($bundle['data']), 'events' => $this->bundles->events($project, $id), 'hash' => self::hash($bundle), 'block' => $this->block($bundle), 'today' => $this->today($project)];
    }

    /** @param array<string, mixed> $input */
    public function create(User $user, Project $project, array $input, ClientInfo $client): string
    {
        $this->auth->authorize($user->subject(), Permission::ProjectsEdit, $project->workspaceId, $project->id);
        if ($this->bundles->countInProject($project->id) >= self::MAX_BUNDLES) {
            throw new HttpException(409, sprintf('A project can keep %d change bundles.', self::MAX_BUNDLES));
        }
        [$title, $url, $content] = $this->content($project, $input);
        // Started from an opportunity: keep where it came from, so a measurement plan can follow the deployment.
        $from = is_string($input['opportunity'] ?? null) && Uuid::isValid($input['opportunity']) ? $this->drafts?->fromOpportunity($project, $input['opportunity']) : null;
        if ($from !== null) {
            $content['source'] = $from['source'];
        }
        $id = Uuid::v7($this->clock);
        $this->bundles->create($project, $id, $title, $url, 'draft', $content, $user->id, $this->now());
        $this->bundles->addEvent($project, $id, 'created', $from !== null ? 'Draft created from an opportunity.' : 'Draft created.', $user->id, $this->now());
        $this->record($user, $project, 'change_bundle.created', $client);

        return $id;
    }

    /**
     * Edits the reviewed content. An approved bundle goes back to draft: the approval covered other content.
     *
     * @param array<string, mixed> $input
     */
    public function update(User $user, Project $project, string $id, array $input, ClientInfo $client): void
    {
        $this->auth->authorize($user->subject(), Permission::ProjectsEdit, $project->workspaceId, $project->id);
        $bundle = $this->get($project, $id);
        if (!in_array($bundle['status'], ['draft', 'approved'], true)) {
            throw new HttpException(409, 'A deployed bundle is a record of what happened and cannot be edited. Create a new bundle for further edits.');
        }
        [$title, $url, $content] = $this->content($project, $input);
        $data = [...$bundle['data'], ...$content];
        if ($url !== $bundle['url']) {
            // Another page: its old baseline and checks say nothing about it.
            $data = [...$data, 'baseline' => null, 'baseline_at' => null, 'guard' => null];
        }
        $cleared = $bundle['status'] === 'approved';
        if ($cleared) {
            $data = self::unapprove($data);
        }
        $this->save($project, $bundle, $title, $url, 'draft', $data, self::version($input));
        $this->bundles->addEvent($project, $id, 'updated', $cleared ? 'Content edited; the approval was cleared.' : 'Content edited.', $user->id, $this->now());
        $this->record($user, $project, 'change_bundle.updated', $client);
    }

    /** Queues a live read: the baseline before approval, the version guard before deployment, or verification after it. */
    public function queueCheck(User $user, Project $project, string $id, string $purpose, int $version, ClientInfo $client): void
    {
        $this->auth->authorize($user->subject(), Permission::ProjectsEdit, $project->workspaceId, $project->id);
        $this->features->require(Feature::SiteAudit);
        $bundle = $this->get($project, $id);
        $allowed = match ($purpose) {
            'baseline' => in_array($bundle['status'], ['draft', 'approved'], true),
            'guard' => $bundle['status'] === 'approved',
            'verify' => in_array($bundle['status'], ['deployed', 'partially_deployed', 'verified'], true),
            default => false,
        };
        if (!$allowed) {
            throw new HttpException(409, match ($purpose) {
                'guard' => 'The version guard checks an approved bundle.',
                'verify' => 'Verification reads the live page after a recorded deployment.',
                default => 'The baseline is captured before deployment.',
            });
        }
        $this->save($project, $bundle, $bundle['title'], $bundle['url'], $bundle['status'], [...$bundle['data'], 'pending' => $purpose, 'check_error' => null], $version);
        $this->checks->check($project, $id, $purpose);
        $this->bundles->addEvent($project, $id, 'check_queued', match ($purpose) {
            'baseline' => 'Live baseline requested.',
            'guard' => 'Version guard check requested.',
            default => 'Verification requested.',
        }, $user->id, $this->now());
    }

    /** Runs a queued live read (cron). */
    public function runCheck(int $projectId, int $workspaceId, string $projectPublicId, string $id, string $purpose): void
    {
        if (!$this->features->enabled(Feature::SiteAudit)) {
            return;
        }
        $project = $this->projects->findInWorkspace($workspaceId, $projectPublicId);
        $bundle = $project !== null && $project->id === $projectId ? $this->bundles->find($project, $id) : null;
        if ($project === null || $bundle === null || ($bundle['data']['pending'] ?? null) !== $purpose || !PageMonitoringService::validUrl($project, $bundle['url'])) {
            return;
        }
        $live = $this->pages->read($bundle['url']);
        $now = $this->now();
        // The bundle may have been edited while the page was read: apply to the current version once.
        $bundle = $this->bundles->find($project, $id);
        if ($bundle === null || ($bundle['data']['pending'] ?? null) !== $purpose) {
            return;
        }
        $data = [...$bundle['data'], 'pending' => null];
        $status = $bundle['status'];
        if (is_string($live)) {
            $data['check_error'] = ['purpose' => $purpose, 'message' => $live, 'at' => $now];
            $event = ['check_failed', 'Live read failed: ' . $live];
        } elseif ($purpose === 'baseline') {
            $data = [...$data, 'baseline' => $live, 'baseline_at' => $now];
            $event = ['baseline_captured', 'Live baseline captured.'];
            if ($status === 'approved') {
                $data = self::unapprove($data);
                $status = 'draft';
                $event = ['baseline_captured', 'Live baseline captured; the approval was cleared because it covered the previous baseline.'];
            }
        } elseif ($purpose === 'guard') {
            $changes = $status === 'approved' && is_array($data['baseline'] ?? null) ? BundleRules::differences($data['baseline'], $live) : ['status' => ['before' => 'no baseline', 'after' => 'live']];
            $data['guard'] = ['checked_at' => $now, 'matches' => $changes === [], 'changes' => $changes];
            $event = $changes === []
                ? ['guard_passed', 'The live page still matches the approved baseline.']
                : ['guard_blocked', 'The live page changed since the approval: ' . implode(', ', array_map(static fn(string $f, array $c): string => (BundleRules::GUARDED[$f] ?? $f) . ' ' . $c['before'] . ' → ' . $c['after'], array_keys($changes), $changes)) . '.'];
        } else {
            /** @var list<bool> $applied */
            $applied = is_array($data['deployment']['applied'] ?? null) ? array_values(array_map(static fn(mixed $a): bool => $a === true, $data['deployment']['applied'])) : [];
            $baseline = is_array($data['baseline'] ?? null) ? $data['baseline'] : null;
            $checks = BundleRules::verify(self::edits($data), $applied, $live, $baseline);
            $data['verification'] = ['checked_at' => $now, 'checks' => $checks];
            $statuses = array_column($checks, 'status');
            $mismatch = in_array('mismatch', $statuses, true);
            if (!$mismatch && $status === 'deployed') {
                $status = 'verified';
            }
            $event = $mismatch
                ? ['verification_failed', 'Not live: ' . implode(', ', array_map(static fn(array $c): string => BundleRules::FIELDS[$c['field']] ?? $c['field'], array_values(array_filter($checks, static fn(array $c): bool => $c['status'] === 'mismatch')))) . '.']
                : ['verified', 'Every checkable applied edit is live' . (in_array('manual', $statuses, true) ? '; the others need a check by hand' : '') . (in_array('not_applied', $statuses, true) ? '; some edits were not applied' : '') . '.'];
        }
        if ($this->bundles->update($project, $id, $bundle['title'], $bundle['url'], $status, $data, $bundle['version'], $now)) {
            $this->bundles->addEvent($project, $id, $event[0], $event[1], null, $now);
        }
    }

    /** Binds the approval to the bundle's content and live baseline. */
    public function approve(User $user, Project $project, string $id, int $version, ClientInfo $client): void
    {
        $this->auth->authorize($user->subject(), Permission::ProjectsEdit, $project->workspaceId, $project->id);
        $bundle = $this->get($project, $id);
        if ($bundle['status'] !== 'draft') {
            throw new HttpException(409, 'Only a draft can be approved.');
        }
        if (!is_array($bundle['data']['baseline'] ?? null)) {
            throw new HttpException(409, 'Capture the live baseline first: the approval covers the page as it is now.');
        }
        $hash = self::hash($bundle);
        $this->save($project, $bundle, $bundle['title'], $bundle['url'], 'approved', [...$bundle['data'], 'approved_hash' => $hash, 'approved_at' => $this->now(), 'approved_by' => $user->id, 'guard' => null], $version);
        $this->bundles->addEvent($project, $id, 'approved', 'Approved; content and baseline hash ' . substr($hash, 0, 12) . '.', $user->id, $this->now());
        $this->record($user, $project, 'change_bundle.approved', $client);
    }

    /**
     * Records that a person deployed the edits in their CMS, after the version guard passed.
     * Records the change on the timeline, so a measurement plan can follow.
     *
     * @param array<string, mixed> $input applied (edit indexes), deployed_on, note, version
     */
    public function recordDeployment(User $user, Project $project, string $id, array $input, ClientInfo $client): void
    {
        $this->auth->authorize($user->subject(), Permission::ProjectsEdit, $project->workspaceId, $project->id);
        $bundle = $this->get($project, $id);
        $block = $this->block($bundle);
        if ($block !== null) {
            throw new HttpException(409, $block);
        }
        $edits = self::edits($bundle['data']);
        $chosen = array_map('intval', array_filter(is_array($input['applied'] ?? null) ? $input['applied'] : [], static fn(mixed $v): bool => is_string($v) || is_int($v)));
        $applied = array_map(static fn(int $i): bool => in_array($i, $chosen, true), array_keys($edits));
        if (!in_array(true, $applied, true)) {
            throw new HttpException(422, 'Tick the edits that were applied. If none was, record nothing: the bundle stays approved.');
        }
        $date = $this->day($project, $input['deployed_on'] ?? null, substr((string) ($bundle['data']['approved_at'] ?? ''), 0, 10));
        $note = mb_substr(trim(is_string($input['note'] ?? null) ? $input['note'] : ''), 0, 2000);
        $failed = array_values(array_filter($edits, static fn(array $e, int $i): bool => !$applied[$i], ARRAY_FILTER_USE_BOTH));
        $annotation = $this->annotations->save($user, $project, ['date' => $date, 'kind' => BundleRules::changeKind(array_values(array_filter($edits, static fn(array $e, int $i): bool => $applied[$i], ARRAY_FILTER_USE_BOTH))), 'title' => mb_substr('Bundle: ' . $bundle['title'], 0, 120), 'note' => mb_substr(self::summary($edits, $applied) . ($note !== '' ? "\n" . $note : ''), 0, 2000), 'url' => $bundle['url']], $client);
        $status = $failed === [] ? 'deployed' : 'partially_deployed';
        $this->save($project, $bundle, $bundle['title'], $bundle['url'], $status, [...$bundle['data'], 'deployment' => ['deployed_on' => $date, 'applied' => $applied, 'note' => $note, 'recorded_at' => $this->now(), 'recorded_by' => $user->id], 'annotation_id' => $annotation->publicId], self::version($input));
        $this->bundles->addEvent($project, $id, $status, $failed === [] ? 'Deployment recorded for ' . $date . '; all edits applied.' : 'Partial deployment recorded for ' . $date . '; not applied: ' . implode(', ', array_map(static fn(array $e): string => BundleRules::FIELDS[$e['field']] ?? $e['field'], $failed)) . '.', $user->id, $this->now());
        $this->draftPlan($user, $project, $id, $bundle, $annotation->publicId, $client);
        $this->record($user, $project, 'change_bundle.deployed', $client);
    }

    /**
     * A bundle started from an opportunity gets a measurement plan on its recorded change (0.41.0):
     * the opportunity's metric, the page and its query, 28 days on each side. A plan that cannot be
     * saved never blocks the deployment record; the bundle's history says why.
     *
     * @param Bundle $bundle
     */
    private function draftPlan(User $user, Project $project, string $id, array $bundle, string $annotationId, ClientInfo $client): void
    {
        $source = $bundle['data']['source'] ?? null;
        if ($this->plans === null || !is_array($source) || !is_string($source['metric'] ?? null)) {
            return;
        }
        $metric = $source['metric'];
        $query = is_string($source['query'] ?? null) && !in_array($metric, \SeoControlCenter\Domain\Measurement\MeasurementResult::ANALYTICS, true) ? $source['query'] : '';
        try {
            $this->plans->save($user, $project, $annotationId, ['objective' => is_string($source['objective'] ?? null) && mb_strlen($source['objective']) >= 3 ? $source['objective'] : $bundle['title'], 'metric' => $metric, 'days' => (string) (is_int($source['days'] ?? null) ? $source['days'] : 28), 'target_pages' => $bundle['url'], 'target_queries' => $query, 'control_pages' => '', 'review_dates' => '', 'version' => '0'], $client);
            $this->bundles->addEvent($project, $id, 'plan_drafted', 'Measurement plan drafted on the recorded change (' . (\SeoControlCenter\Domain\Measurement\MeasurementResult::METRICS[$metric] ?? $metric) . '). Add control pages and freeze its baseline there.', $user->id, $this->now());
        } catch (HttpException $e) {
            $this->bundles->addEvent($project, $id, 'plan_skipped', 'No measurement plan drafted: ' . $e->getMessage(), $user->id, $this->now());
        }
    }

    /**
     * Deletes a bundle that was never deployed (0.42.0). A deployed bundle is a record of what
     * happened on the site and stays; its SEO change lives on the timeline.
     */
    public function delete(User $user, Project $project, string $id, int $version, ClientInfo $client): void
    {
        $this->auth->authorize($user->subject(), Permission::ProjectsEdit, $project->workspaceId, $project->id);
        $bundle = $this->get($project, $id);
        if (!in_array($bundle['status'], ['draft', 'approved'], true)) {
            throw new HttpException(409, 'A deployed bundle is a record of what happened and cannot be deleted. Record a rollback instead if the edits were undone.');
        }
        if (!$this->bundles->delete($project, $id, $version)) {
            throw new HttpException(409, 'Someone else changed this bundle. Reload the page and try again.');
        }
        $this->record($user, $project, 'change_bundle.deleted', $client);
    }

    /** @param array<string, mixed> $input note, rolled_back_on, version */
    public function rollback(User $user, Project $project, string $id, array $input, ClientInfo $client): void
    {
        $this->auth->authorize($user->subject(), Permission::ProjectsEdit, $project->workspaceId, $project->id);
        $bundle = $this->get($project, $id);
        if (!in_array($bundle['status'], ['deployed', 'partially_deployed', 'verified'], true)) {
            throw new HttpException(409, 'Only a deployed bundle can be rolled back.');
        }
        $note = trim(is_string($input['note'] ?? null) ? $input['note'] : '');
        if (mb_strlen($note) < 3 || mb_strlen($note) > 2000) {
            throw new HttpException(422, 'Say what was rolled back and why (3 to 2,000 characters).');
        }
        $date = $this->day($project, $input['rolled_back_on'] ?? null, (string) ($bundle['data']['deployment']['deployed_on'] ?? ''));
        $annotation = $this->annotations->save($user, $project, ['date' => $date, 'kind' => BundleRules::changeKind(self::edits($bundle['data'])), 'title' => mb_substr('Rolled back: ' . $bundle['title'], 0, 120), 'note' => $note, 'url' => $bundle['url']], $client);
        $this->save($project, $bundle, $bundle['title'], $bundle['url'], 'rolled_back', [...$bundle['data'], 'rollback' => ['rolled_back_on' => $date, 'note' => $note, 'recorded_at' => $this->now(), 'annotation_id' => $annotation->publicId]], self::version($input));
        $this->bundles->addEvent($project, $id, 'rolled_back', 'Rollback recorded for ' . $date . ': ' . $note, $user->id, $this->now());
        $this->record($user, $project, 'change_bundle.rolled_back', $client);
    }

    /** @param Bundle $bundle */
    public static function hash(array $bundle): string
    {
        $d = $bundle['data'];

        return BundleRules::hash($bundle['title'], (string) ($d['problem'] ?? ''), (string) ($d['evidence'] ?? ''), $bundle['url'], self::edits($d), (string) ($d['expected'] ?? ''), (string) ($d['validation'] ?? ''), is_array($d['baseline'] ?? null) ? $d['baseline'] : null);
    }

    /**
     * @param array<string, mixed> $data
     * @return list<Edit>
     */
    public static function edits(array $data): array
    {
        $edits = [];
        foreach (is_array($data['edits'] ?? null) ? $data['edits'] : [] as $edit) {
            if (is_array($edit) && is_string($edit['field'] ?? null) && is_string($edit['before'] ?? null) && is_string($edit['after'] ?? null)) {
                $edits[] = ['field' => $edit['field'], 'before' => $edit['before'], 'after' => $edit['after']];
            }
        }

        return $edits;
    }

    /** @param Bundle $bundle */
    private function block(array $bundle): ?string
    {
        $d = $bundle['data'];
        $guard = null;
        if (is_array($d['guard'] ?? null) && is_string($d['guard']['checked_at'] ?? null) && is_bool($d['guard']['matches'] ?? null)) {
            $changes = [];
            foreach (is_array($d['guard']['changes'] ?? null) ? $d['guard']['changes'] : [] as $field => $change) {
                if (is_string($field) && is_array($change) && is_string($change['before'] ?? null) && is_string($change['after'] ?? null)) {
                    $changes[$field] = ['before' => $change['before'], 'after' => $change['after']];
                }
            }
            $guard = ['checked_at' => $d['guard']['checked_at'], 'matches' => $d['guard']['matches'], 'changes' => $changes];
        }

        return BundleRules::deploymentBlock($bundle['status'], self::hash($bundle), is_string($d['approved_hash'] ?? null) ? $d['approved_hash'] : null, is_string($d['approved_at'] ?? null) ? $d['approved_at'] : null, $guard, $this->now());
    }

    /**
     * @param array<string, mixed> $input
     * @return array{string, string, array<string, mixed>}
     */
    private function content(Project $project, array $input): array
    {
        $text = static fn(string $key): string => is_string($input[$key] ?? null) ? trim($input[$key]) : '';
        $title = $text('title');
        $url = $text('url');
        $lengths = ['title' => [3, 200, 'the title'], 'problem' => [3, 2000, 'the problem'], 'expected' => [3, 2000, 'the expected effects'], 'validation' => [3, 2000, 'how to validate it']];
        foreach ($lengths as $key => [$min, $max, $label]) {
            if (mb_strlen($text($key)) < $min || mb_strlen($text($key)) > $max) {
                throw new HttpException(422, sprintf('Describe %s in %d to %s characters.', $label, $min, number_format($max)));
            }
        }
        if (mb_strlen($text('evidence')) > 4000) {
            throw new HttpException(422, 'Keep the evidence under 4,000 characters; link to longer sources.');
        }
        if (!PageMonitoringService::validUrl($project, $url)) {
            throw new HttpException(422, sprintf('Enter the full address of a page on %s.', $project->primaryDomain));
        }
        $fields = is_array($input['edit_field'] ?? null) ? array_values($input['edit_field']) : [];
        $before = is_array($input['edit_before'] ?? null) ? array_values($input['edit_before']) : [];
        $after = is_array($input['edit_after'] ?? null) ? array_values($input['edit_after']) : [];
        $edits = [];
        foreach ($fields as $i => $field) {
            $value = is_string($after[$i] ?? null) ? trim($after[$i]) : '';
            if (!is_string($field) || $field === '' || $value === '') {
                continue;
            }
            $old = is_string($before[$i] ?? null) ? trim($before[$i]) : '';
            if (!isset(BundleRules::FIELDS[$field]) || mb_strlen($value) > 2000 || mb_strlen($old) > 2000) {
                throw new HttpException(422, 'Each edit needs a known field and before/after text up to 2,000 characters.');
            }
            $edits[] = ['field' => $field, 'before' => $old, 'after' => $value];
        }
        if ($edits === [] || count($edits) > BundleRules::MAX_EDITS) {
            throw new HttpException(422, sprintf('List 1 to %d exact edits, each with the new text.', BundleRules::MAX_EDITS));
        }

        return [$title, $url, ['problem' => $text('problem'), 'evidence' => $text('evidence'), 'edits' => $edits, 'expected' => $text('expected'), 'validation' => $text('validation')]];
    }

    /**
     * @param Bundle $bundle
     * @param array<string, mixed> $data
     */
    private function save(Project $project, array $bundle, string $title, string $url, string $status, array $data, int $version): void
    {
        if (!$this->bundles->update($project, $bundle['public_id'], $title, $url, $status, $data, $version, $this->now())) {
            throw new HttpException(409, 'Someone else changed this bundle. Reload the page and try again.');
        }
    }

    /** @return Bundle */
    private function get(Project $project, string $id): array
    {
        return (Uuid::isValid($id) ? $this->bundles->find($project, $id) : null) ?? throw new NotFoundHttpException('Change bundle not found.');
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private static function unapprove(array $data): array
    {
        return [...$data, 'approved_hash' => null, 'approved_at' => null, 'approved_by' => null, 'guard' => null];
    }

    /** @param array<string, mixed> $input */
    private static function version(array $input): int
    {
        $version = filter_var($input['version'] ?? null, FILTER_VALIDATE_INT);

        return $version === false ? -1 : $version;
    }

    /**
     * @param list<Edit> $edits
     * @param list<bool> $applied
     */
    private static function summary(array $edits, array $applied): string
    {
        return implode("\n", array_map(static fn(array $e, int $i): string => ($applied[$i] ? '' : '(not applied) ') . (BundleRules::FIELDS[$e['field']] ?? $e['field']) . ': ' . mb_substr($e['after'], 0, 200), $edits, array_keys($edits)));
    }

    private function day(Project $project, mixed $value, string $earliest): string
    {
        $today = $this->today($project);
        $date = is_string($value) && $value !== '' ? $value : $today;
        $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        if ($parsed === false || $parsed->format('Y-m-d') !== $date || $date > $today || ($earliest !== '' && $date < $earliest)) {
            throw new HttpException(422, 'Enter the day it happened: not in the future and not before the previous step.');
        }

        return $date;
    }

    private function record(User $user, Project $project, string $action, ClientInfo $client): void
    {
        $this->audit->record(new AuditEvent($action, ActorType::User, actorUserId: $user->id, workspaceId: $project->workspaceId, targetType: 'project', targetId: $project->publicId), $client);
    }

    private function today(Project $project): string
    {
        try {
            return $this->clock->now()->setTimezone(new \DateTimeZone($project->timezone))->format('Y-m-d');
        } catch (\Exception) {
            return $this->clock->now()->format('Y-m-d');
        }
    }

    private function now(): string
    {
        return $this->clock->now()->format('Y-m-d H:i:s');
    }
}
