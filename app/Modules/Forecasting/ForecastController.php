<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\Forecasting;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use SeoControlCenter\Core\Authorization\Permission;
use SeoControlCenter\Core\Http\Exception\HttpException;
use SeoControlCenter\Core\Http\ResponseFactory;
use SeoControlCenter\Core\View\ViewRenderer;
use SeoControlCenter\Domain\Projects\ProjectService;
use SeoControlCenter\Modules\Shared\Http\WebContext;

final readonly class ForecastController
{
    public function __construct(private ProjectService $projects, private ForecastService $forecasts, private ResponseFactory $responses, private ViewRenderer $views) {}
    public function index(ServerRequestInterface $request): ResponseInterface
    {
        $project = $this->projects->get(WebContext::user($request), WebContext::workspace($request)->workspace(), WebContext::routeParam($request, 'project'));
        return $this->responses->html($this->views->render('forecasts/index', ['project' => $project, 'report' => $this->forecasts->overview($project, $this->horizon($request)), 'canEdit' => $this->projects->can(WebContext::user($request), Permission::ProjectsEdit, $project)], $request))->withHeader('Cache-Control', 'no-store');
    }
    public function capture(ServerRequestInterface $request): ResponseInterface
    {
        $project = $this->projects->get(WebContext::user($request), WebContext::workspace($request)->workspace(), WebContext::routeParam($request, 'project'));
        $horizon = $this->horizon($request);
        $eligible = $this->forecasts->capture(WebContext::user($request), $project, $horizon, \SeoControlCenter\Core\Support\ClientInfo::fromRequest($request));
        WebContext::session($request)->flash('status', $eligible > 0 ? 'Eligible forecasts saved; weekly tracking enabled. Existing predictions for this date are preserved.' : 'No predictions meet the data requirements yet. Weekly tracking enabled to check stored history as it grows.');
        return $this->responses->redirect('/projects/' . $project->publicId . '/forecasts?horizon=' . $horizon);
    }
    private function horizon(ServerRequestInterface $request): int
    {
        $data = $request->getMethod() === 'POST' ? (array) $request->getParsedBody() : $request->getQueryParams();
        $value = filter_var($data['horizon'] ?? 14, FILTER_VALIDATE_INT);
        if (!in_array($value, [7, 14, 28], true)) {
            throw new HttpException(422, 'Choose a 7, 14 or 28-day forecast.');
        }
        return $value;
    }
    public function calendar(ServerRequestInterface $request): ResponseInterface
    {
        $project = $this->projects->get(WebContext::user($request), WebContext::workspace($request)->workspace(), WebContext::routeParam($request, 'project'));
        $input = (array) $request->getParsedBody();
        $this->forecasts->addEvent(WebContext::user($request), $project, is_string($input['name'] ?? null) ? $input['name'] : '', is_string($input['date'] ?? null) ? $input['date'] : '', \SeoControlCenter\Core\Support\ClientInfo::fromRequest($request));
        WebContext::session($request)->flash('status', 'Calendar occurrence recorded. Earlier backtests cannot use a date SEOcc learned later.');
        return $this->responses->redirect('/projects/' . $project->publicId . '/forecasts');
    }
    public function tracking(ServerRequestInterface $request): ResponseInterface
    {
        $project = $this->projects->get(WebContext::user($request), WebContext::workspace($request)->workspace(), WebContext::routeParam($request, 'project'));
        $input = (array) $request->getParsedBody();
        if (!in_array($input['enabled'] ?? null, ['0', '1'], true)) {
            throw new HttpException(422, 'Choose whether tracking is enabled.');
        }
        $this->forecasts->setTracking(WebContext::user($request), $project, $input['enabled'] === '1', \SeoControlCenter\Core\Support\ClientInfo::fromRequest($request));
        return $this->responses->redirect('/projects/' . $project->publicId . '/forecasts');
    }
    public function excludeEvent(ServerRequestInterface $request): ResponseInterface
    {
        $project = $this->projects->get(WebContext::user($request), WebContext::workspace($request)->workspace(), WebContext::routeParam($request, 'project'));
        $input = (array) $request->getParsedBody();
        $this->forecasts->excludeEvent(WebContext::user($request), $project, is_string($input['name'] ?? null) ? $input['name'] : '', is_string($input['date'] ?? null) ? $input['date'] : '', \SeoControlCenter\Core\Support\ClientInfo::fromRequest($request));
        return $this->responses->redirect('/projects/' . $project->publicId . '/forecasts');
    }
}
