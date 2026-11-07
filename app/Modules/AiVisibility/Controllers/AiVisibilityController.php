<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\AiVisibility\Controllers;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use SeoControlCenter\Core\Http\ResponseFactory;
use SeoControlCenter\Core\View\ViewRenderer;
use SeoControlCenter\Domain\Projects\ProjectService;
use SeoControlCenter\Modules\AiVisibility\AiCrawlerService;
use SeoControlCenter\Modules\AiVisibility\AiVisibilityService;
use SeoControlCenter\Modules\Analytics\AiReferralService;
use SeoControlCenter\Modules\Research\ResearchService;
use SeoControlCenter\Modules\Shared\Http\WebContext;

/**
 * Project → AI visibility.
 */
final readonly class AiVisibilityController
{
    public function __construct(
        private AiVisibilityService $service,
        private ResearchService $research,
        private ProjectService $projects,
        private ResponseFactory $responses,
        private ViewRenderer $views,
        private \SeoControlCenter\Modules\Research\BrandMentionService $brands,
        private ?AiCrawlerService $crawlers = null,
        private ?AiReferralService $referrals = null,
    ) {}

    public function show(ServerRequestInterface $request): ResponseInterface
    {
        return $this->page($request);
    }
    public function saveBrand(ServerRequestInterface $request): ResponseInterface
    {
        $project = $this->projects->get(WebContext::user($request), WebContext::workspace($request)->workspace(), WebContext::routeParam($request, 'project'));
        try {
            $this->brands->save(WebContext::user($request), $project, WebContext::body($request), \SeoControlCenter\Core\Support\ClientInfo::fromRequest($request));
        } catch (\SeoControlCenter\Domain\Shared\ValidationException $e) {
            return $this->page($request, 422, $e->errors(), WebContext::body($request));
        }
        WebContext::session($request)->flash('status', 'Brand settings saved. No paid check was started by saving. Weekly checks run only when explicitly enabled.');
        return $this->responses->redirect('/projects/' . $project->publicId . '/ai-visibility#brand-mentions');
    }
    public function fetchBrand(ServerRequestInterface $request): ResponseInterface
    {
        $project = $this->projects->get(WebContext::user($request), WebContext::workspace($request)->workspace(), WebContext::routeParam($request, 'project'));
        $this->brands->request(WebContext::user($request), $project, \SeoControlCenter\Core\Support\ClientInfo::fromRequest($request));
        WebContext::session($request)->flash('status', 'Paid brand check queued. Cron runs one request at a time; results appear here when all four requests finish.');
        return $this->responses->redirect('/projects/' . $project->publicId . '/ai-visibility#brand-mentions');
    }
    public function checkCrawlers(ServerRequestInterface $request): ResponseInterface
    {
        $project = $this->projects->get(WebContext::user($request), WebContext::workspace($request)->workspace(), WebContext::routeParam($request, 'project'));
        if ($this->crawlers === null) {
            throw new \SeoControlCenter\Core\Http\Exception\HttpException(404, 'Not found.');
        }
        $this->crawlers->request(WebContext::user($request), $project, \SeoControlCenter\Core\Support\ClientInfo::fromRequest($request));
        WebContext::session($request)->flash('status', 'Crawler check queued. It reads robots.txt and llms.txt of your site for free; the result appears here within a few minutes.');
        return $this->responses->redirect('/projects/' . $project->publicId . '/ai-visibility#ai-crawlers');
    }
    /**
     * @param array<string, string> $errors
     * @param array<string, mixed> $old */
    private function page(ServerRequestInterface $request, int $status = 200, array $errors = [], array $old = []): ResponseInterface
    {
        $project = $this->projects->get(WebContext::user($request), WebContext::workspace($request)->workspace(), WebContext::routeParam($request, 'project'));
        $query = $request->getQueryParams();
        $all = ($query['show'] ?? null) === 'all';
        $page = is_string($query['page'] ?? null) && ctype_digit($query['page']) ? max(1, min(10000, (int) $query['page'])) : 1;

        return $this->responses->html($this->views->render('ai-visibility/index', [
            'project' => $project,
            'showAll' => $all,
            'page' => $page,
            ...$this->service->page($project, !$all, $page),
            'assistants' => $this->research->assistants(WebContext::user($request), $project),
            'brands' => $this->brands->page($project), 'brandErrors' => $errors, 'brandOld' => $old,
            'brandCanEdit' => $this->projects->can(WebContext::user($request), \SeoControlCenter\Core\Authorization\Permission::ProjectsEdit, $project),
            'brandCanRun' => $this->projects->can(WebContext::user($request), \SeoControlCenter\Core\Authorization\Permission::AnalysisExecute, $project),
            'crawlerCheck' => $this->crawlers?->latest($project),
            'crawlersAvailable' => $this->crawlers !== null,
            'citation' => $this->service->citationPages($project),
            'referrals' => $this->referrals?->page($project),
        ], $request), $status)->withHeader('Cache-Control', 'no-store');
    }
}
