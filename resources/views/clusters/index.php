<?php

declare(strict_types=1);

/**
 * Keyword clusters with page ownership and overlap (ClusterService::listing).
 *
 * @var SeoControlCenter\Core\View\Template $view
 * @var SeoControlCenter\Domain\Projects\Project $project
 * @var array{items: list<SeoControlCenter\Domain\Clusters\KeywordCluster>, total: int, page: int, pages: int, counts: array<string, int>, show_all: bool} $listing
 * @var array{status: string, q: string, show: string} $filters
 * @var bool $canEdit
 */

use SeoControlCenter\Domain\Clusters\KeywordCluster;

$base = '/projects/' . $project->publicId . '/keywords/topics';
$view->extend('layouts/app', ['title' => 'Topics & owners · ' . $project->name]);
echo $view->partial('partials/project-header', ['project' => $project, 'active' => 'keywords']);
$statusBadge = static fn(string $status): string => match ($status) {
    KeywordCluster::OWNED => 'badge badge-ok',
    KeywordCluster::CONTESTED => 'badge badge-warning',
    default => 'badge',
};
$statusLabel = [KeywordCluster::OWNED => 'Owned', KeywordCluster::CONTESTED => 'Contested', KeywordCluster::UNOWNED => 'Unowned'];
$kindLabel = ['switching' => 'Switching pages', 'split' => 'Split', 'stacked' => 'Two listings', 'brand' => 'Brand search'];
$kindBadge = ['switching' => 'badge badge-error', 'split' => 'badge badge-warning', 'stacked' => 'badge badge-ok', 'brand' => 'badge badge-ok'];
$counts = $listing['counts'];
$link = static fn(array $params): string => $base . '?' . http_build_query(array_filter([...$filters, ...$params], static fn(mixed $v): bool => $v !== '' && $v !== null && $v !== 1));
$path = static fn(?string $url): string => $url === null ? '' : ((string) (parse_url($url, PHP_URL_PATH) ?? '') . (($q = parse_url($url, PHP_URL_QUERY)) !== null && $q !== false ? '?' . $q : '') ?: '/');
?>
<div class="research-workspace topics-workspace">
<header class="research-intro"><div><p class="portfolio-eyebrow">Research · Topics &amp; owners</p><h2>Which page owns each topic?</h2><p class="muted">Searches one page can target together, grouped from your Search Console queries, tracked keywords and research. Each topic shows the page that should own it and where several of your pages compete for the same search.</p></div></header>

<div class="insight-metric-grid" aria-label="Topic ownership">
<?php foreach ([[KeywordCluster::OWNED, 'Owned', 'one page leads'], [KeywordCluster::CONTESTED, 'Contested', 'two pages close'], [KeywordCluster::UNOWNED, 'Unowned', 'no page yet'], ['overlap', 'Overlap to review', 'switching or split']] as [$key, $title, $note]): ?>
    <a class="card insight-metric" href="<?= $view->e($link(['status' => $key, 'page' => null])) ?>"<?= $filters['status'] === $key ? ' aria-current="true"' : '' ?>><p class="portfolio-eyebrow"><?= $view->e($title) ?></p><strong><?= (int) ($counts[$key] ?? 0) ?></strong><p class="muted small"><?= $view->e($note) ?></p></a>
<?php endforeach; ?>
</div>

