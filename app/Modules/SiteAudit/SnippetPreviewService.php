<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\SiteAudit;

use SeoControlCenter\Domain\Projects\Project;
use SeoControlCenter\Domain\Snippets\SnippetWidth;

/**
 * Search snippet preview (1.6.0): every indexable page of the latest
 * completed audit with its title and description measured in pixels, the
 * ones Google cuts first, and a form to try a new title and description.
 */
final readonly class SnippetPreviewService
{
    public const int PAGES = 300;

    public function __construct(private SiteAuditRepository $audits) {}

    /**
     * @param array{url: string, title: string, description: string}|null $trial a title and description to try
     * @return array{
     *     audited: bool, total: int, cut: int,
     *     rows: list<array{url: string, title: ?array{text: string, width: float, limit: int, fits: bool, shown: string}, description: ?array{text: string, width: float, limit: int, fits: bool, shown: string}}>,
     *     trial: ?array{url: string, title: array{text: string, width: float, limit: int, fits: bool, shown: string}, description: array{text: string, width: float, limit: int, fits: bool, shown: string}}
     * }
     */
    public function preview(Project $project, bool $cutOnly, ?array $trial): array
    {
        $audit = $this->audits->latest($project->id, 'completed');
        $rows = [];
        $total = $cut = 0;
        if ($audit !== null) {
            foreach ($this->audits->allPages($audit->id) as $page) {
                if ($page->state !== 'done' || !$page->isHtml() || ($page->statusCode ?? 0) >= 300 || $page->noindex) {
                    continue;
                }
                $total++;
                $title = $page->title !== null && trim($page->title) !== '' ? ['text' => $page->title, ...SnippetWidth::title($page->title)] : null;
                $description = $page->description !== null && trim($page->description) !== '' ? ['text' => $page->description, ...SnippetWidth::description($page->description)] : null;
                $isCut = ($title !== null && !$title['fits']) || ($description !== null && !$description['fits']);
                $cut += $isCut ? 1 : 0;
                if ($cutOnly && !$isCut) {
                    continue;
                }
                $rows[] = ['url' => $page->url, 'title' => $title, 'description' => $description];
            }
        }
        // The widest titles first: they lose the most.
        usort($rows, static fn(array $a, array $b): int => [($b['title']['width'] ?? 0) / SnippetWidth::TITLE_PX, $a['url']] <=> [($a['title']['width'] ?? 0) / SnippetWidth::TITLE_PX, $b['url']]);

        return [
            'audited' => $audit !== null,
            'total' => $total,
            'cut' => $cut,
            'rows' => array_slice($rows, 0, self::PAGES),
            'trial' => $trial === null ? null : [
                'url' => $trial['url'],
                'title' => ['text' => $trial['title'], ...SnippetWidth::title($trial['title'])],
                'description' => ['text' => $trial['description'], ...SnippetWidth::description($trial['description'])],
            ],
        ];
    }
}
