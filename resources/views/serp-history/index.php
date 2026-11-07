<?php

declare(strict_types=1);

/**
 * Rankings → Changes (ADR-039, E1): results-page change history.
 *
 * @var SeoControlCenter\Core\View\Template $view
 * @var SeoControlCenter\Domain\Projects\Project $project
 * @var string $device
 * @var list<SeoControlCenter\Domain\SerpHistory\SerpEvent> $events
 * @var int $total
 * @var array<string, int> $counts
 * @var array<string, array{new_urls: int, entered: int, left: int}> $competitors
 * @var string $since
 * @var array<string, mixed> $filters
 * @var int $page
 * @var array<string, mixed> $query
 */

use SeoControlCenter\Domain\SerpHistory\SerpChangeDetector as D;
use SeoControlCenter\Domain\SerpHistory\SerpEventText;

$base = '/projects/' . $project->publicId . '/rankings/changes';
$view->extend('layouts/app', ['title' => 'Results-page changes · ' . $project->name]);
echo $view->partial('partials/project-header', ['project' => $project, 'active' => 'rankings']);
$q = static fn(string $key): string => is_string($query[$key] ?? null) ? $query[$key] : '';
$tiles = [
    ['Features', ($counts[D::FEATURE_ADDED] ?? 0) + ($counts[D::FEATURE_REMOVED] ?? 0), 'features'],
    ['Competitors', ($counts[D::COMPETITOR_ENTERED] ?? 0) + ($counts[D::COMPETITOR_LEFT] ?? 0) + ($counts[D::NEW_URL] ?? 0), 'competitors'],
    ['Your positions', ($counts[D::OWN_ENTERED] ?? 0) + ($counts[D::OWN_LEFT] ?? 0) + ($counts[D::OWN_MOVED] ?? 0), 'yours'],
    ['Page types', $counts[D::FORMAT_SHIFT] ?? 0, D::FORMAT_SHIFT],
];
$pages = max(1, (int) ceil($total / 50));
$link = static fn(int $p): string => $base . '?' . http_build_query(array_filter([...array_intersect_key($query, array_flip(['kind', 'q', 'subject', 'from', 'to'])), 'page' => $p > 1 ? $p : null], static fn(mixed $v): bool => $v !== null && $v !== ''));
?>
<div class="research-workspace">
<header class="research-intro"><div><p class="portfolio-eyebrow">Research · Rankings · Changes</p><h2>Results-page changes</h2><p class="muted">What changed on the results pages of your tracked keywords between two checks on the <?= $view->e($device) ?> device, at most 14 days apart: features such as the AI Overview, competitors entering or leaving the top 10, new URLs, your own movement and the kind of page Google ranks. From your stored rank checks; nothing is bought.</p></div><nav class="research-shortcuts" aria-label="Rankings"><a href="<?= $view->e('/projects/' . $project->publicId . '/rankings') ?>">Rankings</a><a href="<?= $view->e('/projects/' . $project->publicId . '/competitors') ?>">Competitors</a></nav></header>

<section class="card">
    <div class="section-header"><div><p class="portfolio-eyebrow">Since <?= $view->e($since) ?></p><h3>Last 30 days</h3></div></div>
    <div class="kpis">
    <?php foreach ($tiles as [$label, $n, $kind]): ?>
        <a class="kpi" href="<?= $view->e($base . '?kind=' . $kind) ?>"><span class="kpi-label"><?= $view->e($label) ?></span><strong class="kpi-value"><?= (int) $n ?></strong></a>
    <?php endforeach; ?>
    </div>
    <?php if (array_sum($counts) === 0): ?><p class="muted small">No changes recorded yet. They appear after the second rank check of a keyword.</p><?php endif; ?>
</section>

