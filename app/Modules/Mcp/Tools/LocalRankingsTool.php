<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\Mcp\Tools;

use SeoControlCenter\Domain\Local\LocalResult;
use SeoControlCenter\Domain\Mcp\McpScope;
use SeoControlCenter\Modules\Local\LocalRepository;
use SeoControlCenter\Modules\Mcp\Server\McpTool;
use SeoControlCenter\Modules\Mcp\Server\ToolContext;

final readonly class LocalRankingsTool implements McpTool
{
    public function __construct(private LocalRepository $local, private \SeoControlCenter\Modules\Local\LocationRepository $locations, private ?\SeoControlCenter\Modules\Local\LocalService $service = null) {}

    public function name(): string
    {
        return 'local_rankings';
    }

    public function title(): string
    {
        return 'Local rankings';
    }

    public function description(): string
    {
        return 'Local SEO of the project: the business, the Google Maps position of each local keyword on the latest check (with the previous position and the top 3 businesses with rating and reviews), the latest ranking grid per keyword, and the Business Profile (rating, reviews, category, claimed) with its rating history. Reads stored checks; it starts none.';
    }

    public function scope(): McpScope
    {
        return McpScope::Rankings;
    }

    public function inputSchema(): array
    {
        return Present::projectSchema(['location_id' => ['type' => 'string', 'description' => 'Exact branch ID, or primary. Omit for a compact overview.'], 'include_reviews' => ['type' => 'boolean', 'default' => false], 'include_grids' => ['type' => 'boolean', 'default' => false]]);
    }

    public function call(ToolContext $context, array $arguments): array
    {
        $project = $context->project($arguments);
        $locationId = $arguments['location_id'] ?? null;
        if ($locationId !== null && (!is_string($locationId) || ($locationId !== 'primary' && $this->locations->find($project->id, $locationId) === null))) {
            throw new \SeoControlCenter\Modules\Mcp\Server\ToolError('Location not found in this project.');
        }
        $includeReviews = ($arguments['include_reviews'] ?? false) === true;
        $includeGrids = ($arguments['include_grids'] ?? false) === true;
        $locations = array_values(array_filter($this->locations->locations($project->id), static fn(\SeoControlCenter\Modules\Local\LocalLocation $l): bool => $locationId === null || $l->publicId === $locationId));
        $branches = array_map(function (\SeoControlCenter\Modules\Local\LocalLocation $l) use ($project, $includeReviews, $includeGrids): array {
            $state = $this->locations->reviews($project->id, $l->publicId);
            $items = ($state['cid'] ?? null) === $l->cid && is_array($state['items'] ?? null) ? $state['items'] : [];
            return ['location_id' => $l->publicId, 'name' => $l->name, 'cid' => $l->cid, 'coordinates' => $l->area->label(), 'language' => $l->language, 'weekly' => $l->weekly, 'history' => array_slice($this->locations->history($project->id, $l->publicId), 0, 12), 'reviews' => $includeReviews ? array_slice($items, 0, 20) : [], 'review_sample_count' => count($items), 'reviews_fetched_at' => $state['fetched_at'] ?? null, 'grids' => $includeGrids ? array_map(static fn(\SeoControlCenter\Modules\Local\BranchGrid $g): array => ['grid_id' => $g->publicId, 'keyword' => $g->keyword, 'checked_on' => $g->grid->checkedOn, 'status' => $g->grid->status, 'size' => $g->grid->size, 'spacing_m' => $g->grid->spacing, 'average_position' => $g->grid->averagePosition(), 'points_found' => $g->grid->found(), 'positions' => $g->grid->rows()], array_slice($this->locations->grids($project->id, $l->publicId), 0, 12)) : [], 'history_limit' => 12];
        }, $locations);
        $settings = $this->local->settings($project->id);
        if ($settings === null) {
            return ['project_id' => $project->publicId, 'business' => null, 'locations' => $branches, 'note' => 'Local SEO is not set up for this project.'];
        }
        $date = $this->local->latestDate($project->id);
        $previousDate = $date !== null ? $this->local->dateBefore($project->id, $date) : null;
        $current = $date !== null ? $this->local->rankings($project->id, $date) : [];
        $previous = $previousDate !== null ? $this->local->rankings($project->id, $previousDate) : [];
        $grids = $this->local->latestGrids($project->id);
        [$nap, $reviews, $categories] = $this->service?->insightsFor($project) ?? [null, null, null];

        return [
            'project_id' => $project->publicId,
            'locations' => $branches,
            'primary_reviews' => $includeReviews && ($this->locations->reviews($project->id, 'primary')['cid'] ?? null) === $settings->cid ? array_slice($this->locations->reviews($project->id, 'primary')['items'] ?? [], 0, 20) : [],
            'business' => ['name' => $settings->businessName, 'location' => $settings->area->label(), 'zoom' => $settings->area->zoom, 'language' => $settings->language, 'weekly_check' => $settings->weekly],
            'check_date' => $date,
            'previous_check_date' => $previousDate,
            'keywords' => array_map(static fn(array $k): array => [
                'keyword' => $k['keyword'],
                'position' => $current[$k['id']]['position'] ?? null,
                'previous_position' => $previous[$k['id']]['position'] ?? null,
                'top3' => array_map(static fn(LocalResult $r): array => ['position' => $r->position, 'business' => $r->title, 'rating' => $r->rating, 'reviews' => $r->votes], array_slice($current[$k['id']]['top'] ?? [], 0, 3)),
                'grid' => isset($grids[$k['id']]) ? [
                    'checked_on' => $grids[$k['id']]->checkedOn,
                    'size' => $grids[$k['id']]->size,
                    'spacing_m' => $grids[$k['id']]->spacing,
                    'average_position' => $grids[$k['id']]->averagePosition(),
                    'points_found' => $grids[$k['id']]->found(),
                    'positions' => $grids[$k['id']]->rows(),
                ] : null,
            ], $this->local->keywords($project->id)),
            'business_profile' => array_map(static fn(array $row): array => [
                'checked_on' => $row['date'],
                'name' => $row['profile']->title,
                'category' => $row['profile']->category,
                'rating' => $row['profile']->rating,
                'reviews' => $row['profile']->votes,
                'claimed' => $row['profile']->claimed,
            ], $this->local->profiles($project->id, 12)),
            // The Local page's checks (0.46.0), read from stored data (0.48.0).
            'profile_vs_website' => $nap === null ? null : ['fields' => $nap->fields, 'website_states' => $nap->site, 'page_url' => $nap->pageUrl, 'note' => $nap->isKnown() ? 'Compared with the latest completed site audit.' : 'Unknown until a site audit records business details.'],
            'review_handling' => $reviews === null ? null : ['reviews_read' => $reviews->count, 'average_rating' => $reviews->average, 'owner_replies' => $reviews->replied, 'reply_share' => round($reviews->replyShare(), 3), 'critical' => $reviews->critical, 'critical_without_reply' => $reviews->unansweredCritical, 'answer_first' => $reviews->unanswered, 'new_per_month' => $reviews->perMonth, 'own_reviews' => $reviews->ownVotes, 'local_pack_average_reviews' => $reviews->leaderVotes !== null ? round($reviews->leaderVotes, 1) : null, 'imported_on' => $reviews->fetchedOn, 'note' => 'A sample of up to 100 of the newest reviews.'],
            'local_pack_categories' => $categories === null || $categories->categories === [] ? null : ['own' => $categories->own, 'categories' => $categories->categories, 'leading' => $categories->leading(), 'gap' => $categories->hasGap()],
        ];
    }
}
