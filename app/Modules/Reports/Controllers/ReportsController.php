<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\Reports\Controllers;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use SeoControlCenter\Core\Authorization\Permission;
use SeoControlCenter\Core\Http\ResponseFactory;
use SeoControlCenter\Core\Support\ClientInfo;
use SeoControlCenter\Core\Support\Clock;
use SeoControlCenter\Core\View\ViewRenderer;
use SeoControlCenter\Domain\Projects\Project;
use SeoControlCenter\Domain\Projects\ProjectService;
use SeoControlCenter\Domain\Shared\ValidationException;
use SeoControlCenter\Modules\Reports\ReportBuilder;
use SeoControlCenter\Modules\Reports\ReportPeriod;
use SeoControlCenter\Modules\Reports\ReportSchedule;
use SeoControlCenter\Modules\Reports\ReportService;
use SeoControlCenter\Modules\Shared\Http\WebContext;

/**
 * Project → Reports: the e-mail schedule, test sends, the delivery log, the
 * printable report and its PDF download.
 */
final readonly class ReportsController
{
    public function __construct(
        private ReportService $service,
        private ReportBuilder $builder,
        private ProjectService $projects,
        private ResponseFactory $responses,
        private ViewRenderer $views,
        private Clock $clock,
        private \SeoControlCenter\Modules\Reports\ReportCustomization $customization,
    ) {}

    public function show(ServerRequestInterface $request): ResponseInterface
    {
        return $this->page($request, $this->load($request), [], [], 200);
    }

    public function save(ServerRequestInterface $request): ResponseInterface
    {
        $project = $this->load($request);
        $body = WebContext::body($request);
        $frequency = is_string($body['frequency'] ?? null) ? $body['frequency'] : '';
        $recipients = is_string($body['recipients'] ?? null) ? $body['recipients'] : '';
        $enabled = ($body['enabled'] ?? null) === '1';
        try {
            $this->service->save(WebContext::user($request), $project, $frequency, $recipients, $enabled, ClientInfo::fromRequest($request));
        } catch (ValidationException $e) {
            return $this->page($request, $project, $e->errors(), ['frequency' => $frequency, 'recipients' => $recipients], 422);
        }
        WebContext::session($request)->flash('status', $enabled ? 'Report schedule saved.' : 'Report schedule saved; it is paused.');

        return $this->responses->redirect('/projects/' . $project->publicId . '/reports');
    }

    public function test(ServerRequestInterface $request): ResponseInterface
    {
        $project = $this->load($request);
        $user = WebContext::user($request);
        $frequency = WebContext::body($request)['frequency'] ?? ReportSchedule::WEEKLY;
        $this->service->sendTest($user, $project, is_string($frequency) ? $frequency : ReportSchedule::WEEKLY);
        WebContext::session($request)->flash('status', sprintf('A test report is on its way to %s (with the next cron run).', $user->email));

        return $this->responses->redirect('/projects/' . $project->publicId . '/reports');
    }

    /**
     * The printable report (?frequency=weekly|monthly&to=YYYY-MM-DD; default: the latest complete week).
     */
    public function view(ServerRequestInterface $request): ResponseInterface
    {
        $project = $this->load($request);
        [$frequency, $period, $latest] = $this->period($request, $project);
        $earlier = ReportPeriod::before($frequency, $period);

        return $this->responses->html($this->views->render('reports/view', [
            'report' => $this->builder->build($project, $frequency, $period),
            'reportBranding' => $this->customization->brand($project), 'sections' => $this->customization->sections($project), 'reportLogoUrl' => $this->customization->logo($project) !== null ? '/projects/' . $project->publicId . '/reports/logo' : null,
            'earlierUrl' => '?' . http_build_query(['frequency' => $frequency, 'to' => $earlier->toString()]),
            'pdfUrl' => '/projects/' . $project->publicId . '/reports/pdf?' . http_build_query(['frequency' => $frequency, 'to' => $period->toString()]),
            'laterUrl' => $period->toString() < $latest->toString()
                ? '?' . http_build_query(['frequency' => $frequency, 'to' => ReportPeriod::endingOn($frequency, self::nextEnd($frequency, $period->toString()))?->toString() ?? $latest->toString()])
                : null,
        ], $request))->withHeader('Cache-Control', 'no-store');
    }

    /**
     * The same report as a PDF download (same parameters as view()).
     */
    public function pdf(ServerRequestInterface $request): ResponseInterface
    {
        $project = $this->load($request);
        [$frequency, $period] = $this->period($request, $project);
        $pdf = $this->service->pdf($project, $frequency, $period);

        return $this->responses->text($pdf['content'])
            ->withHeader('Content-Type', 'application/pdf')
            ->withHeader('Content-Disposition', 'attachment; filename="' . $pdf['fileName'] . '"')
            ->withHeader('Cache-Control', 'no-store')
            ->withHeader('X-Content-Type-Options', 'nosniff');
    }

    /**
     * The requested report period (?frequency=weekly|monthly&to=YYYY-MM-DD),
     * never later than the latest complete one.
     *
     * @return array{0: string, 1: \SeoControlCenter\Domain\Shared\DateRange, 2: \SeoControlCenter\Domain\Shared\DateRange}
     */
    private function period(ServerRequestInterface $request, Project $project): array
    {
        $query = $request->getQueryParams();
        $frequency = ($query['frequency'] ?? '') === ReportSchedule::MONTHLY ? ReportSchedule::MONTHLY : ReportSchedule::WEEKLY;
        $period = is_string($query['to'] ?? null) ? ReportPeriod::endingOn($frequency, $query['to']) : null;
        $latest = ReportPeriod::latestComplete($frequency, $this->clock->now(), $project->timezone);
        if ($period === null || $period->toString() > $latest->toString()) {
            $period = $latest;
        }

        return [$frequency, $period, $latest];
    }

    /**
     * @param array<string, string> $errors
     * @param array<string, string> $old
     */
    private function page(ServerRequestInterface $request, Project $project, array $errors, array $old, int $status): ResponseInterface
    {
        return $this->responses->html($this->views->render('reports/index', [
            'project' => $project,
            ...$this->service->page($project),
            'canManage' => $this->projects->can(WebContext::user($request), Permission::ProjectsEdit, $project),
            'canBrand' => $this->customization->canBrand(WebContext::user($request), $project), 'reportBranding' => $this->customization->settingsBrand($project), 'sections' => $this->customization->sections($project), 'hasWorkspaceLogo' => $this->customization->logo($project) !== null,
            'errors' => $errors,
            'old' => $old,
        ], $request), $status);
    }

    private static function nextEnd(string $frequency, string $to): string
    {
        $end = new \DateTimeImmutable($to);

        return $frequency === ReportSchedule::MONTHLY
            ? $end->modify('first day of next month')->modify('last day of this month')->format('Y-m-d')
            : $end->modify('+7 days')->format('Y-m-d');
    }
    public function sections(ServerRequestInterface $request): ResponseInterface
    {
        return $this->customize($request, false);
    }
    public function branding(ServerRequestInterface $request): ResponseInterface
    {
        return $this->customize($request, true);
    }
    private function customize(ServerRequestInterface $request, bool $brand): ResponseInterface
    {
        $project = $this->load($request);
        try {
            if ($brand) {
                $this->customization->saveBrand(WebContext::user($request), $project, WebContext::body($request), ClientInfo::fromRequest($request));
            } else {
                $this->customization->saveSections(WebContext::user($request), $project, WebContext::body($request), ClientInfo::fromRequest($request));
            }
        } catch (ValidationException $e) {
            return $this->page($request, $project, $e->errors(), [], 422);
        }
        WebContext::session($request)->flash('status', 'Report customization saved. It applies to printable and future emailed reports.');
        return $this->responses->redirect('/projects/' . $project->publicId . '/reports');
    }
    public function uploadLogo(ServerRequestInterface $request): ResponseInterface
    {
        $project = $this->load($request);
        if (!$this->customization->canBrand(WebContext::user($request), $project)) {
            throw new \SeoControlCenter\Core\Http\Exception\HttpException(403, 'You cannot change workspace report branding.');
        }
        try {
            $body = null;
            if ((WebContext::body($request)['remove_logo'] ?? null) !== '1') {
                $file = $request->getUploadedFiles()['logo'] ?? null;
                if (!$file instanceof \Psr\Http\Message\UploadedFileInterface || $file->getError() !== UPLOAD_ERR_OK) {
                    throw new ValidationException(['logo' => 'Choose an image to upload.']);
                }
                $body = $file->getStream()->read(524289);
            }
            $this->customization->saveLogo(WebContext::user($request), $project, $body, ClientInfo::fromRequest($request));
        } catch (ValidationException $e) {
            return $this->page($request, $project, $e->errors(), [], 422);
        }
        return $this->responses->redirect('/projects/' . $project->publicId . '/reports');
    }
    public function logo(ServerRequestInterface $request): ResponseInterface
    {
        $logo = $this->customization->logo($this->load($request)) ?? throw new \SeoControlCenter\Core\Http\Exception\HttpException(404, 'No workspace logo.');
        return $this->responses->text($logo['body'])->withHeader('Content-Type', $logo['type'])->withHeader('Cache-Control', 'no-store')->withHeader('X-Content-Type-Options', 'nosniff')->withHeader('Content-Security-Policy', "default-src 'none'");
    }
    public function stylesheet(ServerRequestInterface $request): ResponseInterface
    {
        $brand = $this->customization->brand($this->load($request));
        return $this->responses->text(str_replace(':root, :root[data-theme]', '.report', \SeoControlCenter\Modules\Branding\BrandingService::stylesheet($brand)))->withHeader('Content-Type', 'text/css; charset=utf-8')->withHeader('Cache-Control', 'no-store');
    }

    private function load(ServerRequestInterface $request): Project
    {
        return $this->projects->get(WebContext::user($request), WebContext::workspace($request)->workspace(), WebContext::routeParam($request, 'project'));
    }
}
