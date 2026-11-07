<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\Comparison;

use SeoControlCenter\Core\Audit\ActorType;
use SeoControlCenter\Core\Audit\AuditEvent;
use SeoControlCenter\Core\Audit\AuditLogger;
use SeoControlCenter\Core\Http\Exception\HttpException;
use SeoControlCenter\Core\Support\ClientInfo;
use SeoControlCenter\Core\Support\Clock;
use SeoControlCenter\Domain\Accounts\User;
use SeoControlCenter\Domain\Comparison\Analysis;
use SeoControlCenter\Domain\Comparison\AnalysisRepository;
use SeoControlCenter\Domain\Projects\Project;
use SeoControlCenter\Modules\Branding\BrandingService;
use SeoControlCenter\Modules\Clusters\ClusterService;
use SeoControlCenter\Modules\Reports\ReportCustomization;

/**
 * The client report (PDF) of a topic's latest finished analysis, with the
 * project's report branding (ADR-038, D2). Exporting writes an audit event.
 */
final readonly class ComparisonReport
{
    public function __construct(
        private ClusterService $clusters,
        private AnalysisRepository $analyses,
        private ReportCustomization $customization,
        private AuditLogger $audit,
        private Clock $clock,
        private string $fontDirectory,
        private ?BrandingService $installationBranding = null,
    ) {}

    /**
     * The caller has checked that the user can see the project.
     *
     * @return array{fileName: string, content: string}
     */
    public function pdf(User $user, Project $project, string $clusterId, ClientInfo $client): array
    {
        $cluster = $this->clusters->get($project, $clusterId);
        $analysis = $this->analyses->latestForCluster($project, $cluster->publicId);
        if ($analysis === null || $analysis->status !== Analysis::DONE) {
            throw new HttpException(404, 'This topic has no finished analysis yet.');
        }
        $brand = $this->customization->brand($project);
        $logo = $this->customization->logo($project)['body'] ?? null;
        if ($logo === null && $brand->hasLogo()) {
            $file = $this->installationBranding?->logo();
            $logo = $file !== null ? ((string) @file_get_contents($file['path']) ?: null) : null;
        }
        $content = ComparisonReportPdf::render($this->fontDirectory, $project, $analysis, $brand, $logo, $this->clock->now());
        $this->audit->record(new AuditEvent('comparison.report_exported', ActorType::User, actorUserId: $user->id, actorLabel: $user->email, workspaceId: $project->workspaceId, targetType: 'competitor_analysis', targetId: $analysis->publicId), $client);

        return ['fileName' => ComparisonReportPdf::fileName($project, $analysis), 'content' => $content];
    }
}
