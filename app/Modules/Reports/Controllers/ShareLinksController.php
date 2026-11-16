<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\Reports\Controllers;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use SeoControlCenter\Core\Http\Exception\HttpException;
use SeoControlCenter\Core\Http\ResponseFactory;
use SeoControlCenter\Core\RateLimit\RateLimiter;
use SeoControlCenter\Core\Support\ClientInfo;
use SeoControlCenter\Core\Support\Clock;
use SeoControlCenter\Core\View\ViewRenderer;
use SeoControlCenter\Domain\Projects\Project;
use SeoControlCenter\Domain\Projects\ProjectService;
use SeoControlCenter\Domain\Shared\ValidationException;
use SeoControlCenter\Modules\Reports\ReportBuilder;
use SeoControlCenter\Modules\Reports\ReportCustomization;
use SeoControlCenter\Modules\Reports\ReportPeriod;
use SeoControlCenter\Modules\Reports\ReportSchedule;
use SeoControlCenter\Modules\Reports\ReportService;
use SeoControlCenter\Modules\Reports\ShareLinkService;
use SeoControlCenter\Modules\Shared\Http\WebContext;

/**
 * Read-only client links (1.3.0): creating and revoking them on the
 * reports page, and the public report behind a link. Public responses are
 * never cached, never indexed and send no referrer, so the token in the
 * address does not leak to other sites.
 */
final readonly class ShareLinksController
{
    private const int VIEWS_PER_MINUTE = 60;

    public function __construct(
        private ShareLinkService $links,
        private ProjectService $projects,
        private ReportBuilder $builder,
        private ReportService $reports,
        private ReportCustomization $customization,
        private RateLimiter $rateLimiter,
        private ResponseFactory $responses,
        private ViewRenderer $views,
        private Clock $clock,
        private string $appUrl,
    ) {}

    public function create(ServerRequestInterface $request): ResponseInterface
    {
        $project = $this->project($request);
        $back = '/projects/' . $project->publicId . '/reports#client-links';
        try {
            $created = $this->links->create(WebContext::user($request), $project, (array) $request->getParsedBody(), ClientInfo::fromRequest($request));
        } catch (ValidationException $e) {
            WebContext::session($request)->flash('error', implode(' ', $e->errors()));

            return $this->responses->redirect($back);
        }
        WebContext::session($request)->flash('report_link', $this->appUrl . $created['url']);
        WebContext::session($request)->flash('status', 'Client link created. Copy it now: it is shown only once.');

        return $this->responses->redirect($back);
    }

    public function revoke(ServerRequestInterface $request): ResponseInterface
    {
        $project = $this->project($request);
        $this->links->revoke(WebContext::user($request), $project, WebContext::routeParam($request, 'link'), ClientInfo::fromRequest($request));
        WebContext::session($request)->flash('status', 'Link revoked. It no longer opens the report.');

        return $this->responses->redirect('/projects/' . $project->publicId . '/reports#client-links');
    }

    /** Public: the report behind a link. */
    public function view(ServerRequestInterface $request): ResponseInterface
    {
        ['link' => $link, 'project' => $project, 'token' => $token] = $this->open($request);
        [$frequency, $period, $latest] = $this->period($request, $project);
        $earlier = ReportPeriod::before($frequency, $period);
        $this->links->recordView($link);
        $base = '/share/' . $token;

        return $this->public($this->responses->html($this->views->render('reports/view', [
            'report' => $this->builder->build($project, $frequency, $period),
            'reportBranding' => $this->customization->brand($project),
            'sections' => $this->customization->sections($project),
            'reportLogoUrl' => $this->customization->logo($project) !== null ? $base . '/logo' : null,
            'reportStylesheetUrl' => $base . '/branding.css',
            'shared' => true,
            'earlierUrl' => '?' . http_build_query(['frequency' => $frequency, 'to' => $earlier->toString()]),
            'pdfUrl' => $base . '/pdf?' . http_build_query(['frequency' => $frequency, 'to' => $period->toString()]),
            'laterUrl' => $period->toString() < $latest->toString()
                ? '?' . http_build_query(['frequency' => $frequency, 'to' => ReportPeriod::endingOn($frequency, self::nextEnd($frequency, $period->toString()))?->toString() ?? $latest->toString()])
                : null,
        ], $request)));
    }

    /** Public: the same report as a PDF. */
    public function pdf(ServerRequestInterface $request): ResponseInterface
    {
        ['project' => $project] = $this->open($request);
        [$frequency, $period] = $this->period($request, $project);
        $pdf = $this->reports->pdf($project, $frequency, $period);

        return $this->public($this->responses->text($pdf['content'])
            ->withHeader('Content-Type', 'application/pdf')
            ->withHeader('Content-Disposition', 'attachment; filename="' . $pdf['fileName'] . '"')
            ->withHeader('X-Content-Type-Options', 'nosniff'));
    }

    /** Public: the report logo. */
    public function logo(ServerRequestInterface $request): ResponseInterface
    {
        ['project' => $project] = $this->open($request);
        $logo = $this->customization->logo($project) ?? throw new HttpException(404, 'No logo.');

        return $this->public($this->responses->text($logo['body'])->withHeader('Content-Type', $logo['type'])->withHeader('X-Content-Type-Options', 'nosniff')->withHeader('Content-Security-Policy', "default-src 'none'"));
    }

    /** Public: the report's brand colours. */
    public function stylesheet(ServerRequestInterface $request): ResponseInterface
    {
        ['project' => $project] = $this->open($request);
        $brand = $this->customization->brand($project);

        return $this->public($this->responses->text(str_replace(':root, :root[data-theme]', '.report', \SeoControlCenter\Modules\Branding\BrandingService::stylesheet($brand)))->withHeader('Content-Type', 'text/css; charset=utf-8'));
    }

    /**
     * @return array{link: \SeoControlCenter\Modules\Reports\ShareLink, project: Project, token: string}
     */
    private function open(ServerRequestInterface $request): array
    {
        $ip = ClientInfo::fromRequest($request)->ip ?? 'unknown';
        if (!$this->rateLimiter->hit('report-link:ip:' . $ip, self::VIEWS_PER_MINUTE, 60)->allowed) {
            throw new HttpException(429, 'Too many requests. Try again in a minute.');
        }
        $token = WebContext::routeParam($request, 'token');

        return [...$this->links->open($token), 'token' => $token];
    }

    private function public(ResponseInterface $response): ResponseInterface
    {
        return $response
            ->withHeader('Cache-Control', 'no-store, private')
            ->withHeader('X-Robots-Tag', 'noindex, nofollow')
            ->withHeader('Referrer-Policy', 'no-referrer');
    }

    /**
     * The requested report period, never later than the latest complete one.
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

    /** The end of the period after the one ending on $to (as the report page computes it). */
    private static function nextEnd(string $frequency, string $to): string
    {
        $end = new \DateTimeImmutable($to);

        return $frequency === ReportSchedule::MONTHLY
            ? $end->modify('first day of next month')->modify('last day of this month')->format('Y-m-d')
            : $end->modify('+7 days')->format('Y-m-d');
    }

    private function project(ServerRequestInterface $request): Project
    {
        return $this->projects->get(WebContext::user($request), WebContext::workspace($request)->workspace(), WebContext::routeParam($request, 'project'));
    }
}
