<?php

declare(strict_types=1);

use SeoControlCenter\Modules\PageMonitoring\PageSnapshot;

$base = '/projects/' . $project->publicId . '/page-monitoring';
$filters = $filters ?? ['q' => '', 'kind' => 'all'];
$historyQuery = http_build_query($filters, '', '&', PHP_QUERY_RFC3986);
$view->extend('layouts/app', ['title' => 'Page monitoring · ' . $project->name]);
echo $view->partial('partials/project-header', ['project' => $project, 'active' => 'page-monitoring']);
$labels = ['status' => 'HTTP status', 'title' => 'Title', 'description' => 'Description', 'h1s' => 'H1 headings (first 20)', 'h1_count' => 'H1 count', 'canonical' => 'Canonical', 'noindex' => 'Noindex'];
?>
<div class="improve-workspace improve-monitoring">
<header class="improve-intro"><div><p class="portfolio-eyebrow">Improve · Page monitoring</p><h2>Keep important pages under watch</h2><p class="muted">Follow saved page checks and review changes to SEO settings and page content.</p></div><nav class="improve-shortcuts" aria-label="Improve sections"><a href="#improve-watched-pages">Watched pages</a><a href="#improve-detected-changes">Detected changes</a></nav></header>
<section class="card page-monitoring-section">
    <div class="sync-row"><div><h2>Page monitoring</h2><p>Watch important pages for changes to their search settings.</p></div><span class="indexing-badge"><?= count($watches) ?> / <?= (int) $limit ?> watched pages</span></div>
    <p class="muted">A new watch is read within the hour, then every seven days. The first complete check establishes a baseline. Canonical, noindex and HTTP status changes can trigger your enabled alerts. Titles, descriptions and H1 changes remain in history.</p>
    <p class="muted">Checks read server HTML and respect robots.txt. JavaScript content is not rendered and redirects are not followed. Failed or incomplete checks retain previous evidence. Times are UTC; the exact edit time between checks is unknown.</p>
    <a href="/account/notifications">Choose your alert preferences</a>
    <?php if ($canEdit): ?>
    <form method="post" action="<?= $view->e($base) ?>" class="indexing-request">
        <?= $view->csrfField() ?>
        <div class="field"><label for="watch-url">Important page URL</label><input type="url" id="watch-url" name="url" required maxlength="2048" value="<?= $view->e($selectedUrl ?? '') ?>" placeholder="<?= $view->e('https://' . $project->primaryDomain . '/important-page') ?>"><p class="muted small">Use an exact HTTPS page on this project’s domain. Watching a page requires the Site audit feature.</p></div>
        <button class="button button-primary" name="action" value="watch">Watch weekly</button>
    </form>
    <details class="page-monitoring-bulk"><summary>Add many pages</summary>
    <?php if (count($watches) >= (int) $limit): ?>
    <p class="muted small page-monitoring-full">This project already watches <?= (int) $limit ?> pages, the limit of this workspace. Stop watching a page below<?= ($canManageLimit ?? false) ? ' or raise the limit' : '' ?> before adding more.</p>
    <?php else: ?>
    <form method="post" action="<?= $view->e($base . '/bulk') ?>" class="stack">
        <?= $view->csrfField() ?>
        <div class="field"><label for="watch-urls">Page URLs, one per line</label><textarea id="watch-urls" name="urls" rows="4" maxlength="100000" placeholder="<?= $view->e('https://' . $project->primaryDomain . '/a' . "\n" . 'https://' . $project->primaryDomain . '/b') ?>"></textarea></div>
        <?php if (($suggestions ?? []) !== []): ?>
        <fieldset class="page-monitoring-picks"><legend class="small">Or pick pages with the most Google clicks that are not watched yet</legend>
            <?php foreach ($suggestions as $i => $s): ?><label class="small"><input type="checkbox" name="pick[]" value="<?= $view->e($s['url']) ?>"> <?= $view->e($s['url']) ?><?= $s['clicks'] !== null ? ' <span class="muted">(' . $view->e(number_format($s['clicks'])) . ' clicks)</span>' : '' ?></label><?php endforeach; ?>
        </fieldset>
        <?php endif; ?>
        <button class="button button-small">Watch these pages</button>
        <p class="muted small">Each new page is read right away for its “before” snapshot, then weekly. Recording a change for an exact URL on the change timeline also reads that page before and again three days later.</p>
    </form>
    <?php endif; ?></details>
    <?php endif; ?>
    <?php if ($canManageLimit ?? false): ?>
    <form method="post" action="<?= $view->e($base . '/limit') ?>" class="inline-form"><?= $view->csrfField() ?>
        <label for="watch-limit" class="small">Pages each project of this workspace may watch</label><input id="watch-limit" name="limit" type="number" min="1" max="<?= \SeoControlCenter\Modules\PageMonitoring\PageWatchWriter::MAX_LIMIT ?>" value="<?= (int) $limit ?>">
        <button class="button button-small button-ghost">Save limit</button></form>
    <?php endif; ?>
