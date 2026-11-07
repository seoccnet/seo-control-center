<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\Reports;

use Psr\Log\LoggerInterface;
use SeoControlCenter\Core\Audit\ActorType;
use SeoControlCenter\Core\Audit\AuditEvent;
use SeoControlCenter\Core\Audit\AuditLogger;
use SeoControlCenter\Core\Authorization\Authorizer;
use SeoControlCenter\Core\Authorization\Permission;
use SeoControlCenter\Core\Http\Exception\HttpException;
use SeoControlCenter\Core\Mail\Attachment;
use SeoControlCenter\Core\Mail\Mailer;
use SeoControlCenter\Core\Mail\MailException;
use SeoControlCenter\Core\Mail\Message;
use SeoControlCenter\Core\RateLimit\RateLimiter;
use SeoControlCenter\Core\Support\ClientInfo;
use SeoControlCenter\Core\Support\Clock;
use SeoControlCenter\Core\View\ViewRenderer;
use SeoControlCenter\Domain\Accounts\User;
use SeoControlCenter\Domain\Projects\Project;
use SeoControlCenter\Domain\Projects\ProjectRepository;
use SeoControlCenter\Domain\Shared\ValidationException;

/**
 * Scheduled project reports: the schedule (projects.edit), test sends, the
 * hourly check for due reports and the sending itself (as a job). Report
 * e-mails have a plain-text and an HTML part (ADR-027 amendment), link the
 * printable report page and carry the report as a PDF (ADR-033).
 */
