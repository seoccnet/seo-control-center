<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\ContentInventory\Controllers;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use SeoControlCenter\Core\Http\ResponseFactory;
use SeoControlCenter\Core\Support\ClientInfo;
use SeoControlCenter\Core\View\ViewRenderer;
use SeoControlCenter\Domain\ContentInventory\Inventory;
use SeoControlCenter\Domain\Projects\Project;
use SeoControlCenter\Domain\Projects\ProjectService;
use SeoControlCenter\Modules\ContentInventory\ContentInventoryService;
use SeoControlCenter\Modules\Shared\Http\WebContext;

/** Research → Content inventory (ADR-042). */
final readonly class ContentInventoryController
{
    public function __construct(
        private ProjectService $projects,
        private ContentInventoryService $inventory,
        private ResponseFactory $responses,
        private ViewRenderer $views,
    ) {}

    public function index(ServerRequestInterface $request): ResponseInterface
    {
        $project = $this->project($request);
        $query = $request->getQueryParams();
        $filters = [
            'state' => is_string($query['state'] ?? null) && isset(Inventory::STATES[$query['state']]) ? $query['state'] : '',
            'source' => is_string($query['source'] ?? null) && isset(Inventory::SOURCES[$query['source']]) ? $query['source'] : '',
            'q' => is_string($query['q'] ?? null) ? mb_substr($query['q'], 0, 200) : '',
        ];
        $page = is_string($query['page'] ?? null) && ctype_digit($query['page']) ? max(1, min(1000, (int) $query['page'])) : 1;
        $data = $this->inventory->overview(WebContext::user($request), $project, $filters, ($page - 1) * ContentInventoryService::PER_PAGE);

        return $this->responses->html($this->views->render('inventory/index', ['project' => $project, 'data' => $data, 'filters' => $filters, 'page' => $page], $request))->withHeader('Cache-Control', 'no-store');
    }

    public function add(ServerRequestInterface $request): ResponseInterface
    {
        $project = $this->project($request);
        $item = $this->inventory->add(WebContext::user($request), $project, WebContext::body($request), ClientInfo::fromRequest($request));
        WebContext::session($request)->flash('status', 'Saved "' . $item->title . '" to the inventory.');

        return $this->back($project);
    }

    public function import(ServerRequestInterface $request): ResponseInterface
    {
        $project = $this->project($request);
        $body = WebContext::body($request);
        $result = $this->inventory->importCsv(WebContext::user($request), $project, is_string($body['csv'] ?? null) ? $body['csv'] : '', ClientInfo::fromRequest($request));
        $message = sprintf('Imported %d new and updated %d CMS records.', $result['added'], $result['updated']);
        if ($result['skipped'] !== []) {
            $message .= ' Skipped ' . count($result['skipped']) . ': ' . implode(' ', $result['skipped']);
        }
        WebContext::session($request)->flash('status', $message);

        return $this->back($project);
    }

    public function update(ServerRequestInterface $request): ResponseInterface
    {
        $project = $this->project($request);
        $this->inventory->update(WebContext::user($request), $project, WebContext::routeParam($request, 'item'), WebContext::body($request), ClientInfo::fromRequest($request));
        WebContext::session($request)->flash('status', 'Record updated.');

        return $this->back($project);
    }

    public function link(ServerRequestInterface $request): ResponseInterface
    {
        $project = $this->project($request);
        $body = WebContext::body($request);
        $url = is_string($body['url'] ?? null) && trim($body['url']) !== '' ? trim($body['url']) : null;
        $version = is_string($body['version'] ?? null) && ctype_digit($body['version']) ? (int) $body['version'] : 0;
        $this->inventory->link(WebContext::user($request), $project, WebContext::routeParam($request, 'item'), $url, $version, ClientInfo::fromRequest($request));
        WebContext::session($request)->flash('status', $url !== null ? 'Linked the record to ' . $url . '.' : 'Removed the link.');

        return $this->back($project);
    }

    public function remove(ServerRequestInterface $request): ResponseInterface
    {
        $project = $this->project($request);
        $this->inventory->remove(WebContext::user($request), $project, WebContext::routeParam($request, 'item'), ClientInfo::fromRequest($request));
        WebContext::session($request)->flash('status', 'Record removed. Pages from crawls and Search Console are not affected.');

        return $this->back($project);
    }

    private function project(ServerRequestInterface $request): Project
    {
        return $this->projects->get(WebContext::user($request), WebContext::workspace($request)->workspace(), WebContext::routeParam($request, 'project'));
    }

    private function back(Project $project): ResponseInterface
    {
        return $this->responses->redirect('/projects/' . $project->publicId . '/inventory');
    }
}