</section>
<section class="card page-monitoring-section" id="improve-watched-pages"><h2>Watched pages</h2>
<?php if ($watches === []): ?><div class="empty-state"><h3>No pages watched yet</h3><p>Add your most important landing pages to start collecting evidence.</p></div><?php endif; ?>
<div class="brief-list">
<?php foreach ($watches as $watch): ?>
    <article class="brief-list-item"><div>
        <h3 class="brief-url"><?= $view->e($watch['url']) ?></h3>
        <p class="muted small">Last complete check: <?= $view->e($watch['checked_at'] ?? 'Awaiting baseline') ?> · last attempt: <?= $view->e($watch['attempted_at'] ?? 'Waiting for cron') ?></p>
        <?php if ($watch['error'] !== null): ?><p role="status"><?= $view->e($watch['error']) ?></p><?php endif; ?>
        <?php if (is_string($watch['snapshot'])): $snapshot = PageSnapshot::decode($watch['snapshot']); ?>
        <details><summary>Latest saved evidence</summary><dl class="indexing-evidence"><?php foreach ($labels as $field => $label): ?><dt><?= $view->e($label) ?></dt><dd><?= $view->e($field !== 'status' && !($snapshot->values['html'] ?? false) ? 'Unavailable on this response' : PageSnapshot::display($snapshot->values[$field] ?? null)) ?></dd><?php endforeach; ?></dl></details>
        <?php endif; ?>
    </div><?php if ($canEdit): ?><form method="post" action="<?= $view->e($base) ?>"><?= $view->csrfField() ?><input type="hidden" name="url" value="<?= $view->e($watch['url']) ?>"><button class="button button-ghost button-small" name="action" value="unwatch">Stop watching</button></form><?php endif; ?></article>
<?php endforeach; ?>
</div></section>
<section class="card page-monitoring-section" id="improve-detected-changes"><h2>Detected changes</h2><p class="muted">A change was observed between two complete checks. Review the evidence before drawing conclusions about SEO impact. The newest 500 changes are retained per project.</p>
<?php if (!($selectedChange ?? false)): ?>
<form method="get" action="<?= $view->e($base) ?>#improve-detected-changes" class="form">
    <div class="field"><label for="history-search">Search page URL</label><input type="search" id="history-search" name="q" maxlength="2048" value="<?= $view->e($filters['q']) ?>" placeholder="URL or part of a path"></div>
    <div class="field"><label for="history-kind">Change type</label><select id="history-kind" name="kind"><?php foreach (['all' => 'All changes', 'significant' => 'Significant SEO setting changes', 'text' => 'Page text changes'] as $value => $label): ?><option value="<?= $view->e($value) ?>" <?= $filters['kind'] === $value ? 'selected' : '' ?>><?= $view->e($label) ?></option><?php endforeach; ?></select></div>
    <button class="button button-secondary">Filter history</button> <a href="<?= $view->e($base) ?>#improve-detected-changes">Reset filters</a>