<section class="card">
    <form method="get" action="<?= $view->e($base) ?>" class="filter-form">
        <label class="filter-field"><span>Topic or keyword contains</span><input type="search" name="q" maxlength="100" value="<?= $view->e($filters['q']) ?>"></label>
        <label class="filter-field"><span>Show</span><select name="status"><?php foreach (['' => 'All active topics', KeywordCluster::OWNED => 'Owned', KeywordCluster::CONTESTED => 'Contested', KeywordCluster::UNOWNED => 'Unowned', 'overlap' => 'Overlap to review', 'retired' => 'No longer found'] as $value => $label): ?><option value="<?= $view->e($value) ?>"<?= $filters['status'] === $value ? ' selected' : '' ?>><?= $view->e($label) ?></option><?php endforeach; ?></select></label>
        <?php if ($filters['show'] === 'all'): ?><input type="hidden" name="show" value="all"><?php endif; ?>
        <div class="filter-actions"><button type="submit" class="button button-small button-ghost">Filter</button></div>
    </form>
    <?php if ($counts['hidden'] > 0): ?>
        <p class="muted small"><?= (int) $counts['hidden'] ?> <?= $counts['hidden'] === 1 ? 'topic is' : 'topics are' ?> hidden: no search in them mentions what you offer, and your site gets no traffic or rankings for them. <a href="<?= $view->e($link(['show' => 'all', 'page' => null])) ?>">Show all topics</a></p>
    <?php elseif ($listing['show_all']): ?>
        <p class="muted small">Showing every topic, including those with no relevant search and no traffic. <a href="<?= $view->e($link(['show' => null, 'page' => null])) ?>">Hide unrelated topics</a></p>
    <?php endif; ?>
    <?php if ($counts['all'] === 0 && $counts['retired'] === 0 && $counts['hidden'] === 0): ?>
        <div class="empty-state"><h3>No topics yet</h3><p>Topics are saved by the opportunity analysis. Run <a href="<?= $view->e('/projects/' . $project->publicId . '/opportunities') ?>">Analyze now</a> once Search Console or tracked keywords have data.</p></div>
    <?php elseif ($listing['items'] === []): ?>
        <p class="muted">No topic matches the filter.</p>
    <?php endif; ?>
    <p class="muted small">Ownership comes from Search Console clicks (then impressions) for the topic's searches over the analysis window, or from tracked rank checks. Overlap is not always bad: two listings and brand searches are fine; switching pages and splits are worth a look.</p>
</section>

