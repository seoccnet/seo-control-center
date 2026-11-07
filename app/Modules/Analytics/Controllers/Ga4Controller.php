<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\Analytics\Controllers;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use SeoControlCenter\Core\Authorization\Authorizer;
use SeoControlCenter\Core\Authorization\Permission;
use SeoControlCenter\Core\Http\ResponseFactory;
use SeoControlCenter\Core\Support\ClientInfo;
use SeoControlCenter\Core\Support\Clock;
use SeoControlCenter\Core\View\ViewRenderer;
use SeoControlCenter\Domain\Projects\ProjectService;
use SeoControlCenter\Domain\Shared\DateRange;
use SeoControlCenter\Modules\Analytics\Ga4Repository;
use SeoControlCenter\Modules\Analytics\Ga4Service;
use SeoControlCenter\Modules\Shared\Http\WebContext;

final readonly class Ga4Controller
{
    public function __construct(
        private Ga4Service $service,
        private Ga4Repository $data,
        private ProjectService $projects,
        private Authorizer $auth,
        private Clock $clock,
        private ResponseFactory $responses,
        private ViewRenderer $views,
        private ?\SeoControlCenter\Modules\Analytics\BusinessMeasurementService $measurement = null,
    ) {}
    public function settings(ServerRequestInterface $request): ResponseInterface
    {
        $workspace = WebContext::workspace($request)->workspace();
        $this->auth->authorize(WebContext::user($request)->subject(), Permission::IntegrationsManage, $workspace->id);
        return $this->responses->html($this->views->render('analytics/settings', ['configured' => $this->data->hasCredentials($workspace->id)], $request))->withHeader('Cache-Control', 'no-store');
    }
    public function save(ServerRequestInterface $request): ResponseInterface
    {
        $body = WebContext::body($request);
        $this->service->saveCredentials(WebContext::user($request), WebContext::workspace($request)->workspace()->id, is_string($body['credentials'] ?? null) ? $body['credentials'] : '', ClientInfo::fromRequest($request));
        WebContext::session($request)->flash('status', 'GA4 credentials saved. The next import verifies property access.');
        return $this->responses->redirect('/integrations/ga4');
    }
    public function show(ServerRequestInterface $request): ResponseInterface
    {
        $project = $this->projects->get(WebContext::user($request), WebContext::workspace($request)->workspace(), WebContext::routeParam($request, 'project'));
        $metadata = $this->data->metadata($project->id);
        $query = $request->getQueryParams();
        $range = DateRange::endingOn($metadata['to'] ?? $this->clock->now()->modify('-1 day')->format('Y-m-d'), 28);
        if (isset($query['from']) || isset($query['to'])) {
            try {
                $range = new DateRange(is_string($query['from'] ?? null) ? $query['from'] : '', is_string($query['to'] ?? null) ? $query['to'] : '');
            } catch (\InvalidArgumentException) {
                throw new \SeoControlCenter\Core\Http\Exception\HttpException(422, 'Enter valid start and end dates.');
            }
            if ($range->days() > 365) {
                throw new \SeoControlCenter\Core\Http\Exception\HttpException(422, 'Choose a period of at most 365 days.');
            }
        }
        $previous = $range->previous();
        $metadata = $this->data->metadata($project->id, $range);
        $previousMetadata = $this->data->metadata($project->id, $previous);
        $rows = $this->data->landingPages($project->id, $range, 100);
        $previousRows = $rows === [] ? [] : $this->data->landingPages($project->id, $previous, 100, urls: array_column($rows, 'url'));
        $previousByUrl = array_column($previousRows, null, 'url');
        return $this->responses->html($this->views->render('analytics/index', ['project' => $project, 'propertyId' => $this->data->propertyId($project->id),
            'canManage' => $this->projects->can(WebContext::user($request), Permission::IntegrationsManage, $project), 'metadata' => $metadata, 'previousMetadata' => $previousMetadata,
            'range' => $range, 'previous' => $previous, 'covered' => $this->data->coveredDays($project->id, $range), 'previousCovered' => $this->data->coveredDays($project->id, $previous), 'previousByUrl' => $previousByUrl,
            'rows' => $rows, 'measurement' => $this->measurement?->overview($project),
            'canEditProject' => $this->projects->can(WebContext::user($request), Permission::ProjectsEdit, $project)], $request))->withHeader('Cache-Control', 'no-store');
    }
    public function link(ServerRequestInterface $request): ResponseInterface
    {
        $project = $this->projects->get(WebContext::user($request), WebContext::workspace($request)->workspace(), WebContext::routeParam($request, 'project'));
        $body = WebContext::body($request);
        $this->service->link(WebContext::user($request), $project, is_string($body['property_id'] ?? null) ? $body['property_id'] : '', ClientInfo::fromRequest($request));
        WebContext::session($request)->flash('status', 'GA4 property linked; import queued.');
        return $this->responses->redirect('/projects/' . $project->publicId . '/analytics');
    }
    public function import(ServerRequestInterface $request): ResponseInterface
    {
        $project = $this->projects->get(WebContext::user($request), WebContext::workspace($request)->workspace(), WebContext::routeParam($request, 'project'));
        $body = WebContext::body($request);
        if (isset($body['days']) && (!is_string($body['days']) || !ctype_digit($body['days']))) {
            throw new \SeoControlCenter\Core\Http\Exception\HttpException(422, 'Choose a valid import period.');
        }
        $days = isset($body['days']) ? (int) $body['days'] : 28;
        $this->service->request(WebContext::user($request), $project, ClientInfo::fromRequest($request), $days);
        WebContext::session($request)->flash('status', 'GA4 import queued. Check System → Jobs if it fails.');
        return $this->responses->redirect('/projects/' . $project->publicId . '/analytics');
    }
}