</form>
<p class="muted small"><?= (int) ($total ?? count($changes)) ?> matching changes · page <?= (int) $page ?> of <?= (int) $pages ?></p>
<?php endif; ?>
<p><a class="button button-secondary" href="<?= $view->e($base . '?format=csv' . (($selectedChange ?? false) && $changes !== [] ? '&change=' . rawurlencode($changes[0]['public_id']) : '&' . $historyQuery)) ?>"><?= ($selectedChange ?? false) ? 'Export this change as CSV' : ($filters['q'] !== '' || $filters['kind'] !== 'all' ? 'Export filtered history as CSV' : 'Export retained history as CSV') ?></a></p>
<p class="muted small">One CSV row per changed field, with before/after values and UTC observation times. History export uses the current filters and includes up to 500 matching retained changes across all pages; detection time is not the exact edit time.</p>
<?php if ($selectedChange ?? false): ?><p><a href="<?= $view->e($base) ?>">Show all detected changes</a></p><?php endif; ?>
<?php if ($changes === []): ?><div class="empty-state"><h3><?= $filters['q'] !== '' || $filters['kind'] !== 'all' ? 'No changes match these filters' : 'No changes detected yet' ?></h3><p>Try clearing the filters. Changes appear after a watched page differs from its previous complete check.</p></div><?php endif; ?>
<?php foreach ($changes as $change): $before = PageSnapshot::decode($change['before_snapshot']); $after = PageSnapshot::decode($change['after_snapshot']); ?>
<article class="brief-list-item <?= (bool) $change['significant'] ? 'monitor-change-significant' : 'monitor-change-text' ?>" id="change-<?= $view->e($change['public_id']) ?>"><div>
    <h3 class="brief-url"><?= $view->e($change['url']) ?></h3>
    <p><?= (bool) $change['significant'] ? 'Significant SEO setting change' : 'Page text changed' ?> · <?= $view->e($change['detected_at']) ?> UTC</p>
    <p class="muted small">Before: <?= $view->e($change['before_at']) ?> · after: <?= $view->e($change['detected_at']) ?></p>
    <dl class="indexing-evidence"><?php foreach ($before->changes($after) as $field => $values): ?><dt><?= $view->e($labels[$field] ?? $field) ?></dt><dd><strong>Before:</strong> <?= $view->e(PageSnapshot::display($values['before'])) ?><br><strong>After:</strong> <?= $view->e(PageSnapshot::display($values['after'])) ?></dd><?php endforeach; ?></dl>
    <?php if ($change['annotation_id'] !== null): ?><a class="button button-secondary" href="<?= $view->e('/projects/' . $project->publicId . '/annotations/' . $change['annotation_id'] . '/impact') ?>">Open impact comparison</a><?php endif; ?>
    <?php if ($canEdit): ?><form method="post" action="<?= $view->e($base . '/' . $change['public_id'] . '/annotation') ?>">
        <?= $view->csrfField() ?>
        <div class="field"><label for="change-date-<?= $view->e($change['public_id']) ?>">Actual edit date, if known (UTC)</label><input type="date" id="change-date-<?= $view->e($change['public_id']) ?>" name="date" required value="<?= $view->e(substr($change['detected_at'], 0, 10)) ?>"><p class="muted small">Defaults to the detection date. If the edit date is unknown, this comparison is approximate context.</p></div>
        <button class="button button-secondary"><?= $change['annotation_id'] === null ? 'Record annotation and compare impact' : 'Open or recreate linked annotation' ?></button>
    </form><?php endif; ?>
</div></article>
<?php endforeach; ?>
<nav class="pagination" aria-label="Page change history"><?php foreach (['Previous' => $page - 1, 'Next' => $page + 1] as $label => $number): ?><?php if ($number >= 1 && $number <= $pages): ?><a class="button button-ghost" href="<?= $view->e($base . '?' . $historyQuery . '&page=' . $number . '#improve-detected-changes') ?>"><?= $view->e($label) ?></a><?php endif; ?><?php endforeach; ?></nav>
</section>

</div>
