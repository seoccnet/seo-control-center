<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\IndexNow\Controllers;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use SeoControlCenter\Core\Authorization\Permission;
use SeoControlCenter\Core\Http\Exception\HttpException;
use SeoControlCenter\Core\Http\Exception\NotFoundHttpException;
use SeoControlCenter\Core\Http\ResponseFactory;
use SeoControlCenter\Core\Support\ClientInfo;
use SeoControlCenter\Core\View\ViewRenderer;
use SeoControlCenter\Domain\Projects\Project;
use SeoControlCenter\Domain\Projects\ProjectService;
use SeoControlCenter\Domain\Shared\ValidationException;
use SeoControlCenter\Modules\IndexNow\IndexNowService;
use SeoControlCenter\Modules\Shared\Http\WebContext;

/**
 * Project settings → IndexNow: key file, sitemap watching, manual submissions.
 */
final readonly class IndexNowController
{
    public function __construct(
        private IndexNowService $service,
        private ProjectService $projects,
        private ResponseFactory $responses,
        private ViewRenderer $views,
        private string $appRoot,
    ) {}

    public function show(ServerRequestInterface $request): ResponseInterface
    {
        return $this->page($request, $this->load($request), [], [], 200);
    }

    public function setUp(ServerRequestInterface $request): ResponseInterface
    {
        $project = $this->load($request);
        $this->service->setUp(WebContext::user($request), $project, ClientInfo::fromRequest($request));
        WebContext::session($request)->flash('status', 'Key created. Put the key file on your site, then press Check key file.');

        return $this->back($project);
    }

    public function verify(ServerRequestInterface $request): ResponseInterface
    {
        $project = $this->load($request);
        $path = WebContext::body($request)['key_path'] ?? '';
        $path = is_string($path) ? $path : '';
        try {
            $location = $this->service->verify(WebContext::user($request), $project, $path);
        } catch (ValidationException $e) {
            return $this->page($request, $project, $e->errors(), ['key_path' => $path], 422);
        }
        WebContext::session($request)->flash('status', sprintf('Key file found at %s. IndexNow is ready.', $location));

        return $this->back($project);
    }

    public function sitemap(ServerRequestInterface $request): ResponseInterface
    {
        $project = $this->load($request);
        $body = WebContext::body($request);
        $url = is_string($body['sitemap_url'] ?? null) ? $body['sitemap_url'] : '';
        $auto = ($body['auto_submit'] ?? null) === '1';
        try {
            $this->service->saveSitemap(WebContext::user($request), $project, $url, $auto, ClientInfo::fromRequest($request));
        } catch (ValidationException $e) {
            return $this->page($request, $project, $e->errors(), ['sitemap_url' => $url], 422);
        }
        WebContext::session($request)->flash('status', $auto
            ? 'Saved. The first check records the URLs in the sitemap; from then on, new and changed URLs are submitted automatically.'
            : 'Saved. Automatic submissions are off.');

        return $this->back($project);
    }

    public function submit(ServerRequestInterface $request): ResponseInterface
    {
        $project = $this->load($request);
        $text = WebContext::body($request)['urls'] ?? '';
        try {
            $count = $this->service->submitUrls(WebContext::user($request), $project, is_string($text) ? $text : '', ClientInfo::fromRequest($request));
        } catch (ValidationException $e) {
            return $this->page($request, $project, $e->errors(), ['urls' => is_string($text) ? $text : ''], 422);
        }
        WebContext::session($request)->flash('status', sprintf('%d URL%s queued for IndexNow. The result appears below after the next cron run.', $count, $count === 1 ? '' : 's'));

        return $this->back($project);
    }

    public function wordpressPlugin(ServerRequestInterface $request): ResponseInterface
    {
        $project = $this->load($request);
        if (!$this->projects->can(WebContext::user($request), Permission::IntegrationsManage, $project)) {
            throw new HttpException(403, 'You cannot manage IndexNow for this project.');
        }
        $path = $this->appRoot . '/resources/integrations/seocc.zip';
        if (!is_file($path)) {
            throw new NotFoundHttpException();
        }
        return $this->responses->download($path, 'seocc-0.3.1.zip', 'application/zip')
            ->withHeader('X-Content-Type-Options', 'nosniff');
    }

    /**
     * The key file to upload to the site's root ({key}.txt containing the key).
     */
    public function keyFile(ServerRequestInterface $request): ResponseInterface
    {
        $project = $this->load($request);
        if (!$this->projects->can(WebContext::user($request), Permission::IntegrationsManage, $project)) {
            throw new HttpException(403, 'You cannot manage IndexNow for this project.');
        }
        $settings = $this->service->page($project)['settings'] ?? throw new NotFoundHttpException();

        return $this->responses->text($settings->key)
            ->withHeader('Content-Disposition', 'attachment; filename="' . $settings->fileName() . '"')
            ->withHeader('Cache-Control', 'no-store');
    }

    /**
     * @param array<string, string> $errors
     * @param array<string, string> $old
     */
    private function page(ServerRequestInterface $request, Project $project, array $errors, array $old, int $status): ResponseInterface
    {
        return $this->responses->html($this->views->render('integrations/project-indexnow', [
            'project' => $project,
            ...$this->service->page($project),
            'canManage' => $this->projects->can(WebContext::user($request), Permission::IntegrationsManage, $project),
            'errors' => $errors,
            'old' => $old,
        ], $request), $status);
    }

    private function back(Project $project): ResponseInterface
    {
        return $this->responses->redirect('/projects/' . $project->publicId . '/indexnow');
    }

    private function load(ServerRequestInterface $request): Project
    {
        return $this->projects->get(WebContext::user($request), WebContext::workspace($request)->workspace(), WebContext::routeParam($request, 'project'));
    }
}