<?php if ($competitors !== []): ?>
<section class="card">
    <div class="section-header"><div><p class="portfolio-eyebrow">Competitors</p><h3>Movement of tracked competitors</h3></div></div>
    <div class="table-scroll"><table class="table table-dense"><thead><tr><th scope="col">Competitor</th><th scope="col" class="numeric">New URLs in the top 10</th><th scope="col" class="numeric">Keywords entered</th><th scope="col" class="numeric">Keywords left</th></tr></thead><tbody>
    <?php foreach ($competitors as $domain => $c): ?><tr><td><a href="<?= $view->e($base . '?kind=competitors&subject=' . rawurlencode((string) $domain)) ?>"><?= $view->e((string) $domain) ?></a></td><td class="numeric"><?= $c['new_urls'] ?></td><td class="numeric"><?= $c['entered'] ?></td><td class="numeric"><?= $c['left'] ?></td></tr><?php endforeach; ?>
    </tbody></table></div>
</section>
<?php endif; ?>

<section class="card">
    <form method="get" action="<?= $view->e($base) ?>" class="insight-filter-form">
        <div class="field"><label for="serp-kind">Change</label><select id="serp-kind" name="kind"><option value="">All changes</option>
            <?php foreach (['features' => 'Features', 'competitors' => 'Competitors', 'yours' => 'Your positions', D::FORMAT_SHIFT => 'Page types'] as $value => $label): ?><option value="<?= $view->e($value) ?>"<?= $q('kind') === $value ? ' selected' : '' ?>><?= $view->e($label) ?></option><?php endforeach; ?>
            <?php foreach (SerpEventText::LABELS as $value => $label): ?><option value="<?= $view->e($value) ?>"<?= $q('kind') === $value ? ' selected' : '' ?>>· <?= $view->e($label) ?></option><?php endforeach; ?></select></div>
        <div class="field"><label for="serp-q">Keyword contains</label><input id="serp-q" name="q" maxlength="100" value="<?= $view->e($q('q')) ?>"></div>
        <div class="field"><label for="serp-subject">Domain, URL or feature</label><input id="serp-subject" name="subject" maxlength="100" value="<?= $view->e($q('subject')) ?>"></div>
        <div class="field"><label for="serp-from">From</label><input id="serp-from" name="from" type="date" value="<?= $view->e($q('from')) ?>"></div>
        <div class="field"><label for="serp-to">To</label><input id="serp-to" name="to" type="date" value="<?= $view->e($q('to')) ?>"></div>
        <button type="submit" class="button button-small">Filter</button>
    </form>
    <p class="muted small"><?= $total ?> <?= $total === 1 ? 'change' : 'changes' ?><?= $filters !== [] ? ($total === 1 ? ' matches' : ' match') . ' the filter' : '' ?>.</p>
    <?php if ($events !== []): ?>
    <div class="table-scroll"><table class="table table-dense"><thead><tr><th scope="col">Checked</th><th scope="col">Change</th><th scope="col">Keyword</th><th scope="col">Compared with</th></tr></thead><tbody>
    <?php foreach ($events as $e): ?><tr><td class="small"><?= $view->e($e->checkedOn) ?></td><td><span class="badge"><?= $view->e(SerpEventText::LABELS[$e->kind] ?? $e->kind) ?></span><br><span class="small"><?= $view->e(SerpEventText::summary($e)) ?></span></td><td><a href="<?= $view->e('/projects/' . $project->publicId . '/rankings/' . $e->keywordPublicId) ?>"><?= $view->e($e->keyword) ?></a></td><td class="small"><?= $view->e($e->previousOn) ?></td></tr><?php endforeach; ?>
    </tbody></table></div>
    <?php if ($pages > 1): ?><nav class="pagination" aria-label="Pages"><?php if ($page > 1): ?><a href="<?= $view->e($link($page - 1)) ?>">Previous</a><?php endif; ?><span><?= $page ?> of <?= $pages ?></span><?php if ($page < $pages): ?><a href="<?= $view->e($link($page + 1)) ?>">Next</a><?php endif; ?></nav><?php endif; ?>
    <?php endif; ?>
</section>
</div>
