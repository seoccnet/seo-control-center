<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\Reports;

use SeoControlCenter\Core\Audit\ActorType;
use SeoControlCenter\Core\Audit\AuditEvent;
use SeoControlCenter\Core\Audit\AuditLogger;
use SeoControlCenter\Core\Authorization\Authorizer;
use SeoControlCenter\Core\Authorization\Permission;
use SeoControlCenter\Core\Branding\Branding;
use SeoControlCenter\Core\Branding\CurrentBranding;
use SeoControlCenter\Core\Support\ClientInfo;
use SeoControlCenter\Domain\Accounts\User;
use SeoControlCenter\Domain\Projects\Project;
use SeoControlCenter\Domain\Shared\ValidationException;

final readonly class ReportCustomization
{
    public function __construct(private ReportScheduleRepository $repository, private Authorizer $auth, private AuditLogger $audit, private CurrentBranding $installation) {}
    public function brand(Project $project): Branding
    {
        $workspace = $this->repository->branding($project->workspaceId);
        $fallback = $this->installation->get();
        return new Branding($workspace->productName ?? $fallback->productName, $workspace->accentColor ?? $fallback->accentColor, $workspace->reportFooter ?? $fallback->reportFooter, $workspace->productName === null ? $fallback->logoFile : null, $workspace->productName === null ? $fallback->logoVersion : null);
    }
    public function sections(Project $project): ReportSections
    {
        return $this->repository->sections($project->id);
    }
    public function settingsBrand(Project $project): Branding
    {
        return $this->repository->branding($project->workspaceId);
    }
    /** @return array{body: string, type: string}|null */
    public function logo(Project $project): ?array
    {
        return $this->repository->logo($project->workspaceId);
    }
    public function canBrand(User $user, Project $project): bool
    {
        return $this->auth->can($user->subject(), Permission::WorkspaceManage, $project->workspaceId);
    }
    /** @param array<string, mixed> $input */
    public function saveSections(User $user, Project $project, array $input, ClientInfo $client): void
    {
        $this->auth->authorize($user->subject(), Permission::ProjectsEdit, $project->workspaceId, $project->id);
        $this->repository->saveSections($project->id, ReportSections::fromInput($input['sections'] ?? null));
        $this->record('report.sections_updated', $user, $project, $client);
    }
    /** @param array<string, mixed> $input */
    public function saveBrand(User $user, Project $project, array $input, ClientInfo $client): void
    {
        $this->auth->authorize($user->subject(), Permission::WorkspaceManage, $project->workspaceId);
        $name = is_string($input['product_name'] ?? null) ? trim($input['product_name']) : '';
        $color = is_string($input['accent_color'] ?? null) ? strtolower(trim($input['accent_color'])) : '';
        $footer = is_string($input['report_footer'] ?? null) ? trim($input['report_footer']) : '';
        $errors = [];
        if (mb_strlen($name) > 60 || preg_match('/[\x00-\x1f<>]/u', $name) === 1) {
            $errors['product_name'] = 'Use up to 60 characters, without markup or control characters.';
        }
        if ($color !== '' && preg_match('/^#[0-9a-f]{6}$/D', $color) !== 1) {
            $errors['accent_color'] = 'Use a six-digit hex colour, such as #2f5fd0.';
        }
        if (mb_strlen($footer) > 300) {
            $errors['report_footer'] = 'Use up to 300 characters.';
        }
        if ($errors !== []) {
            throw new ValidationException($errors);
        }
        $this->repository->saveBranding($project->workspaceId, new Branding($name !== '' ? $name : null, $color !== '' ? $color : null, $footer !== '' ? $footer : null));
        $this->record('report.workspace_branding_updated', $user, $project, $client);
    }
    public function saveLogo(User $user, Project $project, ?string $body, ClientInfo $client): void
    {
        $this->auth->authorize($user->subject(), Permission::WorkspaceManage, $project->workspaceId);
        $type = null;
        if ($body !== null) {
            $info = strlen($body) <= 524288 ? @getimagesizefromstring($body) : false;
            $type = is_array($info) ? [IMAGETYPE_PNG => 'image/png', IMAGETYPE_JPEG => 'image/jpeg', IMAGETYPE_WEBP => 'image/webp'][$info[2]] ?? null : null;
            if ($type === null || !is_array($info) || $info[0] < 16 || $info[1] < 16 || $info[0] > 2000 || $info[1] > 2000) {
                throw new ValidationException(['logo' => 'Choose a PNG, JPEG or WebP up to 512 KB, 16–2,000 pixels per side.']);
            }
        }
        $this->repository->saveLogo($project->workspaceId, $body, $type);
        $this->record('report.workspace_logo_updated', $user, $project, $client);
    }
    private function record(string $event, User $user, Project $project, ClientInfo $client): void
    {
        $this->audit->record(new AuditEvent($event, ActorType::User, actorUserId: $user->id, workspaceId: $project->workspaceId, targetType: 'project', targetId: $project->publicId), $client);
    }
}
