<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\SiteAudit;

use SeoControlCenter\Core\Support\Clock;
use SeoControlCenter\Domain\Projects\Project;
use SeoControlCenter\Domain\Rankings\RankingRepository;
use SeoControlCenter\Domain\Snippets\TitleRewrite;

/**
 * Titles Google rewrites (1.7.0): the title Google showed for each ranking
 * page in the rank checks of the last 90 days, next to the page's own title
 * from the latest completed audit. Stored data only; nothing is bought.
 */
final readonly class TitleRewriteService
{
    public const int DAYS = 90;
    public const int ROWS = 300;
    private const int READ = 5000;
    private const array ORDER = [TitleRewrite::REWRITTEN => 0, TitleRewrite::MISSING => 1, TitleRewrite::SITE_NAME => 2, TitleRewrite::CUT => 3, TitleRewrite::KEPT => 4];

    public function __construct(
        private RankingRepository $rankings,
        private SiteAuditRepository $audits,
        private Clock $clock,
    ) {}

    /**
     * @return array{
     *     audited: bool, titles: int, pages: int, unaudited: int,
     *     counts: array<string, int>,
     *     rows: list<array{url: string, own: ?string, shown: string, kind: string, overlap: float, keywords: list<string>, checkedOn: string}>
     * }
     */
    public function report(Project $project, ?string $kind = null): array
    {
        $audit = $this->audits->latest($project->id, 'completed');
        $own = [];
        if ($audit !== null) {
            foreach ($this->audits->allPages($audit->id) as $page) {
                if ($page->state === 'done' && $page->isHtml() && ($page->statusCode ?? 0) < 300) {
                    $own[self::key($page->url)] = $page->title;
                }
            }
        }

        $since = $this->clock->now()->modify('-' . self::DAYS . ' days')->format('Y-m-d');
        // One row per page and shown title: Google can show different titles for different searches.
        $grouped = [];
        foreach ($this->rankings->shownTitles($project->id, $this->rankings->settings($project->id)->device, $since, self::READ) as $row) {
            $id = $row['url'] . "\n" . $row['title'];
            $grouped[$id] ??= ['url' => $row['url'], 'title' => $row['title'], 'keywords' => [], 'checkedOn' => $row['checkedOn']];
            $grouped[$id]['keywords'][] = $row['keyword'];
            $grouped[$id]['checkedOn'] = max($grouped[$id]['checkedOn'], $row['checkedOn']);
        }

        $counts = array_fill_keys(array_keys(self::ORDER), 0);
        $rows = [];
        $pages = [];
        $unaudited = [];
        foreach ($grouped as $group) {
            $key = self::key($group['url']);
            if (!array_key_exists($key, $own)) {
                $unaudited[$key] = true;
                continue;
            }
            $pages[$key] = true;
            $comparison = TitleRewrite::compare($own[$key], $group['title']);
            ++$counts[$comparison['kind']];
            if ($comparison['kind'] === TitleRewrite::KEPT || ($kind !== null && $comparison['kind'] !== $kind)) {
                continue;
            }
            sort($group['keywords']);
            $rows[] = [
                'url' => $group['url'],
                'own' => $own[$key],
                'shown' => $group['title'],
                'kind' => $comparison['kind'],
                'overlap' => $comparison['overlap'],
                'keywords' => array_slice($group['keywords'], 0, 5),
                'checkedOn' => $group['checkedOn'],
            ];
        }
        usort($rows, static fn(array $a, array $b): int => [self::ORDER[$a['kind']], $a['overlap'], $a['url']] <=> [self::ORDER[$b['kind']], $b['overlap'], $b['url']]);

        return [
            'audited' => $audit !== null,
            'titles' => array_sum($counts),
            'pages' => count($pages),
            'unaudited' => count($unaudited),
            'counts' => $counts,
            'rows' => array_slice($rows, 0, self::ROWS),
        ];
    }

    /** Scheme and host in lower case, no fragment, no trailing slash after a path. */
    public static function key(string $url): string
    {
        $parts = parse_url($url);
        if ($parts === false || !isset($parts['host'])) {
            return $url;
        }
        $path = $parts['path'] ?? '/';
        $path = $path === '/' ? '/' : rtrim($path, '/');

        return strtolower(($parts['scheme'] ?? 'https') . '://' . $parts['host']) . (isset($parts['port']) ? ':' . $parts['port'] : '') . $path . (isset($parts['query']) ? '?' . $parts['query'] : '');
    }
}
