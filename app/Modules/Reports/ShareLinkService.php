<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\Reports;

use SeoControlCenter\Core\Audit\ActorType;
use SeoControlCenter\Core\Audit\AuditEvent;
use SeoControlCenter\Core\Audit\AuditLogger;
use SeoControlCenter\Core\Authorization\Authorizer;
use SeoControlCenter\Core\Authorization\Permission;
use SeoControlCenter\Core\Http\Exception\NotFoundHttpException;
use SeoControlCenter\Core\Support\ClientInfo;
use SeoControlCenter\Core\Support\Clock;
use SeoControlCenter\Domain\Accounts\User;
use SeoControlCenter\Domain\Projects\Project;
use SeoControlCenter\Domain\Projects\ProjectRepository;
use SeoControlCenter\Domain\Shared\ValidationException;

/**
 * Read-only client links (1.3.0): a secret URL that shows a project's
 * report, without an account, until it expires or is revoked. The token is
 * 32 random bytes, shown once when the link is created; only its SHA-256 is
 * stored. Links show the report exactly as the report page does (same
 * sections and branding), and nothing else of the project.
 */
final readonly class ShareLinkService
{
    public const array DAYS = [7, 30, 90, 365];
    public const int MAX_ACTIVE = 20;
    public const string TOKEN_PATTERN = '[A-Za-z0-9_-]{43}';

    public function __construct(
        private ShareLinkRepository $links,
        private ProjectRepository $projects,
        private Authorizer $auth,
        private AuditLogger $audit,
        private Clock $clock,
    ) {}

    /** @return list<ShareLink> */
    public function forProject(Project $project): array
    {
        return $this->links->forProject($project->id, 50);
    }

    /**
     * @param array<string, mixed> $input label, days
     * @return array{link: ShareLink, url: string} the url holds the token and is shown only now
     */
    public function create(User $user, Project $project, array $input, ClientInfo $client): array
    {
        $this->auth->authorize($user->subject(), Permission::ProjectsEdit, $project->workspaceId, $project->id);
        $label = trim(is_string($input['label'] ?? null) ? $input['label'] : '');
        $days = filter_var($input['days'] ?? null, FILTER_VALIDATE_INT);
        $errors = [];
        if ($label === '' || mb_strlen($label) > 120) {
            $errors['label'] = 'Name the link (who it is for), up to 120 characters.';
        }
        if (!in_array($days, self::DAYS, true)) {
            $errors['days'] = 'Choose how long the link works.';
        }
        $now = $this->now();
        if ($this->links->countActive($project->id, $now) >= self::MAX_ACTIVE) {
            $errors['label'] = sprintf('A project can have %d working links. Revoke one first.', self::MAX_ACTIVE);
        }
        if ($errors !== []) {
            throw new ValidationException($errors);
        }
        $token = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $expires = $this->clock->now()->modify('+' . (int) $days . ' days')->format('Y-m-d H:i:s');
        $link = $this->links->create($project->workspaceId, $project->id, hash('sha256', $token, true), substr($token, 0, 6), $label, $expires, $user->id, $now);
        $this->audit->record(new AuditEvent('project.report_link_created', ActorType::User, actorUserId: $user->id, actorLabel: $user->email, workspaceId: $project->workspaceId, targetType: 'project', targetId: $project->publicId, metadata: ['link' => $link->publicId, 'label' => $label, 'expires_at' => $expires]), $client);

        return ['link' => $link, 'url' => '/share/' . $token];
    }

    public function revoke(User $user, Project $project, string $publicId, ClientInfo $client): void
    {
        $this->auth->authorize($user->subject(), Permission::ProjectsEdit, $project->workspaceId, $project->id);
        $link = $this->links->find($project->id, $publicId) ?? throw new NotFoundHttpException();
        $this->links->revoke($link->id, $this->now());
        $this->audit->record(new AuditEvent('project.report_link_revoked', ActorType::User, actorUserId: $user->id, actorLabel: $user->email, workspaceId: $project->workspaceId, targetType: 'project', targetId: $project->publicId, metadata: ['link' => $link->publicId, 'label' => $link->label]), $client);
    }

    /**
     * The project a working token opens. Unknown, expired and revoked
     * tokens, and links of deleted projects, all answer 404 the same way.
     *
     * @return array{link: ShareLink, project: Project}
     */
    public function open(string $token): array
    {
        $now = $this->now();
        $link = preg_match('/^' . self::TOKEN_PATTERN . '$/', $token) === 1 ? $this->links->byTokenHash(hash('sha256', $token, true)) : null;
        $project = $link !== null && $link->activeAt($now) ? $this->projects->findInWorkspace($link->workspaceId, $link->projectPublicId) : null;
        if ($link === null || $project === null) {
            throw new NotFoundHttpException();
        }

        return ['link' => $link, 'project' => $project];
    }

    public function recordView(ShareLink $link): void
    {
        $this->links->recordView($link->id, $this->now());
    }

    private function now(): string
    {
        return $this->clock->now()->format('Y-m-d H:i:s');
    }
}
