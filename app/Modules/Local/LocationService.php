<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\Local;

use SeoControlCenter\Core\Audit\ActorType;
use SeoControlCenter\Core\Audit\AuditEvent;
use SeoControlCenter\Core\Audit\AuditLogger;
use SeoControlCenter\Core\Authorization\Permission;
use SeoControlCenter\Core\Features\Feature;
use SeoControlCenter\Core\Features\FeatureManager;
use SeoControlCenter\Core\Http\Exception\HttpException;
use SeoControlCenter\Core\Http\Exception\NotFoundHttpException;
use SeoControlCenter\Core\RateLimit\RateLimiter;
use SeoControlCenter\Core\Support\ClientInfo;
use SeoControlCenter\Core\Support\Clock;
use SeoControlCenter\Core\Support\Uuid;
use SeoControlCenter\Domain\Accounts\User;
use SeoControlCenter\Domain\Keywords\KeywordLocale;
use SeoControlCenter\Domain\Local\LocalArea;
use SeoControlCenter\Domain\Local\LocalReviewsProvider;
use SeoControlCenter\Domain\Local\LocalSearchProvider;
use SeoControlCenter\Domain\Projects\Project;
use SeoControlCenter\Domain\Projects\ProjectRepository;
use SeoControlCenter\Domain\Projects\ProjectService;
use SeoControlCenter\Domain\Shared\ValidationException;