<?php foreach ($listing['items'] as $cluster): ?>
<section class="card topic-card" id="cluster-<?= $view->e($cluster->publicId) ?>">
    <div class="section-header"><div><p class="portfolio-eyebrow"><?= (int) $cluster->size ?> <?= $cluster->size === 1 ? 'search' : 'searches' ?> · demand <?= $view->e(number_format($cluster->demand)) ?></p><h3><?= $view->e($cluster->label) ?></h3></div>
        <div><?php if ($cluster->retiredAt !== null): ?><span class="badge">No longer found</span> <?php endif; ?><span class="<?= $view->e($statusBadge($cluster->status)) ?>"><?= $view->e($statusLabel[$cluster->status] ?? $cluster->status) ?></span><?php if ($cluster->overlapFlagged > 0): ?> <span class="badge badge-warning"><?= (int) $cluster->overlapFlagged ?> overlap</span><?php endif; ?></div></div>
    <p><strong>Owner:</strong> <?php if ($cluster->ownerUrl !== null): ?><a href="<?= $view->e('/projects/' . $project->publicId . '/pages/details?url=' . rawurlencode($cluster->ownerUrl)) ?>" class="url-cell"><?= $view->e($cluster->ownerUrl) ?></a> <span class="muted small">(<?= $view->e(match ($cluster->ownerSource) { 'manual' => 'set by your team' . ($cluster->via !== null ? ' via ' . $cluster->via : ''), 'rankings' => 'from rank checks', default => 'from Search Console' }) ?>)</span><?php else: ?><span class="muted">none</span><?php endif; ?></p>
    <p class="muted small"><?= $view->e($cluster->ownerReason) ?><?php if ($cluster->manualOwnerUrl !== null && $cluster->inferredUrl !== null && $cluster->inferredUrl !== $cluster->manualOwnerUrl): ?> Search Console currently sends most of this topic to <?= $view->e($path($cluster->inferredUrl)) ?>, not to the page you chose.<?php endif; ?></p>
    <details><summary>Searches and pages</summary>
        <p class="small"><?php foreach (array_slice($cluster->members, 0, 30) as $member): ?><span class="badge"><?= $view->e($member['keyword']) ?></span> <?php endforeach; ?><?= count($cluster->members) > 30 ? '<span class="muted small">and ' . (count($cluster->members) - 30) . ' more</span>' : '' ?></p>
        <?php if ($cluster->candidates !== []): ?>
            <div class="table-scroll"><table class="table table-dense numeric"><thead><tr><th scope="col">Page</th><th scope="col">Share</th><th scope="col">Clicks</th><th scope="col">Impressions</th><th scope="col">Best position</th></tr></thead><tbody>
            <?php foreach ($cluster->candidates as $candidate): ?><tr><td class="url-cell"><?= $view->e($path($candidate['url'])) ?></td><td><?= $view->e((string) round($candidate['share'] * 100)) ?> %</td><td><?= $view->e(number_format($candidate['clicks'])) ?></td><td><?= $view->e(number_format($candidate['impressions'])) ?></td><td><?= $candidate['best_position'] !== null ? $view->e((string) $candidate['best_position']) : '—' ?></td></tr><?php endforeach; ?>
            </tbody></table></div>
        <?php endif; ?>
    </details>
    <?php if ($cluster->overlap !== []): ?>
    <details<?= $cluster->overlapFlagged > 0 ? ' open' : '' ?>><summary>Searches more than one page appears for (<?= count($cluster->overlap) ?>)</summary>
        <ul class="data-quality-checks">
        <?php foreach ($cluster->overlap as $item): ?>
            <li><span class="<?= $view->e($kindBadge[$item['kind']] ?? 'badge') ?>"><?= $view->e($kindLabel[$item['kind']] ?? (string) $item['kind']) ?></span> <strong><?= $view->e((string) $item['query']) ?></strong><br>
                <span class="muted small"><?= $view->e((string) $item['note']) ?></span><br>
                <span class="small"><?php foreach ((array) $item['pages'] as $page): ?><?= $view->e($path((string) $page['url'])) ?> · <?= $view->e((string) round((float) $page['share'] * 100)) ?> % · led <?= (int) $page['weeks_led'] ?>/<?= (int) $item['weeks'] ?> weeks · position <?= $view->e((string) $page['position']) ?><br><?php endforeach; ?></span></li>
        <?php endforeach; ?>
        </ul>
    </details>
    <?php endif; ?>
    <p><a class="button button-small" href="<?= $view->e($base . '/' . $cluster->publicId . '/analysis') ?>">Why they outrank you</a></p>
    <?php if ($canEdit): ?>
    <form method="post" action="<?= $view->e($base . '/' . $cluster->publicId . '/owner') ?>" class="inline-form">
        <?= $view->csrfField() ?><input type="hidden" name="revision" value="<?= (int) $cluster->revision ?>">
        <label class="small" for="owner-<?= $view->e($cluster->publicId) ?>">Page that should own this topic</label>
        <input id="owner-<?= $view->e($cluster->publicId) ?>" type="url" name="url" maxlength="2048" list="owners-<?= $view->e($cluster->publicId) ?>" value="<?= $view->e($cluster->manualOwnerUrl ?? '') ?>" placeholder="<?= $view->e($cluster->inferredUrl ?? 'https://' . $project->primaryDomain . '/') ?>">
        <datalist id="owners-<?= $view->e($cluster->publicId) ?>"><?php foreach ($cluster->candidates as $candidate): ?><option value="<?= $view->e($candidate['url']) ?>"></option><?php endforeach; ?></datalist>
        <button type="submit" class="button button-small button-ghost">Set owner</button>
        <?php if ($cluster->manualOwnerUrl !== null): ?><button type="submit" name="action" value="clear" class="button button-small button-ghost">Use Search Console again</button><?php endif; ?>
    </form>
    <p class="muted small">Setting the main page also lets SEOcc check that the topic’s other pages link to it from their text.</p>
    <?php endif; ?>
</section>
<?php endforeach; ?>

<?php if ($listing['pages'] > 1): ?>
<nav class="pagination" aria-label="Topic pages"><?php if ($listing['page'] > 1): ?><a href="<?= $view->e($link(['page' => $listing['page'] - 1])) ?>">Previous</a><?php endif; ?> <span>Page <?= (int) $listing['page'] ?> of <?= (int) $listing['pages'] ?></span> <?php if ($listing['page'] < $listing['pages']): ?><a href="<?= $view->e($link(['page' => $listing['page'] + 1])) ?>">Next</a><?php endif; ?></nav>
<?php endif; ?>
</div>
