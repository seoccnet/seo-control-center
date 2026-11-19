<?php

declare(strict_types=1);

use SeoControlCenter\Modules\Rankings\TargetPageService;

/**
 * Target page per keyword (1.8.0).
 *
 * @var SeoControlCenter\Core\View\Template $view
 * @var SeoControlCenter\Domain\Projects\Project $project
 * @var string $filter
 * @var bool $canEdit
 * @var array<string, int> $counts
 * @var list<array{id: string, keyword: string, volume: ?int, checkedOn: string, position: ?int, url: ?string, target: ?string, status: string, switches: int, pages: list<string>}> $rows
 */

$base = '/projects/' . $project->publicId . '/rankings';
$view->extend('layouts/app', ['title' => 'Target pages · ' . $project->name, 'crumbs' => [['/projects', 'Projects'], ['/projects/' . $project->publicId, $project->name], [$base, 'Rankings'], [null, 'Target pages']]]);
echo $view->partial('partials/project-header', ['project' => $project, 'active' => 'rankings']);
$labels = [TargetPageService::OTHER => 'Another page ranks', TargetPageService::NOT_RANKING => 'Not ranking', TargetPageService::NO_TARGET => 'No target', TargetPageService::MATCH => 'Target ranks'];
$tone = [TargetPageService::OTHER => 'badge badge-error', TargetPageService::NOT_RANKING => 'badge badge-warning', TargetPageService::NO_TARGET => 'badge', TargetPageService::MATCH => 'badge badge-ok'];
$filters = ['all' => 'All', TargetPageService::OTHER => 'Another page ranks', TargetPageService::SWITCHING => 'Switching pages', TargetPageService::NOT_RANKING => 'Not ranking', TargetPageService::NO_TARGET => 'No target', TargetPageService::MATCH => 'Target ranks'];
$path = static function (?string $url): string {
    if ($url === null) {
        return '–';
    }
    $p = (string) parse_url($url, PHP_URL_PATH);
    $q = parse_url($url, PHP_URL_QUERY);

    return ($p !== '' ? $p : '/') . (is_string($q) ? '?' . $q : '');
};
$total = array_sum(array_intersect_key($counts, $labels));
?>
<div class="improve-workspace">
<header class="improve-intro"><div><p class="portfolio-eyebrow"><a href="<?= $view->e($base) ?>">Rankings</a></p><h2>Target pages</h2><p class="muted">Choose the page that should rank for each keyword. SEOcc compares it with the page Google ranks in the latest check and shows keywords for which Google keeps switching between your pages in the last <?= $view->e((string) TargetPageService::DAYS) ?> days. When another page ranks, strengthen the target (internal links with the keyword, a clearer title) or merge the two pages.</p></div></header>

<?php if ($total === 0): ?>
<section class="card empty">
    <h2>No checked keywords yet</h2>
    <p>Target pages are compared with your rank checks. Track keywords and run a check first.</p>
    <p><a class="button button-secondary" href="<?= $view->e($base) ?>">Rankings</a></p>
</section>
<?php else: ?>
<div class="stat-grid">
    <section class="stat"><h2 class="stat-label">Another page ranks</h2><p class="stat-value"><?= $view->e(number_format($counts[TargetPageService::OTHER])) ?></p></section>
    <section class="stat"><h2 class="stat-label">Switching pages</h2><p class="stat-value"><?= $view->e(number_format($counts[TargetPageService::SWITCHING])) ?></p><p class="stat-note"><?= $view->e((string) TargetPageService::SWITCHES) ?> or more changes</p></section>
    <section class="stat"><h2 class="stat-label">Target ranks</h2><p class="stat-value"><?= $view->e(number_format($counts[TargetPageService::MATCH])) ?></p></section>
    <section class="stat"><h2 class="stat-label">No target yet</h2><p class="stat-value"><?= $view->e(number_format($counts[TargetPageService::NO_TARGET])) ?></p></section>
