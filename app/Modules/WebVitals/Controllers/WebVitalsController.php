<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\WebVitals\Controllers;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use SeoControlCenter\Core\Authorization\Authorizer;
use SeoControlCenter\Core\Authorization\Permission;
use SeoControlCenter\Core\Http\ResponseFactory;
use SeoControlCenter\Core\Support\ClientInfo;
use SeoControlCenter\Core\View\ViewRenderer;
use SeoControlCenter\Domain\Projects\ProjectService;
use SeoControlCenter\Modules\Shared\Http\WebContext;
use SeoControlCenter\Modules\WebVitals\WebVitalsRepository;
use SeoControlCenter\Modules\WebVitals\WebVitalsService;

final readonly class WebVitalsController
{
    public function __construct(
        private WebVitalsService $service,
        private WebVitalsRepository $data,
        private ProjectService $projects,
        private Authorizer $auth,
        private ResponseFactory $responses,
        private ViewRenderer $views
    ) {}
    public function show(ServerRequestInterface $request): ResponseInterface
    {
        $workspace = WebContext::workspace($request)->workspace();
        $this->auth->authorize(WebContext::user($request)->subject(), Permission::IntegrationsManage, $workspace->id);
        return $this->responses->html($this->views->render('web-vitals/settings', ['configured' => $this->data->hasKey($workspace->id)], $request))->withHeader('Cache-Control', 'no-store');
    }
    public function save(ServerRequestInterface $request): ResponseInterface
    {
        $body = WebContext::body($request);
        $this->service->saveKey(WebContext::user($request), WebContext::workspace($request)->workspace()->id, is_string($body['api_key'] ?? null) ? $body['api_key'] : '', ClientInfo::fromRequest($request));
        WebContext::session($request)->flash('status', 'CrUX settings saved. The next requested check verifies the key.');
        return $this->responses->redirect('/integrations/web-vitals');
    }
    public function fetch(ServerRequestInterface $request): ResponseInterface
    {
        $project = $this->projects->get(WebContext::user($request), WebContext::workspace($request)->workspace(), WebContext::routeParam($request, 'project'));
        $body = WebContext::body($request);
        $this->service->request(WebContext::user($request), $project, is_string($body['url'] ?? null) ? $body['url'] : '', ClientInfo::fromRequest($request), is_string($body['form_factor'] ?? null) ? $body['form_factor'] : 'PHONE');
        WebContext::session($request)->flash('status', 'Web Vitals check queued. The result appears after cron runs.');
        return $this->responses->redirect('/projects/' . $project->publicId . '/pages/details?url=' . rawurlencode((string) $body['url']));
    }
    public function watch(ServerRequestInterface $request): ResponseInterface
    {
        $project = $this->projects->get(WebContext::user($request), WebContext::workspace($request)->workspace(), WebContext::routeParam($request, 'project'));
        $body = WebContext::body($request);
        $url = is_string($body['url'] ?? null) ? $body['url'] : '';
        $this->service->watch(WebContext::user($request), $project, $url, is_string($body['form_factor'] ?? null) ? $body['form_factor'] : 'PHONE', ($body['enabled'] ?? null) === '1', ClientInfo::fromRequest($request));
        WebContext::session($request)->flash('status', 'Weekly field-data refresh setting saved.');
        return $this->responses->redirect('/projects/' . $project->publicId . '/pages/details?url=' . rawurlencode($url));
    }
}
