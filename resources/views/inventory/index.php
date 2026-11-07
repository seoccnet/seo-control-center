<?php

declare(strict_types=1);

/**
 * Research → Content inventory (ADR-042, 0.33.0).
 *
 * @var SeoControlCenter\Core\View\Template $view
 * @var SeoControlCenter\Domain\Projects\Project $project
 * @var array<string, mixed> $data
 * @var array{state: string, source: string, q: string} $filters
 * @var int $page
 */

use SeoControlCenter\Domain\ContentInventory\ContentItem;
use SeoControlCenter\Domain\ContentInventory\Inventory;
use SeoControlCenter\Modules\ContentInventory\ContentInventoryService;

$view->extend('layouts/app', ['title' => 'Content inventory · ' . $project->name]);
echo $view->partial('partials/project-header', ['project' => $project, 'active' => 'inventory']);
$base = '/projects/' . $project->publicId . '/inventory';
$label = static fn(string $state): string => match ($state) {
    'live' => 'badge badge-ok',
    'retired', 'error' => 'badge badge-error',
    'redirect', 'canonicalised', 'noindex' => 'badge badge-warning',
    'draft', 'scheduled', 'published' => 'badge badge-info',
    default => 'badge',
};
$query = static fn(array $over) => '?' . http_build_query(array_filter([...$filters, 'page' => null, ...$over], static fn($v): bool => $v !== '' && $v !== null));
$canEdit = $data['can_edit'];
$pages = max(1, (int) ceil($data['total'] / $data['limit']));
?>
<div class="research-workspace inventory-workspace">
<header class="research-intro"><div><h2>Content inventory</h2><p class="muted">Every page, draft and planned article SEOcc knows about, in one list. Each source keeps its own status and date: a CMS draft is not a public page, a canonical is not a redirect, and a page without rankings still exists. Article ideas and briefs check this list before suggesting new content.</p><p class="small"><a href="<?= $view->e($base . '/overlap') ?>">Overlap review</a>: pages and plans that may cover the same thing.</p></div></header>

<section class="card" aria-labelledby="inventory-sources-title">
    <h2 id="inventory-sources-title">Sources</h2>
    <div class="table-scroll"><table class="table table-dense" data-no-table-controls>
        <thead><tr><th scope="col">Source</th><th scope="col" class="num">Records</th><th scope="col">As of (UTC)</th><th scope="col">What it covers</th></tr></thead>
        <tbody>
        <?php foreach (Inventory::SOURCES as $key => $name): $c = $data['coverage'][$key] ?? null; if ($c === null) { continue; } ?>
            <tr>
                <td><a href="<?= $view->e($base . $query(['source' => $key])) ?>"><?= $view->e($name) ?></a></td>
                <td class="num"><?= number_format((int) $c['count']) ?><?php if ($c['limited']): ?> <span class="badge badge-warning">limit reached</span><?php endif; ?></td>
                <td class="cell-nowrap"><?= $view->e($c['observed_at'] !== null ? substr((string) $c['observed_at'], 0, 16) : '—') ?></td>
                <td class="small muted"><?= $view->e($c['note']) ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table></div>
</section>

