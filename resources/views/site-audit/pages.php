<?php
/**
 * @var SeoControlCenter\Core\View\Template $view
 * @var SeoControlCenter\Domain\Projects\Project $project
 * @var SeoControlCenter\Modules\SiteAudit\SiteAudit $audit
 * @var list<SeoControlCenter\Modules\SiteAudit\AuditPage> $pages
 * @var int $page
 * @var int $pageCount
 */
$base = '/projects/' . $project->publicId . '/site-audit';
$view->extend('layouts/app', ['title' => 'Checked pages · ' . $project->name, 'crumbs' => [['/projects', 'Projects'], ['/projects/' . $project->publicId, $project->name], [$base . '/' . $audit->publicId, 'Site audit'], [null, 'Checked pages']]]);
$self = $base . '/' . $audit->publicId . '/pages';
$path = static fn(string $url): string => (string) preg_replace('#^https?://[^/]+#', '', $url) ?: '/';
$stateLabels = ['blocked' => 'robots.txt', 'skipped' => 'not fetched'];
?>
<?= $view->partial('partials/project-header', ['project' => $project, 'active' => 'site-audit']) ?>
<div class="improve-workspace improve-audit">

<section class="card">
    <div class="card-header">
        <h2>Checked pages</h2>
        <a class="button button-ghost button-small" href="<?= $view->e($base . '/' . $audit->publicId) ?>">Back to the issues</a>
    </div>
    <p class="muted small">Audit of <?= $view->e($audit->createdAt) ?> UTC · <?= $view->e(number_format($audit->pagesCrawled)) ?> addresses</p>
    <div class="table-scroll">
        <table class="table table-dense audit-pages">
            <thead><tr><th scope="col">Page</th><th scope="col" class="num">Status</th><th scope="col">Title</th><th scope="col" class="num">Words</th><th scope="col" class="num">Links</th><th scope="col" class="num">Time</th><th scope="col" class="num">Depth</th></tr></thead>
            <tbody>
                <?php foreach ($pages as $row): ?>
                    <tr>
                        <td class="url-cell" title="<?= $view->e($row->url) ?>"><?= $view->e($path($row->url)) ?></td>
                        <td class="num">
                            <?php if ($row->statusCode !== null && $row->statusCode > 0): ?>
                                <span class="badge <?= $row->statusCode >= 400 ? 'badge-error' : ($row->statusCode >= 300 ? 'badge-warning' : 'badge-ok') ?>"><?= $view->e($row->statusCode) ?></span>
                            <?php else: ?>
                                <span class="badge badge-info" title="<?= $view->e($row->error ?? '') ?>"><?= $view->e($stateLabels[$row->state] ?? 'error') ?></span>
                            <?php endif; ?>
                        </td>
                        <td class="audit-title"><?= $row->redirectTo !== null ? '→ ' . $view->e($path($row->redirectTo)) : $view->e($row->title ?? '') ?></td>
                        <td class="num"><?= $row->wordCount !== null ? $view->e(number_format($row->wordCount)) : '' ?></td>
                        <td class="num"><?= $row->isHtml() ? $view->e($row->internalLinks) . ' / ' . $view->e($row->externalLinks) : '' ?></td>
                        <td class="num"><?= $row->durationMs !== null ? $view->e(number_format($row->durationMs)) . ' ms' : '' ?></td>
                        <td class="num"><?= $row->isLinked() ? $view->e($row->depth) : '<span class="muted" title="Not reachable by links from the home page">—</span>' ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <p class="small muted">Links: internal / external. Depth: clicks from the home page; — means the page is in the sitemap but not reachable by links from the home page.</p>
    <?php if ($pageCount > 1): ?>
        <nav class="pagination" aria-label="Pages">
            <?php if ($page > 1): ?><a class="button button-small button-ghost" href="<?= $view->e($self . ($page > 2 ? '?page=' . ($page - 1) : '')) ?>">Previous</a><?php endif; ?>
            <span class="muted small">Page <?= $view->e($page) ?> of <?= $view->e($pageCount) ?></span>
            <?php if ($page < $pageCount): ?><a class="button button-small button-ghost" href="<?= $view->e($self . '?page=' . ($page + 1)) ?>">Next</a><?php endif; ?>
        </nav>
    <?php endif; ?>
</section>

</div>
