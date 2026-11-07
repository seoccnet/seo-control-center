<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\Opportunities;

use SeoControlCenter\Core\Audit\ActorType;
use SeoControlCenter\Core\Audit\AuditEvent;
use SeoControlCenter\Core\Audit\AuditLogger;
use SeoControlCenter\Core\Authorization\Permission;
use SeoControlCenter\Core\Http\Exception\HttpException;
use SeoControlCenter\Core\Support\ClientInfo;
use SeoControlCenter\Domain\Accounts\User;
use SeoControlCenter\Domain\Keywords\Keyword;
use SeoControlCenter\Domain\Opportunities\BusinessContext;
use SeoControlCenter\Domain\Opportunities\BusinessContextRepository;
use SeoControlCenter\Domain\Projects\Project;
use SeoControlCenter\Domain\Projects\ProjectService;
use SeoControlCenter\Domain\Shared\ValidationException;

/**
 * The project's business profile (what it sells and wants from search,
 * plus its difficulty and competitor-strength limits) and the team's
 * business-potential ratings of single keywords. Changes need
 * projects.edit, are audited and take effect at the next analysis.
 */
final readonly class BusinessProfileService
{
    public function __construct(
        private BusinessContextRepository $repository,
        private ProjectService $projects,
        private AuditLogger $audit,
    ) {}

    /** @return array{profile: ?BusinessContext, ratings: array<string, int>, canEdit: bool} */
    public function page(User $user, Project $project): array
    {
        return [
            'profile' => $this->repository->find($project->id),
            'ratings' => $this->repository->ratings($project->id),
            'canEdit' => $this->projects->can($user, Permission::ProjectsEdit, $project),
        ];
    }

    /**
     * @param array<string, mixed> $input
     * @throws ValidationException
     */
    public function save(User $user, Project $project, array $input, ClientInfo $client): BusinessContext
    {
        $this->authorize($user, $project);
        $context = BusinessContext::fromInput($input);
        $this->repository->save($project->id, $context, $user->id);
        $this->audit->record(new AuditEvent('opportunities.business_profile_saved', ActorType::User, actorUserId: $user->id, actorLabel: $user->email, workspaceId: $project->workspaceId, targetType: 'project', targetId: $project->publicId, metadata: [
            'model' => $context->model,
            'goal' => $context->goal,
            'offerings' => count($context->offerings),
            'exclusions' => count($context->exclusions),
        ]), $client);

        return $context;
    }

    public function clear(User $user, Project $project, ClientInfo $client): void
    {
        $this->authorize($user, $project);
        $this->repository->delete($project->id);
        $this->audit->record(new AuditEvent('opportunities.business_profile_cleared', ActorType::User, actorUserId: $user->id, actorLabel: $user->email, workspaceId: $project->workspaceId, targetType: 'project', targetId: $project->publicId), $client);
    }

    /**
     * Sets a keyword's business potential (0-3), or "auto" to return it to
     * the rules.
     *
     * @throws ValidationException
     */
    public function rate(User $user, Project $project, string $keyword, string $score, ClientInfo $client): ?int
    {
        $this->authorize($user, $project);
        $keyword = Keyword::normalize($keyword);
        if ($keyword === '' || mb_strlen($keyword) > 255) {
            throw new ValidationException(['keyword' => 'Choose a keyword of up to 255 characters.']);
        }
        if (!in_array($score, ['auto', '0', '1', '2', '3'], true)) {
            throw new ValidationException(['score' => 'Choose a business potential from 0 to 3, or automatic.']);
        }
        $value = $score === 'auto' ? null : (int) $score;
        $this->repository->rate($project->id, $keyword, $value, $user->id);
        $this->audit->record(new AuditEvent('opportunities.keyword_rated', ActorType::User, actorUserId: $user->id, actorLabel: $user->email, workspaceId: $project->workspaceId, targetType: 'project', targetId: $project->publicId, metadata: [
            'score' => $value,
        ]), $client);

        return $value;
    }

    private function authorize(User $user, Project $project): void
    {
        if (!$this->projects->can($user, Permission::ProjectsEdit, $project)) {
            throw new HttpException(403, 'You cannot change the business profile of this project.');
        }
    }
}