<section class="card" aria-labelledby="inventory-list-title">
    <div class="card-header"><h2 id="inventory-list-title">Pages and records</h2><span class="badge"><?= number_format($data['total']) ?><?= $data['total'] !== $data['all'] ? ' of ' . number_format($data['all']) : '' ?></span></div>
    <nav class="inventory-states" aria-label="Filter by state">
        <a class="chip" href="<?= $view->e($base . $query(['state' => null])) ?>"<?= $filters['state'] === '' ? ' aria-current="true"' : '' ?>>All <span class="counter"><?= number_format($data['all']) ?></span></a>
        <?php foreach (Inventory::STATES as $state => $name): if ($data['counts'][$state] === 0) { continue; } ?>
            <a class="chip" href="<?= $view->e($base . $query(['state' => $state])) ?>"<?= $filters['state'] === $state ? ' aria-current="true"' : '' ?>><?= $view->e($name) ?> <span class="counter"><?= number_format($data['counts'][$state]) ?></span></a>
        <?php endforeach; ?>
    </nav>
    <form method="get" action="<?= $view->e($base) ?>" class="inline-form inventory-filter">
        <?php if ($filters['state'] !== ''): ?><input type="hidden" name="state" value="<?= $view->e($filters['state']) ?>"><?php endif; ?>
        <label class="visually-hidden" for="inventory-q">Search address or title</label><input id="inventory-q" type="search" name="q" value="<?= $view->e($filters['q']) ?>" placeholder="Search address or title">
        <label class="visually-hidden" for="inventory-source">Source</label><select id="inventory-source" name="source"><option value="">All sources</option><?php foreach (Inventory::SOURCES as $key => $name): ?><option value="<?= $view->e($key) ?>"<?= $filters['source'] === $key ? ' selected' : '' ?>><?= $view->e($name) ?></option><?php endforeach; ?></select>
        <button class="button button-secondary">Filter</button>
    </form>
    <?php if ($data['needs_review'] > 0): ?><p class="notice" role="status"><?= number_format($data['needs_review']) ?> record<?= $data['needs_review'] === 1 ? '' : 's' ?> without an address may be a page that already exists. Check the possible matches and link the right page.</p><?php endif; ?>

    <?php if ($data['rows'] === []): ?>
        <p class="muted">Nothing matches. <?= $data['all'] === 0 ? 'Run a site audit, import Search Console, or add your CMS drafts below to start the inventory.' : '' ?></p>
    <?php else: ?>
    <div class="table-scroll"><table class="table inventory-table" data-no-table-controls>
        <thead><tr><th scope="col">Page or record</th><th scope="col">State</th><th scope="col">What each source says</th></tr></thead>
        <tbody>
        <?php foreach ($data['rows'] as $row): ?>
            <tr>
                <td>
                    <?php if ($row['title'] !== null): ?><strong><?= $view->e($row['title']) ?></strong><br><?php endif; ?>
                    <?php if ($row['url'] !== null): ?><a href="<?= $view->e($row['url']) ?>" rel="noopener noreferrer" target="_blank" class="small"><?= $view->e($row['url']) ?></a><?php else: ?><span class="small muted">No address yet</span><?php endif; ?>
                    <?php if ($row['variants'] !== []): ?><br><span class="small muted">Also seen as <?= $view->e(implode(', ', $row['variants'])) ?></span><?php endif; ?>
                </td>
                <td class="cell-nowrap"><span class="<?= $label($row['state']) ?>"><?= $view->e(Inventory::STATES[$row['state']]) ?></span></td>
                <td>
                    <ul class="inventory-sources">
                    <?php foreach ($row['sources'] as $source): ?>
                        <li><span class="inventory-source-name"><?= $view->e(Inventory::SOURCES[$source['source']] ?? $source['source']) ?></span> <?= $view->e($source['detail']) ?><?php if ($source['observed_at'] !== null): ?> <span class="muted small">· <?= $view->e(substr((string) $source['observed_at'], 0, 10)) ?></span><?php endif; ?>
                        <?php if ($canEdit && isset($source['item'])): $item = $source['item']; ?>
                            <details class="inventory-edit"><summary class="button button-small button-ghost">Edit</summary>
                                <form method="post" action="<?= $view->e($base . '/items/' . $item['id']) ?>" class="inventory-item-form"><?= $view->csrfField() ?><input type="hidden" name="version" value="<?= (int) $item['version'] ?>">
                                    <div class="field"><label for="t-<?= $view->e($item['id']) ?>">Title</label><input id="t-<?= $view->e($item['id']) ?>" name="title" required maxlength="300" value="<?= $view->e($item['title']) ?>"></div>
                                    <div class="field"><label for="u-<?= $view->e($item['id']) ?>">Address</label><input id="u-<?= $view->e($item['id']) ?>" name="url" type="url" maxlength="2048" value="<?= $view->e((string) $item['url']) ?>"></div>
                                    <div class="field"><label for="s-<?= $view->e($item['id']) ?>">Status</label><select id="s-<?= $view->e($item['id']) ?>" name="status"><?php foreach (ContentItem::STATUSES as $value => $name): ?><option value="<?= $value ?>"<?= $item['status'] === $value ? ' selected' : '' ?>><?= $name ?></option><?php endforeach; ?></select></div>
                                    <div class="field"><label for="d-<?= $view->e($item['id']) ?>">Publish date</label><input id="d-<?= $view->e($item['id']) ?>" name="publish_on" type="date" value="<?= $view->e((string) $item['publish_on']) ?>"></div>
                                    <div class="field"><label for="c-<?= $view->e($item['id']) ?>">CMS ID</label><input id="c-<?= $view->e($item['id']) ?>" name="cms_id" maxlength="100" value="<?= $view->e((string) $item['cms_id']) ?>"></div>
                                    <button class="button button-small">Save</button>
                                </form>
                                <form method="post" action="<?= $view->e($base . '/items/' . $item['id'] . '/delete') ?>" data-confirm="Remove this CMS record from the inventory?"><?= $view->csrfField() ?><button class="button button-small button-danger">Remove record</button></form>
                                <?php if ($item['linked_url'] !== null): ?><form method="post" action="<?= $view->e($base . '/items/' . $item['id'] . '/link') ?>"><?= $view->csrfField() ?><input type="hidden" name="version" value="<?= (int) $item['version'] ?>"><button class="button button-small button-ghost">Unlink <?= $view->e($item['linked_url']) ?></button></form><?php endif; ?>
                            </details>
                        <?php endif; ?></li>
                    <?php endforeach; ?>
                    </ul>
                    <?php if ($row['candidates'] !== []): $item = null; foreach ($row['sources'] as $source) { $item ??= $source['item'] ?? null; } ?>
                        <div class="inventory-candidates"><span class="small"><strong>Possible match</strong> (SEOcc is not sure; confirm before it counts):</span>
                        <?php foreach ($row['candidates'] as $candidate): ?>
                            <div class="inventory-candidate"><a href="<?= $view->e($candidate['url']) ?>" class="small" rel="noopener noreferrer" target="_blank"><?= $view->e($candidate['title'] ?? $candidate['url']) ?></a>
                            <?php if ($canEdit && $item !== null): ?><form method="post" action="<?= $view->e($base . '/items/' . $item['id'] . '/link') ?>"><?= $view->csrfField() ?><input type="hidden" name="version" value="<?= (int) $item['version'] ?>"><input type="hidden" name="url" value="<?= $view->e($candidate['url']) ?>"><button class="button button-small">This is the page</button></form><?php endif; ?></div>
                        <?php endforeach; ?></div>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table></div>
    <?php if ($pages > 1): ?>
    <nav class="pagination" aria-label="Inventory pages">
        <?php if ($page > 1): ?><a class="button button-small" href="<?= $view->e($base . $query(['page' => $page - 1])) ?>">Previous</a><?php endif; ?>
        <span class="small muted">Page <?= $page ?> of <?= $pages ?></span>
        <?php if ($page < $pages): ?><a class="button button-small" href="<?= $view->e($base . $query(['page' => $page + 1])) ?>">Next</a><?php endif; ?>
    </nav>
    <?php endif; ?>
    <?php endif; ?>
