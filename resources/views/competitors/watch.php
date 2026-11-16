<?php

declare(strict_types=1);

/**
 * Competitor content watch (1.6.0).
 *
 * @var SeoControlCenter\Core\View\Template $view
 * @var SeoControlCenter\Domain\Projects\Project $project
 * @var bool $enabled
 * @var bool $canEdit
 * @var bool $featureOn
 * @var bool $matchedOnly
 * @var string $since
 * @var list<array{competitor: SeoControlCenter\Domain\Competitors\Competitor, state: ?array{checked_on: string, baseline_on: ?string, urls: ?int, capped: bool, error: ?string}, new: int, removed: int}> $competitors
 * @var list<array{domain: string, url: string, kind: string, date: string, keywords: list<string>}> $changes
 */

$base = '/projects/' . $project->publicId . '/competitors';
$view->extend('layouts/app', ['title' => 'Competitor new pages · ' . $project->name, 'crumbs' => [['/projects', 'Projects'], ['/projects/' . $project->publicId, $project->name], [$base, 'Competitors'], [null, 'New pages']]]);
echo $view->partial('partials/project-header', ['project' => $project, 'active' => 'competitors']);
?>
<div class="research-workspace">
<header class="research-intro"><div><p class="portfolio-eyebrow"><a href="<?= $view->e($base) ?>">Competitors</a></p><h2>What competitors publish</h2><p class="muted">Once a day SEOcc reads each competitor's sitemap and lists the pages that appeared or disappeared, matched to your tracked keywords. New pages often rank weeks later, so this is an early warning. Only sitemaps are requested; it costs nothing.</p></div></header>

<section class="card" aria-labelledby="watch-heading">
    <h2 id="watch-heading">Watch</h2>
    <p><?= $enabled ? 'On: competitor sitemaps are read daily.' : 'Off. Turn it on to start; the first read records what each competitor has today, and new pages appear from the next read on.' ?></p>
    <?php if ($canEdit && $featureOn): ?>
    <form method="post" action="<?= $view->e($base . '/new-pages/watch') ?>"><?= $view->csrfField() ?><input type="hidden" name="enabled" value="<?= $enabled ? '0' : '1' ?>"><button class="button button-small"><?= $enabled ? 'Stop watching' : 'Watch competitors\' sitemaps' ?></button></form>
    <?php endif; ?>
    <?php if ($competitors === []): ?>
    <p class="muted">No competitors yet. <a href="<?= $view->e($base) ?>">Add competitors</a> first.</p>
    <?php else: ?>
    <div class="table-scroll"><table class="table table-dense">
        <thead><tr><th scope="col">Competitor</th><th scope="col">Last read</th><th scope="col">Pages in sitemap</th><th scope="col">New (30 days)</th><th scope="col">Removed (30 days)</th></tr></thead>
        <tbody>
        <?php foreach ($competitors as $row): $state = $row['state']; ?>
            <tr>
                <th scope="row"><?= $view->e($row['competitor']->domain) ?></th>
                <td><?= $state === null ? '<span class="muted">Not read yet</span>' : $view->e($state['checked_on']) . ($state['error'] !== null ? ' <span class="muted">· ' . $view->e($state['error']) . '</span>' : '') ?></td>
                <td><?= $view->e(($state['urls'] ?? null) !== null ? number_format((int) $state['urls']) . ($state['capped'] ? '+' : '') : '–') ?></td>
                <td><?= $view->e((string) $row['new']) ?></td>
                <td><?= $view->e((string) $row['removed']) ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table></div>
    <?php endif; ?>
</section>

<section class="card" aria-labelledby="changes-heading">
    <div class="card-header"><h2 id="changes-heading">Pages since <?= $view->e($since) ?></h2><a class="button button-ghost button-small" href="<?= $view->e($base . '/new-pages' . ($matchedOnly ? '' : '?matched=1')) ?>"><?= $matchedOnly ? 'Show all pages' : 'Only pages matching my keywords' ?></a></div>
    <?php if ($changes === []): ?>
    <p class="muted"><?= $matchedOnly ? 'No new or removed page matches a tracked keyword.' : 'No pages appeared or disappeared yet.' ?></p>
    <?php else: ?>
    <div class="table-scroll"><table class="table table-dense">
        <thead><tr><th scope="col">Date</th><th scope="col">Competitor</th><th scope="col">Change</th><th scope="col">Page</th><th scope="col">Your keywords</th></tr></thead>
        <tbody>
        <?php foreach ($changes as $change): ?>
            <tr>
                <td><?= $view->e($change['date']) ?></td>
                <td><?= $view->e($change['domain']) ?></td>
                <td><?= $change['kind'] === 'new' ? '<span class="badge badge-info">New</span>' : '<span class="badge">Removed</span>' ?></td>
                <td class="url-cell"><?= $view->e($change['url']) ?></td>
                <td><?= $view->e(implode(', ', $change['keywords'])) ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table></div>
    <p class="muted small">A page counts as removed when the sitemap no longer lists it; it may still exist. Sitemaps capped at 10,000 pages, or that suddenly list less than half as many pages, are not used to report removals.</p>
    <?php endif; ?>
</section>
</div>
