<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\Analytics\Controllers;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use SeoControlCenter\Core\Http\ResponseFactory;
use SeoControlCenter\Core\Support\ClientInfo;
use SeoControlCenter\Domain\Projects\ProjectService;
use SeoControlCenter\Modules\Analytics\BusinessMeasurementService;
use SeoControlCenter\Modules\Shared\Http\WebContext;

/** Business events on the Analytics page (ADR-042): define, remove, test check. */
final readonly class BusinessEventsController
{
    public function __construct(
        private BusinessMeasurementService $service,
        private ProjectService $projects,
        private ResponseFactory $responses,
    ) {}

    public function add(ServerRequestInterface $request): ResponseInterface
    {
        $project = $this->projects->get(WebContext::user($request), WebContext::workspace($request)->workspace(), WebContext::routeParam($request, 'project'));
        $body = WebContext::body($request);
        $event = $this->service->define(WebContext::user($request), $project, self::text($body, 'kind'), self::text($body, 'event_name'), self::text($body, 'label'), ClientInfo::fromRequest($request));
        WebContext::session($request)->flash('status', 'Business event "' . $event->eventName . '" saved. The next GA4 import checks whether GA4 records it.');

        return $this->back($project->publicId);
    }

    public function remove(ServerRequestInterface $request): ResponseInterface
    {
        $project = $this->projects->get(WebContext::user($request), WebContext::workspace($request)->workspace(), WebContext::routeParam($request, 'project'));
        $this->service->remove(WebContext::user($request), $project, WebContext::routeParam($request, 'event'), ClientInfo::fromRequest($request));
        WebContext::session($request)->flash('status', 'Business event removed with its imported counts.');

        return $this->back($project->publicId);
    }

    public function check(ServerRequestInterface $request): ResponseInterface
    {
        $project = $this->projects->get(WebContext::user($request), WebContext::workspace($request)->workspace(), WebContext::routeParam($request, 'project'));
        $this->service->requestCheck(WebContext::user($request), $project, self::text(WebContext::body($request), 'note'), ClientInfo::fromRequest($request));
        WebContext::session($request)->flash('status', 'Test check queued. SEOcc reads GA4\'s last 30 minutes at the next cron run; refresh this page in a few minutes.');

        return $this->back($project->publicId);
    }

    private function back(string $project): ResponseInterface
    {
        return $this->responses->redirect('/projects/' . $project . '/analytics#business-measurement');
    }

    /** @param array<string, mixed> $body */
    private static function text(array $body, string $key): string
    {
        return is_string($body[$key] ?? null) ? $body[$key] : '';
    }
}