</section>

<?php if ($canEdit): ?>
<div class="insight-two-column">
    <section class="card" aria-labelledby="inventory-add-title">
        <h2 id="inventory-add-title">Add a CMS record</h2>
        <form method="post" action="<?= $view->e($base . '/items') ?>" class="inventory-item-form"><?= $view->csrfField() ?>
            <div class="field"><label for="inventory-title">Title</label><input id="inventory-title" name="title" required maxlength="300" placeholder="How to dry and cure tomato"></div>
            <div class="field"><label for="inventory-url">Address <span class="muted">(optional for drafts)</span></label><input id="inventory-url" name="url" type="url" maxlength="2048" placeholder="https://<?= $view->e($project->primaryDomain) ?>/blog/…"></div>
            <div class="field"><label for="inventory-status">Status</label><select id="inventory-status" name="status"><?php foreach (ContentItem::STATUSES as $value => $name): ?><option value="<?= $value ?>"><?= $name ?></option><?php endforeach; ?></select></div>
            <div class="field"><label for="inventory-date">Publish date <span class="muted">(optional)</span></label><input id="inventory-date" name="publish_on" type="date"></div>
            <button class="button button-primary">Add record</button>
        </form>
    </section>
    <section class="card" aria-labelledby="inventory-csv-title">
        <h2 id="inventory-csv-title">Import a CMS export</h2>
        <p class="small muted">Paste CSV with a header row: <code>title</code> and <code>status</code> are required; <code>url</code>, <code>publish_date</code>, <code>cms_id</code> and <code>note</code> are optional. WordPress statuses (publish, future, draft, pending, private, trash) are understood. A row with a known <code>cms_id</code> updates that record. Up to <?= ContentInventoryService::MAX_CSV_ROWS ?> rows at a time.</p>
        <form method="post" action="<?= $view->e($base . '/import') ?>"><?= $view->csrfField() ?>
            <div class="field"><label for="inventory-csv">CSV</label><textarea id="inventory-csv" name="csv" rows="6" required spellcheck="false" placeholder="title,url,status,publish_date,cms_id&#10;Drying pepper guide,,draft,,1042"></textarea></div>
            <button class="button button-secondary">Import</button>
        </form>
    </section>
</div>
<?php endif; ?>
</div>
