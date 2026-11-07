<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\SerpHistory;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use SeoControlCenter\Core\Http\ResponseFactory;
use SeoControlCenter\Core\View\ViewRenderer;
use SeoControlCenter\Domain\Projects\ProjectService;
use SeoControlCenter\Domain\SerpHistory\SerpChangeDetector;
use SeoControlCenter\Modules\Shared\Http\WebContext;

/**
 * Rankings → Changes: what changed on the tracked results pages.
 */
final readonly class SerpHistoryController
{
    public function __construct(private ProjectService $projects, private SerpHistoryService $history, private ResponseFactory $responses, private ViewRenderer $views) {}

    public function show(ServerRequestInterface $request): ResponseInterface
    {
        $project = $this->projects->get(WebContext::user($request), WebContext::workspace($request)->workspace(), WebContext::routeParam($request, 'project'));
        $query = $request->getQueryParams();
        $filters = self::filters($query);
        $page = is_string($query['page'] ?? null) && ctype_digit($query['page']) ? max(1, min(1000, (int) $query['page'])) : 1;
        $data = $this->history->overview($project, $filters, ($page - 1) * SerpHistoryService::PER_PAGE);

        return $this->responses->html($this->views->render('serp-history/index', ['project' => $project, ...$data, 'filters' => $filters, 'page' => $page, 'query' => $query], $request))->withHeader('Cache-Control', 'no-store');
    }

    /**
     * @param array<string, mixed> $query
     * @return array{kinds?: list<string>, q?: string, subject?: string, from?: string, to?: string}
     */
    public static function filters(array $query): array
    {
        $filters = [];
        $kind = $query['kind'] ?? '';
        $groups = ['features' => [SerpChangeDetector::FEATURE_ADDED, SerpChangeDetector::FEATURE_REMOVED], 'competitors' => [SerpChangeDetector::COMPETITOR_ENTERED, SerpChangeDetector::COMPETITOR_LEFT, SerpChangeDetector::NEW_URL], 'yours' => [SerpChangeDetector::OWN_ENTERED, SerpChangeDetector::OWN_LEFT, SerpChangeDetector::OWN_MOVED]];
        if (is_string($kind) && isset($groups[$kind])) {
            $filters['kinds'] = $groups[$kind];
        } elseif (is_string($kind) && in_array($kind, SerpChangeDetector::KINDS, true)) {
            $filters['kinds'] = [$kind];
        }
        foreach (['q', 'subject'] as $key) {
            if (is_string($query[$key] ?? null) && trim($query[$key]) !== '') {
                $filters[$key] = mb_substr(trim($query[$key]), 0, 100);
            }
        }
        foreach (['from', 'to'] as $key) {
            if (is_string($query[$key] ?? null) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $query[$key]) === 1) {
                $filters[$key] = $query[$key];
            }
        }

        return $filters;
    }
}