final readonly class ReportService
{
    public function __construct(
        private ReportScheduleRepository $schedules,
        private ReportScheduler $scheduler,
        private ReportBuilder $builder,
        private ProjectRepository $projects,
        private Mailer $mailer,
        private Authorizer $authorizer,
        private RateLimiter $rateLimiter,
        private AuditLogger $audit,
        private Clock $clock,
        private LoggerInterface $logger,
        private ViewRenderer $views,
        private string $appUrl,
        private ReportCustomization $customization,
        private string $fontDirectory = '',
        private ?\SeoControlCenter\Modules\Branding\BrandingService $installationBranding = null,
    ) {}

    /**
     * The report for one period as a PDF, with the project's branding and
     * report sections. The caller has checked access to the project.
     *
     * @return array{fileName: string, content: string}
     */
    public function pdf(Project $project, string $frequency, \SeoControlCenter\Domain\Shared\DateRange $period): array
    {
        $report = $this->builder->build($project, $frequency, $period);

        return ['fileName' => ReportPdf::fileName($report), 'content' => $this->renderPdf($project, $report, $frequency)];
    }

    /** @param array<string, mixed> $report */
    private function renderPdf(Project $project, array $report, string $frequency): string
    {
        $brand = $this->customization->brand($project);
        $logo = $this->customization->logo($project)['body'] ?? null;
        if ($logo === null && $brand->hasLogo()) {
            $file = $this->installationBranding?->logo();
            $logo = $file !== null ? ((string) @file_get_contents($file['path']) ?: null) : null;
        }

        return ReportPdf::render($this->fontDirectory, $report, $brand, $this->customization->sections($project), $this->reportUrl($project, $frequency, $report['period']->toString()), $logo, $this->clock->now());
    }

    /**
     * @return array{schedule: ?ReportSchedule, deliveries: list<array{period: string, kind: string, recipients: int, failed: int, error: ?string, created_at: string}>, mailConfigured: bool}
     */
    public function page(Project $project): array
    {
        $schedule = $this->schedules->find($project->id);

        return [
            'schedule' => $schedule,
            'deliveries' => $this->schedules->deliveries($project->id, 20),
            'mailConfigured' => $this->mailer->isConfigured(),
        ];
    }

    /**
     * @throws ValidationException
     */
    public function save(User $user, Project $project, string $frequency, string $recipientsText, bool $enabled, ClientInfo $client): void
    {
        $this->authorize($user, $project);
        $errors = [];
        if (!in_array($frequency, [ReportSchedule::WEEKLY, ReportSchedule::MONTHLY], true)) {
            $errors['frequency'] = 'Choose weekly or monthly.';
        }
        $recipients = [];
        foreach (preg_split('/[\s,;]+/', $recipientsText) ?: [] as $address) {
            $address = mb_strtolower(trim($address));
            if ($address === '') {
                continue;
            }
            if (filter_var($address, FILTER_VALIDATE_EMAIL) === false || strlen($address) > 254) {
                $errors['recipients'] = sprintf('"%s" is not a valid e-mail address.', mb_substr($address, 0, 80));
                break;
            }
            $recipients[$address] = true;
        }
        if (!isset($errors['recipients']) && ($recipients === [] || count($recipients) > ReportSchedule::MAX_RECIPIENTS)) {
            $errors['recipients'] = sprintf('Enter between 1 and %d e-mail addresses.', ReportSchedule::MAX_RECIPIENTS);
        }
        if ($errors !== []) {
            throw new ValidationException($errors);
        }
        $this->schedules->save($project->id, $project->workspaceId, $frequency, array_keys($recipients), $enabled, $user->id);
        $this->audit->record(new AuditEvent('project.report_schedule_saved', ActorType::User, actorUserId: $user->id, actorLabel: $user->email, workspaceId: $project->workspaceId, targetType: 'project', targetId: $project->publicId, metadata: [
            'frequency' => $frequency,
            'recipients' => count($recipients),
            'enabled' => $enabled,
        ]), $client);
    }

    /**
     * Queues the latest complete report to the signed-in user only.
     */
    public function sendTest(User $user, Project $project, string $frequency): void
    {
        $this->authorize($user, $project);
        if (!$this->mailer->isConfigured()) {
            throw new HttpException(409, 'E-mail is not set up yet. A system administrator can set it up under System → E-mail.');
        }
        if (!$this->rateLimiter->hit('reports:test:user:' . $user->id, 5, 3600)->allowed) {
            throw new HttpException(429, 'Too many test reports. Try again later.');
        }
        $frequency = $frequency === ReportSchedule::MONTHLY ? ReportSchedule::MONTHLY : ReportSchedule::WEEKLY;
        $period = ReportPeriod::latestComplete($frequency, $this->clock->now(), $project->timezone);
        $this->scheduler->send($project->workspaceId, $project->publicId, $frequency, $period->toString(), [$user->email], 'test');
    }

    /**
     * Cron (hourly): queues every enabled report whose next period is due.
     *
     * @return int reports queued
     */
    public function dispatchDue(): int
    {
        if (!$this->mailer->isConfigured()) {
            return 0;
        }
        $queued = 0;
        foreach ($this->schedules->enabled() as ['schedule' => $schedule, 'projectPublicId' => $publicId]) {
            $project = $this->projects->findInWorkspace($schedule->workspaceId, $publicId);
            if ($project === null) {
                continue;
            }
            $period = ReportPeriod::latestSendable($schedule->frequency, $this->clock->now(), $project->timezone);
            if (ReportPeriod::key($period) === $schedule->lastPeriod) {
                continue;
            }
            $this->scheduler->send($project->workspaceId, $project->publicId, $schedule->frequency, $period->toString(), $schedule->recipients, 'scheduled');
            ++$queued;
        }

        return $queued;
    }

    /**
     * Job: builds and e-mails one report. A scheduled period is marked sent
     * after the attempt, so mail errors are logged instead of retried hourly.
     *
     * @param list<string> $recipients
     */
    public function send(int $workspaceId, string $projectPublicId, string $frequency, string $periodTo, array $recipients, string $kind): void
    {
        $project = $this->projects->findInWorkspace($workspaceId, $projectPublicId);
        $period = ReportPeriod::endingOn($frequency, $periodTo);
        if ($project === null || $period === null || $recipients === []) {
            return;
        }
        $key = ReportPeriod::key($period);
        if ($kind === 'scheduled' && $this->schedules->find($project->id)?->lastPeriod === $key) {
            return; // already sent (duplicate job)
        }
        $report = $this->builder->build($project, $frequency, $period);
        $subject = ReportText::subject($report);
        $url = $this->reportUrl($project, $frequency, $period->toString());
        $brand = $this->customization->brand($project);
        $sections = $this->customization->sections($project);
        $body = ReportText::body($report, $url, $brand, $sections);
        $html = $this->views->render('emails/report', ['report' => $report, 'url' => $url, 'reportBranding' => $brand, 'sections' => $sections]);
        $attachments = [];
        try {
            $attachments[] = new Attachment(ReportPdf::fileName($report), 'application/pdf', $this->renderPdf($project, $report, $frequency));
        } catch (\Throwable $e) {
            // The e-mail still goes out with the online report link.
            $this->logger->warning('Report PDF could not be created', ['project_id' => $project->id, 'error' => $e->getMessage()]);
        }

        $failed = 0;
        $error = null;
        foreach ($recipients as $recipient) {
            try {
                $this->mailer->send(new Message($recipient, $subject, $body, null, $html, $attachments));
            } catch (MailException|\InvalidArgumentException $e) {
                ++$failed;
                $error = mb_substr($e->getMessage(), 0, 500);
                $this->logger->warning('Report e-mail failed', ['project_id' => $project->id, 'error' => $e->getMessage()]);
            }
        }
        $this->schedules->logDelivery($project->id, $key, $kind, count($recipients), $failed, $error);
        if ($kind === 'scheduled') {
            $this->schedules->markSent($project->id, $key);
        }
    }

    public function reportUrl(Project $project, string $frequency, string $periodTo): string
    {
        return rtrim($this->appUrl, '/') . '/projects/' . $project->publicId . '/reports/view?' . http_build_query(['frequency' => $frequency, 'to' => $periodTo]);
    }

    private function authorize(User $user, Project $project): void
    {
        $this->authorizer->authorize($user->subject(), Permission::ProjectsEdit, $project->workspaceId, $project->id);
    }
}