final readonly class LocationService
{
    public function __construct(private LocationRepository $repository, private LocalRepository $primary, private LocationScheduler $scheduler, private LocalSearchProvider $provider, private LocalReviewsProvider $reviewsProvider, private ProjectService $projects, private ProjectRepository $projectRepository, private FeatureManager $features, private RateLimiter $rateLimiter, private AuditLogger $audit, private Clock $clock) {}

    /** @return array<string, mixed> */
    public function page(User $user, Project $project): array
    {
        $primaryCid = $this->primary->settings($project->id)?->cid;
        return ['locations' => array_map(fn(LocalLocation $l): array => ['location' => $l, 'history' => $this->repository->history($project->id, $l->publicId), 'reviews' => $this->visibleReviews($project->id, $l->publicId, $l->cid), 'cost' => $this->provider->estimatedCost(count($l->keywords)), 'grids' => $this->branchGrids($project, $l->publicId)], $this->repository->locations($project->id)), 'primaryReviews' => $this->visibleReviews($project->id, 'primary', $primaryCid ?? ''), 'primaryCid' => $primaryCid, 'reviewsCost' => $this->reviewsProvider->estimatedCost(), 'canEdit' => $this->projects->can($user, Permission::ProjectsEdit, $project), 'canRun' => $this->projects->can($user, Permission::AnalysisExecute, $project), 'featureOn' => $this->features->enabled(Feature::RankTracking), 'available' => $this->provider->isAvailable($project->workspaceId)];
    }

    /** @param array<string, mixed> $input */
    public function grid(User $user, Project $project, string $locationId, array $input, ClientInfo $client): void
    {
        $location = $this->repository->find($project->id, $locationId) ?? throw new NotFoundHttpException();
        $this->paid($user, $project);
        $keyword = is_string($input['grid_keyword'] ?? null) ? $input['grid_keyword'] : '';
        $size = is_string($input['grid_size'] ?? null) && ctype_digit($input['grid_size']) ? (int) $input['grid_size'] : 0;
        $spacing = is_string($input['grid_spacing'] ?? null) && ctype_digit($input['grid_spacing']) ? (int) $input['grid_spacing'] : 0;
        if (!in_array($keyword, $location->keywords, true) || !in_array($size, \SeoControlCenter\Domain\Local\LocalGrid::SIZES, true) || !isset(\SeoControlCenter\Domain\Local\LocalGrid::SPACINGS[$spacing])) {
            throw new ValidationException(['grid' => 'Choose a branch keyword, grid size and point spacing.']);
        }
        if (!$this->rateLimiter->hit('local:branch-grid:' . $project->id, 10, 3600)->allowed) {
            throw new HttpException(429, 'Wait before requesting more branch grids.');
        }
        $grid = new BranchGrid(Uuid::v7($this->clock), $keyword, new \SeoControlCenter\Domain\Local\LocalGrid(0, $this->clock->now()->format('Y-m-d'), $size, $spacing, $location->area, [], 'queued'));
        $this->repository->createGrid($project->id, $locationId, $grid);
        $this->scheduler->dispatch('grid', $project->id, $project->workspaceId, $project->publicId, $locationId, gridId: $grid->publicId);
        $this->record('local.branch_grid_requested', $user, $project, $client);
    }

    /** @return array{grids: list<BranchGrid>, queued: bool, costs: array<int, float>} */
    public function branchGrids(Project $project, string $location): array
    {
        return ['grids' => $this->repository->grids($project->id, $location), 'queued' => $this->repository->queuedGrid($project->id, $location) !== null, 'costs' => array_combine(\SeoControlCenter\Domain\Local\LocalGrid::SIZES, array_map(fn(int $size): float => round($this->provider->estimatedCost($size * $size) - $this->provider->estimatedCost(0), 4), \SeoControlCenter\Domain\Local\LocalGrid::SIZES))];
    }

    public function failGrid(int $projectId, string $location, string $message, ?string $gridId = null): void
    {
        $grid = $this->repository->queuedGrid($projectId, $location);
        if ($grid !== null && ($gridId === null || $grid->publicId === $gridId)) {
            $this->repository->finishGrid($projectId, $grid->publicId, 'failed', $message);
        }
    }

    private function gridStep(Project $project, LocalLocation $location, ?string $gridId): void
    {
        $branchGrid = $this->repository->queuedGrid($project->id, $location->publicId);
        if ($branchGrid === null || ($gridId !== null && $branchGrid->publicId !== $gridId)) {
            return;
        }
        foreach ($branchGrid->grid->areas() as $index => $area) {
            if (array_key_exists($index, $branchGrid->grid->positions)) {
                continue;
            }
            $position = null;
            foreach ($this->provider->maps($project->workspaceId, $project->id, $branchGrid->keyword, $area, $location->language, LocalService::DEPTH) as $row) {
                if ($row->cid === $location->cid) {
                    $position = $row->position;
                    break;
                }
            }
            $this->repository->saveGridPoint($project->id, $branchGrid->publicId, $index, $position);
            $this->scheduler->dispatch('grid', $project->id, $project->workspaceId, $project->publicId, $location->publicId, 1, $branchGrid->publicId);
            return;
        }
        $this->repository->finishGrid($project->id, $branchGrid->publicId, 'done');
    }

    /** @param array<string, mixed> $body */
    public function add(User $user, Project $project, array $body, ClientInfo $client): void
    {
        $this->edit($user, $project);
        $name = is_string($body['name'] ?? null) ? trim($body['name']) : '';
        $cid = is_string($body['cid'] ?? null) ? trim($body['cid']) : '';
        $language = is_string($body['language'] ?? null) ? $body['language'] : '';
        $errors = [];
        if ($name === '' || mb_strlen($name) > 200) {
            $errors['name'] = 'Enter a location name of up to 200 characters.';
        }
        if (preg_match('/^[1-9][0-9]{0,19}$/D', $cid) !== 1) {
            $errors['cid'] = 'Enter this branch’s numeric Google Maps CID (up to 20 digits).';
        }
        if (!isset(KeywordLocale::LANGUAGES[$language])) {
            $errors['language'] = 'Choose a language.';
        }
        $area = null;
        try {
            $area = LocalArea::fromInput($body['coordinates'] ?? null, $body['zoom'] ?? null);
        } catch (ValidationException $e) {
            $errors += $e->errors();
        }
        $keywords = array_values(array_unique(array_filter(array_map(static fn(string $v): string => mb_strtolower(trim($v)), preg_split('/\R/', is_string($body['keywords'] ?? null) ? $body['keywords'] : '') ?: []), static fn(string $v): bool => $v !== '')));
        if ($keywords === [] || count($keywords) > LocalService::MAX_KEYWORDS || array_filter($keywords, static fn(string $k): bool => mb_strlen($k) > 100) !== []) {
            $errors['keywords'] = 'Enter 1–50 keywords, one per line, up to 100 characters each.';
        }
        if ($errors !== [] || $area === null) {
            throw new ValidationException($errors);
        }
        foreach ($this->repository->locations($project->id) as $existing) {
            if ($existing->cid === $cid) {
                throw new ValidationException(['cid' => 'This branch is already tracked.']);
            }
        }
        $weekly = ($body['weekly'] ?? null) === '1';
        if ($weekly) {
            $this->paid($user, $project);
        }
        $location = new LocalLocation(Uuid::v7($this->clock), $project->id, $name, $cid, $area, $language, $keywords, $weekly);
        $this->repository->save($location);
        $this->record('local.location_added', $user, $project, $client);
    }
    public function remove(User $user, Project $project, string $publicId, ClientInfo $client): void
    {
        $this->edit($user, $project);
        $this->repository->find($project->id, $publicId) ?? throw new NotFoundHttpException();
        $this->repository->remove($project->id, $publicId);
        $this->record('local.location_removed', $user, $project, $client);
    }
    /** @param array<string, mixed> $body */
    public function update(User $user, Project $project, string $publicId, array $body, ClientInfo $client): void
    {
        $this->edit($user, $project);
        $location = $this->repository->find($project->id, $publicId) ?? throw new NotFoundHttpException();
        $name = is_string($body['name'] ?? null) ? trim($body['name']) : '';
        $keywords = array_values(array_unique(array_filter(array_map(static fn(string $v): string => mb_strtolower(trim($v)), preg_split('/\R/', is_string($body['keywords'] ?? null) ? $body['keywords'] : '') ?: []), static fn(string $v): bool => $v !== '')));
        $errors = [];
        if ($name === '' || mb_strlen($name) > 200) {
            $errors['name'] = 'Enter a location name of up to 200 characters.';
        }
        if ($keywords === [] || count($keywords) > LocalService::MAX_KEYWORDS || array_filter($keywords, static fn(string $k): bool => mb_strlen($k) > 100) !== []) {
            $errors['keywords'] = 'Enter 1–50 keywords, one per line, up to 100 characters each.';
        }
        if ($errors !== []) {
            throw new ValidationException($errors);
        }
        $weekly = ($body['weekly'] ?? null) === '1';
        if ($weekly) {
            $this->paid($user, $project);
        }
        $this->repository->save(new LocalLocation($location->publicId, $location->projectId, $name, $location->cid, $location->area, $location->language, $keywords, $weekly, $location->checkedOn));
        $this->record('local.location_updated', $user, $project, $client);
    }
    public function request(User $user, Project $project, string $location, string $mode, ClientInfo $client): void
    {
        $identity = $this->identity($project->id, $location);
        if ($identity === null || ($location === 'primary' && $mode === 'check')) {
            throw new NotFoundHttpException();
        }
        $this->paid($user, $project);
        if (!$this->rateLimiter->hit('local.locations:' . $project->id, 10, 3600)->allowed) {
            throw new HttpException(429, 'Please wait before requesting another local analysis.');
        }
        if ($mode === 'reviews') {
            $state = $this->repository->reviews($project->id, $location);
            if (in_array($state['status'] ?? null, ['queued', 'submitting', 'pending'], true) && ($state['cid'] ?? null) === $identity->cid && ($state['created_at'] ?? '') >= $this->clock->now()->modify('-1 day')->format('Y-m-d H:i:s')) {
                throw new HttpException(409, 'A review import is already queued or awaiting its result.');
            }
            $items = ($state['cid'] ?? null) === $identity->cid ? ($state['items'] ?? []) : [];
            $this->repository->saveReviews($project->id, $location, ['status' => 'queued', 'cid' => $identity->cid, 'created_at' => $this->clock->now()->format('Y-m-d H:i:s'), 'items' => $items]);
        }
        $this->scheduler->dispatch($mode, $project->id, $project->workspaceId, $project->publicId, $location);
        $this->record('local.' . $mode . '_requested', $user, $project, $client);
    }
    /** One bounded provider step per job; persisted steps survive retries. */
    public function run(int $projectId, int $workspaceId, string $publicId, string $location, string $mode, ?string $gridId = null): void
    {
        $project = $this->projectRepository->findInWorkspace($workspaceId, $publicId);
        $identity = $this->identity($projectId, $location);
        if ($project === null || $project->id !== $projectId || $identity === null || !$this->features->enabled(Feature::RankTracking)) {
            return;
        }
        if ($mode === 'check') {
            $this->checkStep($project, $identity);
            return;
        }
        if ($mode === 'grid') {
            $this->gridStep($project, $identity, $gridId);
            return;
        }
        $state = $this->repository->reviews($projectId, $location);
        if (($state['cid'] ?? null) !== $identity->cid) {
            return;
        }
        if (in_array($state['status'] ?? null, ['queued', 'pending'], true) && ($state['created_at'] ?? '') < $this->clock->now()->modify('-1 day')->format('Y-m-d H:i:s')) {
            $state['status'] = 'failed';
            $state['error'] = 'The review import did not complete within 24 hours.';
            $this->repository->saveReviews($projectId, $location, $state);
            return;
        }
        if ($mode === 'reviews' && ($state['status'] ?? null) === 'queued') {
            // Mark before the paid POST. A lost response is ambiguous, so never automatically submit it again.
            $state['status'] = 'submitting';
            $this->repository->saveReviews($projectId, $location, $state);
            try {
                $state['task'] = $this->reviewsProvider->submit($workspaceId, $projectId, $identity->cid, $identity->area, $identity->language);
                $state['status'] = 'pending';
            } catch (\Throwable $e) {
                $state['status'] = 'failed';
                $state['error'] = 'Review submission failed. Check the job and usage ledger before requesting it again.';
                $this->repository->saveReviews($projectId, $location, $state);
                throw $e;
            }
            $this->repository->saveReviews($projectId, $location, $state);
        }
        if (($state['status'] ?? null) !== 'pending' || !is_string($state['task'] ?? null)) {
            return;
        }
        $items = $this->reviewsProvider->result($workspaceId, $state['task']);
        if ($items === null) {
            $this->scheduler->dispatch('poll', $projectId, $workspaceId, $publicId, $location, 300);
            return;
        }
        $state['items'] = $items;
        $state['status'] = 'done';
        $state['fetched_at'] = $this->clock->now()->format('Y-m-d H:i:s');
        $this->repository->saveReviews($projectId, $location, $state);
    }
    public function startDue(): void
    {
        if (!$this->features->enabled(Feature::RankTracking)) {
            return;
        }
        foreach ($this->repository->due($this->clock->now()->modify('-7 days')->format('Y-m-d')) as $row) {
            if ($this->provider->isAvailable($row['workspace_id'])) {
                $this->scheduler->dispatch('check', $row['project_id'], $row['workspace_id'], $row['public_id'], $row['location']);
            }
        }
    }
    public function failReviews(int $projectId, string $location): void
    {
        $state = $this->repository->reviews($projectId, $location);
        if (($state['status'] ?? null) === 'pending') {
            $state['status'] = 'failed';
            $state['error'] = 'The review import failed. Check System → Jobs before requesting another import.';
            $this->repository->saveReviews($projectId, $location, $state);
        }
    }
    private function checkStep(Project $project, LocalLocation $location): void
    {
        if ($location->publicId === 'primary') {
            return;
        }
        $date = $this->clock->now()->format('Y-m-d');
        $result = $this->repository->ranking($project->id, $location->publicId, $date);
        $rankings = is_array($result['keywords'] ?? null) ? $result['keywords'] : [];
        foreach ($location->keywords as $keyword) {
            if (array_key_exists($keyword, $rankings)) {
                continue;
            }
            $position = null;
            foreach ($this->provider->maps($project->workspaceId, $project->id, $keyword, $location->area, $location->language, LocalService::DEPTH) as $row) {
                if ($row->cid === $location->cid) {
                    $position = $row->position;
                    break;
                }
            }
            $rankings[$keyword] = $position;
            $result['keywords'] = $rankings;
            $this->repository->saveRanking($project->id, $location->publicId, $date, $result);
            $this->scheduler->dispatch('check', $project->id, $project->workspaceId, $project->publicId, $location->publicId, 1);
            return;
        }
        if (!array_key_exists('profile', $result)) {
            $profile = $this->provider->profile($project->workspaceId, $project->id, 'cid:' . $location->cid, $location->area, $location->language);
            $result['profile'] = $profile?->cid === $location->cid ? ['name' => $profile->title, 'rating' => $profile->rating, 'reviews' => $profile->votes, 'address' => $profile->address, 'claimed' => $profile->claimed] : null;
            $this->repository->saveRanking($project->id, $location->publicId, $date, $result);
        }
        $this->repository->markChecked($project->id, $location->publicId, $date);
    }
    private function identity(int $projectId, string $location): ?LocalLocation
    {
        if ($location !== 'primary') {
            return $this->repository->find($projectId, $location);
        }
        $s = $this->primary->settings($projectId);
        return $s === null || $s->cid === null ? null : new LocalLocation('primary', $projectId, $s->businessName, $s->cid, $s->area, $s->language, [], false);
    }
    /** @return array<string, mixed> */
    private function visibleReviews(int $projectId, string $location, string $cid): array
    {
        $state = $this->repository->reviews($projectId, $location);
        return ($state['cid'] ?? null) === $cid ? $state : [];
    }
    private function edit(User $user, Project $project): void
    {
        if (!$this->projects->can($user, Permission::ProjectsEdit, $project)) {
            throw new HttpException(403, 'You cannot edit this project’s locations.');
        }
    }
    private function paid(User $user, Project $project): void
    {
        if (!$this->projects->can($user, Permission::AnalysisExecute, $project)) {
            throw new HttpException(403, 'You cannot start paid analyses for this project.');
        }
        $this->features->require(Feature::RankTracking);
        if (!$this->provider->isAvailable($project->workspaceId)) {
            throw new HttpException(409, 'Connect DataForSEO first.');
        }
    }
    private function record(string $event, User $user, Project $project, ClientInfo $client): void
    {
        $this->audit->record(new AuditEvent($event, ActorType::User, actorUserId: $user->id, actorLabel: $user->email, workspaceId: $project->workspaceId, targetType: 'project', targetId: $project->publicId), $client);
    }
}