</div>
<?php if ($canEdit && $counts[TargetPageService::NO_TARGET] > 0): ?>
<form method="post" action="<?= $view->e($base . '/target-pages') ?>" class="inline-form"><?= $view->csrfField() ?><input type="hidden" name="action" value="adopt"><button class="button button-secondary button-small">Use today's ranking page as the target of every keyword without one</button></form>
<?php endif; ?>

<section class="card" aria-labelledby="targets-heading">
    <h2 id="targets-heading">Keywords</h2>
    <nav class="filter-row" aria-label="Filter keywords">
        <?php foreach ($filters as $key => $label): ?>
            <a class="chip" href="<?= $view->e($base . '/target-pages' . ($key === 'all' ? '' : '?filter=' . $key)) ?>"<?= $filter === $key ? ' aria-current="true"' : '' ?>><?= $view->e($label) ?><?= $key !== 'all' ? ' <span class="muted">' . $view->e(number_format($counts[$key] ?? 0)) . '</span>' : '' ?></a>
        <?php endforeach; ?>
    </nav>
    <?php if ($rows === []): ?>
    <p class="muted">No keywords match this filter.</p>
    <?php else: ?>
    <div class="table-scroll"><table class="table table-dense">
        <thead><tr><th scope="col">Keyword</th><th scope="col">Status</th><th scope="col" class="numeric">Position</th><th scope="col">Ranking page</th><th scope="col">Target page</th><th scope="col" class="numeric">Page changes</th></tr></thead>
        <tbody>
        <?php foreach ($rows as $row): ?>
            <tr>
                <td><a href="<?= $view->e($base . '/' . $row['id']) ?>"><?= $view->e($row['keyword']) ?></a><?= $row['volume'] !== null ? '<br><span class="muted small">' . $view->e(number_format($row['volume'])) . ' searches</span>' : '' ?></td>
                <td><span class="<?= $view->e($tone[$row['status']]) ?>"><?= $view->e($labels[$row['status']]) ?></span></td>
                <td class="numeric"><?= $row['position'] !== null ? $view->e((string) $row['position']) : '–' ?></td>
                <td class="small"><?php if ($row['url'] !== null): ?><a href="<?= $view->e($row['url']) ?>" rel="noopener noreferrer" target="_blank"><?= $view->e($path($row['url'])) ?></a><?php else: ?>–<?php endif; ?></td>
                <td class="small">
                    <?php if ($canEdit): ?>
                    <form method="post" action="<?= $view->e($base . '/target-pages') ?>" class="inline-form"><?= $view->csrfField() ?><input type="hidden" name="keyword" value="<?= $view->e($row['id']) ?>"><input type="hidden" name="filter" value="<?= $view->e($filter) ?>">
                        <label class="visually-hidden" for="target-<?= $view->e($row['id']) ?>">Target page for <?= $view->e($row['keyword']) ?></label>
                        <input id="target-<?= $view->e($row['id']) ?>" name="url" type="text" size="28" value="<?= $view->e($row['target'] ?? '') ?>" placeholder="<?= $view->e($row['url'] !== null ? $path($row['url']) : '/page') ?>">
                        <button class="button button-ghost button-small">Save</button>
                    </form>
                    <?php else: ?><?= $view->e($path($row['target'])) ?><?php endif; ?>
                </td>
                <td class="numeric"><?php if ($row['switches'] > 0): ?><span title="<?= $view->e(implode(', ', array_map($path, $row['pages']))) ?>"><?= $view->e((string) $row['switches']) ?></span><?php if ($row['switches'] >= TargetPageService::SWITCHES): ?> <span class="badge badge-warning">Switching</span><?php endif; ?><?php else: ?>0<?php endif; ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table></div>
    <p class="muted small">Enter a full address or a path such as /page; leave the field empty and save to remove the target. Page changes: how often the page Google ranks for the keyword changed between checks in the last <?= $view->e((string) TargetPageService::DAYS) ?> days. Pages that take turns usually compete for the same search.</p>
    <?php endif; ?>
</section>
<?php endif; ?>
</div>
