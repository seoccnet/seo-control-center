<?php
/**
 * @var SeoControlCenter\Core\View\Template $view
 * @var SeoControlCenter\Domain\Projects\Project $project
 * @var SeoControlCenter\Modules\SiteAudit\SiteAudit|null $audit
 * @var SeoControlCenter\Modules\SiteAudit\SiteAudit|null $previous
 * @var list<SeoControlCenter\Modules\SiteAudit\SiteAudit> $history
 * @var SeoControlCenter\Modules\SiteAudit\SiteAuditSettings $settings
 * @var array<string, int> $counts
 * @var array<string, int>|null $previousCounts
 * @var list<array{pageId: int, url: string, type: SeoControlCenter\Modules\SiteAudit\IssueType, detail: ?string, sources: list<string>}> $issues
 * @var SeoControlCenter\Modules\SiteAudit\IssueType|null $type
 * @var int $page
 * @var int $pageCount
 * @var bool $canRun
 * @var bool $canEdit
 * @var bool $featureOn
 * @var bool $isLatest
 * @var array<string, string> $errors
 * @var array<string, mixed>|null $titleTriage
 */
use SeoControlCenter\Modules\SiteAudit\IssueType;
use SeoControlCenter\Modules\SiteAudit\SiteAuditSettings;

$view->extend('layouts/app', ['title' => 'Site audit · ' . $project->name, 'crumbs' => [['/projects', 'Projects'], ['/projects/' . $project->publicId, $project->name], [null, 'Site audit']]]);
$base = '/projects/' . $project->publicId . '/site-audit';
$self = $audit !== null && !$isLatest ? $base . '/' . $audit->publicId : $base;
$path = static fn(string $url): string => (string) preg_replace('#^https?://[^/]+#', '', $url) ?: '/';
$severityBadge = ['error' => 'badge-error', 'warning' => 'badge-warning', 'notice' => 'badge-info'];
$statusLabels = ['queued' => 'Waiting for cron', 'running' => 'Crawling', 'completed' => 'Completed', 'failed' => 'Failed', 'cancelled' => 'Stopped'];
$types = IssueType::cases();
usort($types, static fn(IssueType $a, IssueType $b): int => [$a->severityRank(), -($counts[$a->value] ?? 0)] <=> [$b->severityRank(), -($counts[$b->value] ?? 0)]);
$startForm = static function (string $label, bool $ghost = false) use ($view, $base): string {
    return '<form method="post" action="' . $view->e($base) . '" class="inline-form">' . $view->csrfField()
        . '<button type="submit" class="button button-small' . ($ghost ? ' button-ghost' : '') . '">' . $view->e($label) . '</button></form>';
};
?>
<?= $view->partial('partials/project-header', ['project' => $project, 'active' => 'site-audit']) ?>
<div class="improve-workspace improve-audit">
<header class="improve-intro"><div><p class="portfolio-eyebrow">Improve · Site audit</p><h2>Find and prioritize technical issues</h2><p class="muted">Review crawl results, inspect affected pages and compare findings with saved diagnostics.</p></div></header>
<?php if (!$featureOn): ?>
    <p class="notice notice-warning">Site audits are turned off on this installation. Existing results stay readable.</p>
<?php endif; ?>

<section class="card sync-status"<?= $audit !== null && $audit->isActive() ? ' data-refresh-seconds="10"' : '' ?>>
    <?php if ($audit === null): ?>
        <div class="sync-row">
            <strong>No audit yet</strong>
            <?php if ($canRun && $featureOn): ?><?= $startForm('Start audit') ?><?php endif; ?>
        </div>
        <p class="muted small">The audit crawls https://<?= $view->e($project->primaryDomain) ?>/ like a search engine: it reads your sitemap and follows the links between your pages (up to <?= $view->e($settings->maxPages) ?> pages), respects robots.txt and reports broken pages, redirects, missing or duplicate titles and descriptions, headings, slow pages and more. It runs in the background, one page at a time, so your server is not put under load.</p>
    <?php elseif ($audit->isActive()): ?>
        <div class="sync-row">
            <div>
                <strong><?= $view->e($statusLabels[$audit->status]) ?></strong>
                <span class="muted small">· <?= $view->e($audit->pagesCrawled) ?> of <?= $view->e($audit->pagesFound) ?> pages found so far checked (limit <?= $view->e($audit->maxPages) ?>) · started <?= $view->e($audit->createdAt) ?> UTC</span>
            </div>
            <?php if ($canRun): ?>
                <form method="post" action="<?= $view->e($base . '/' . $audit->publicId) ?>/cancel" class="inline-form" data-confirm="Stop this audit? The pages checked so far are discarded.">
                    <?= $view->csrfField() ?>
                    <button type="submit" class="button button-small button-ghost">Stop</button>
                </form>
            <?php endif; ?>
        </div>
        <progress max="<?= $view->e(max(1, $audit->pagesFound)) ?>" value="<?= $view->e($audit->pagesCrawled) ?>" class="audit-progress"><?= $view->e($audit->pagesCrawled) ?></progress>
        <p class="muted small">Pages are checked in the background with every cron run; this page updates itself. Results appear when the crawl is finished.</p>
    <?php else: ?>
        <div class="sync-row">
            <div>
                <strong><?= $view->e($statusLabels[$audit->status] ?? $audit->status) ?></strong>
                <span class="muted small">· <?= $view->e($audit->finishedAt ?? $audit->createdAt) ?> UTC · <?= $audit->trigger === 'scheduled' ? 'weekly audit' : 'started by hand' ?><?= !$isLatest ? ' · an earlier audit' : '' ?></span>
            </div>
            <div class="actions">
                <?php if (!$isLatest): ?><a class="button button-small button-ghost" href="<?= $view->e($base) ?>">Latest audit</a><?php endif; ?>
                <?php if ($canRun && $featureOn && $isLatest): ?><?= $startForm('Run again') ?><?php endif; ?>
            </div>
        </div>
        <?php if ($audit->error !== null): ?>
            <p class="notice notice-error"><?= $view->e($audit->error) ?></p>
        <?php endif; ?>
    <?php endif; ?>
</section>

<?php if ($audit !== null && $audit->isCompleted()): ?>
    <?php
    $scoreDelta = '';
    if ($previous !== null && $previous->healthScore !== null && $audit->healthScore !== null) {
        $diff = $audit->healthScore - $previous->healthScore;
        $scoreDelta = $diff === 0 ? '<span class="delta">no change</span>' : sprintf('<span class="delta %s">%s %+d</span>', $diff > 0 ? 'delta-good' : 'delta-bad', $diff > 0 ? '▲' : '▼', $diff);
    }
    ?>
<?php if ($type === null): ?><?php $issueBars=[]; foreach($counts as $issueKey=>$issueCount)if($issueCount>0 && IssueType::tryFrom($issueKey)!==null)$issueBars[]=['label'=>IssueType::from($issueKey)->label(),'value'=>$issueCount]; usort($issueBars,static fn($a,$b): int=>$b['value'] <=> $a['value']); ?>
<?= $view->partial('partials/insight-bars',['title'=>'Most frequent audit findings','eyebrow'=>'Crawl priorities','note'=>'Five most frequent issue types in this audit. A page can have several findings; these are occurrence counts.','bars'=>array_slice($issueBars,0,5)]) ?><?php endif; ?>
    <div class="kpis">
        <div class="kpi"><p class="kpi-label">Health score</p><p class="kpi-value"><?= $audit->healthScore !== null ? $view->e($audit->healthScore) . '<span class="muted small"> / 100</span>' : '—' ?></p><p class="kpi-note"><?= $scoreDelta !== '' ? $scoreDelta . ' since ' . $view->e(substr((string) $previous?->createdAt, 0, 10)) : 'Share of pages without errors and warnings' ?></p></div>
        <div class="kpi"><p class="kpi-label">Pages checked</p><p class="kpi-value"><?= $view->e(number_format($audit->pagesCrawled)) ?></p><p class="kpi-note"><?= $audit->pagesFound >= $audit->maxPages ? 'Page limit reached (' . $view->e($audit->maxPages) . ')' : 'All pages found in the sitemap and by following links' ?></p></div>
        <div class="kpi"><p class="kpi-label">Errors</p><p class="kpi-value"><?= $view->e(number_format($audit->errors)) ?></p><p class="kpi-note">Broken, failing or untitled pages</p></div>
        <div class="kpi"><p class="kpi-label">Warnings</p><p class="kpi-value"><?= $view->e(number_format($audit->warnings)) ?></p><p class="kpi-note"><?= $view->e(number_format($audit->notices)) ?> notices</p></div>
    </div>

    <section class="card">
        <div class="card-header">
            <h2>Issues by type</h2>
            <div class="actions">
                <a class="button button-ghost button-small" href="<?= $view->e($base . '/' . $audit->publicId) ?>/pages">All checked pages</a>
                <?php if ($counts !== []): ?><a class="button button-ghost button-small" href="<?= $view->e($base . '/' . $audit->publicId) ?>/export"><?= $view->icon('download') ?>Export CSV</a><?php endif; ?>
            </div>
        </div>
        <?php if ($counts === []): ?>
            <p class="muted">No issues found. Well done.</p>
        <?php else: ?>
            <div class="table-scroll">
                <table class="table table-dense">
                    <thead><tr><th scope="col">Issue</th><th scope="col">Severity</th><th scope="col" class="num">Pages</th><?php if ($previousCounts !== null): ?><th scope="col" class="num">Change</th><?php endif; ?></tr></thead>
                    <tbody>
                        <?php foreach ($types as $case): ?>
                            <?php $n = $counts[$case->value] ?? 0; $before = $previousCounts[$case->value] ?? 0; ?>
                            <?php if ($n === 0 && $before === 0) { continue; } ?>
                            <tr>
                                <td><?php if ($n > 0): ?><a href="<?= $view->e($self) ?>?type=<?= $view->e($case->value) ?>"><?= $view->e($case->label()) ?></a><?php else: ?><?= $view->e($case->label()) ?><?php endif; ?></td>
                                <td><span class="badge <?= $severityBadge[$case->severity()] ?>"><?= $view->e(ucfirst($case->severity())) ?></span></td>
                                <td class="num"><?= $view->e(number_format($n)) ?></td>
                                <?php if ($previousCounts !== null): ?>
                                    <td class="num"><?php if ($n === $before): ?><span class="delta">—</span><?php else: ?><span class="delta <?= $n < $before ? 'delta-good' : 'delta-bad' ?>"><?= $view->e(sprintf('%+d', $n - $before)) ?></span><?php endif; ?></td>
                                <?php endif; ?>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </section>

    <?php if (($titleTriage ?? null) !== null): ?>
        <?= $view->partial('site-audit/title-triage', ['base' => '/projects/' . $project->publicId, 'triage' => $titleTriage]) ?>
    <?php endif; ?>

    <?php if ($counts !== []): ?>
        <section class="card">
            <div class="card-header">
                <h2><?= $type !== null ? $view->e($type->label()) : 'All issues' ?></h2>
                <?php if ($type !== null): ?><a class="button button-ghost button-small" href="<?= $view->e($self) ?>">Show all</a><?php endif; ?>
            </div>
            <?php if ($type !== null): ?><p class="muted"><?= $view->e($type->advice()) ?></p><?php endif; ?>
            <div class="table-scroll">
                <table class="table table-dense">
                    <thead><tr><th scope="col">Page</th><?php if ($type === null): ?><th scope="col">Issue</th><?php endif; ?><th scope="col">Detail</th></tr></thead>
                    <tbody>
                        <?php foreach ($issues as $issue): ?>
                            <tr>
                                <td class="url-cell" title="<?= $view->e($issue['url']) ?>"><?= $view->e($path($issue['url'])) ?></td>
                                <?php if ($type === null): ?>
                                    <td><span class="badge <?= $severityBadge[$issue['type']->severity()] ?>"><?= $view->e($issue['type']->label()) ?></span></td>
                                <?php endif; ?>
                                <td>
                                    <?= $view->e($issue['detail'] ?? '') ?>
                                    <?php if ($issue['sources'] !== []): ?>
                                        <span class="small muted">· e.g. on <?= implode(', ', array_map(static fn(string $u): string => '<span title="' . $view->e($u) . '">' . $view->e($path($u)) . '</span>', $issue['sources'])) ?></span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php if ($pageCount > 1): ?>
                <nav class="pagination" aria-label="Pages">
                    <?php $q = static fn(int $p): string => '?' . http_build_query(array_filter(['type' => $type?->value, 'page' => $p > 1 ? $p : null])); ?>
                    <?php if ($page > 1): ?><a class="button button-small button-ghost" href="<?= $view->e($self . $q($page - 1)) ?>">Previous</a><?php endif; ?>
                    <span class="muted small">Page <?= $view->e($page) ?> of <?= $view->e($pageCount) ?></span>
                    <?php if ($page < $pageCount): ?><a class="button button-small button-ghost" href="<?= $view->e($self . $q($page + 1)) ?>">Next</a><?php endif; ?>
                </nav>
            <?php endif; ?>
        </section>
    <?php endif; ?>
<?php endif; ?>

<?php if (count($history) > 1 || ($history !== [] && !$history[0]->isActive())): ?>
    <section class="card">
        <h2>Audits</h2>
        <div class="table-scroll">
            <table class="table table-dense">
                <thead><tr><th scope="col">Started (UTC)</th><th scope="col">Kind</th><th scope="col">Status</th><th scope="col" class="num">Pages</th><th scope="col" class="num">Score</th><th scope="col" class="num">Errors</th><th scope="col" class="num">Warnings</th></tr></thead>
                <tbody>
                    <?php foreach ($history as $row): ?>
                        <tr<?= $audit !== null && $row->id === $audit->id ? ' aria-current="true"' : '' ?>>
                            <td><?php if ($row->isCompleted()): ?><a href="<?= $view->e($base . '/' . $row->publicId) ?>"><?= $view->e($row->createdAt) ?></a><?php else: ?><?= $view->e($row->createdAt) ?><?php endif; ?></td>
                            <td><?= $row->trigger === 'scheduled' ? 'Weekly' : 'By hand' ?></td>
                            <td><?= $view->e($statusLabels[$row->status] ?? $row->status) ?></td>
                            <td class="num"><?= $view->e(number_format($row->pagesCrawled)) ?></td>
                            <td class="num"><?= $row->healthScore !== null ? $view->e($row->healthScore) : '—' ?></td>
                            <td class="num"><?= $row->isCompleted() ? $view->e(number_format($row->errors)) : '—' ?></td>
                            <td class="num"><?= $row->isCompleted() ? $view->e(number_format($row->warnings)) : '—' ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <p class="small muted">The last <?= $view->e(SeoControlCenter\Modules\SiteAudit\SiteAuditService::KEEP_AUDITS) ?> finished audits are kept.</p>
    </section>
<?php endif; ?>

<section class="card"><h2>Core Web Vitals</h2><p>Mobile CrUX field data is separate from crawl response time. Open a URL under Pages to request a check.</p>
<?php foreach ($webVitals as $vital): ?><h3><a href="<?= $view->e('/projects/' . $project->publicId . '/pages/details?url=' . rawurlencode($vital->url)) ?>"><?= $view->e($vital->url) ?></a></h3><?= $view->partial('web-vitals/record', ['record' => $vital->toArray()]) ?><?php endforeach; ?>
<?php if ($webVitals === []): ?><p>No stored field data yet.</p><?php endif; ?></section>

<details class="card improve-limits"><summary>Crawl sampling &amp; coverage limits</summary><p class="muted small">Resource checks sample up to 15 HTML images/scripts/stylesheets and 5 external links per page, at most 500 unique targets per audit. HTTPS status probes respect robots.txt, read at most 1 KB and never follow redirects or execute JavaScript. Only 404/410 responses confirm a broken target. Samples are not a complete asset inventory; HTTP and blocked targets are not fetched.</p>
</details>
<section class="card">
    <h2>Settings</h2>
    <?php if ($canEdit): ?>
        <form method="post" action="<?= $view->e($base) ?>/settings" class="form" novalidate>
            <?= $view->csrfField() ?>
            <div class="field<?= isset($errors['max_pages']) ? ' has-error' : '' ?>">
                <label for="max_pages">Pages per audit</label>
                <select id="max_pages" name="max_pages">
                    <?php foreach (SiteAuditSettings::PAGE_LIMITS as $limit): ?>
                        <option value="<?= $view->e($limit) ?>"<?= $limit === $settings->maxPages ? ' selected' : '' ?>><?= $view->e(number_format($limit)) ?></option>
                    <?php endforeach; ?>
                </select>
                <?= $view->fieldError($errors, 'max_pages', 'max_pages') ?>
                <p class="small muted">The crawl stops after this many addresses. About 500 pages take 10 to 30 minutes, depending on your server and the cron interval.</p>
            </div>
            <label><input type="checkbox" name="weekly" value="1"<?= $settings->weekly ? ' checked' : '' ?>> Audit the site every week</label>
            <div class="actions">
                <button type="submit" class="button">Save settings</button>
            </div>
        </form>
    <?php else: ?>
        <p class="muted">Up to <?= $view->e($settings->maxPages) ?> pages per audit<?= $settings->weekly ? ', every week' : '' ?>. Project editors can change this.</p>
    <?php endif; ?>
    <p class="small muted">The crawler identifies itself as <code>SEO-Control-Center</code>. It only fetches pages on <?= $view->e($project->primaryDomain) ?> (with and without www) over HTTPS and follows the rules in your robots.txt for this name or for <code>*</code>.</p>
</section>

<?= $view->partial('site-audit/rendered', ['project' => $project, 'result' => $renderedResult, 'evidence' => $renderedEvidence, 'available' => $renderedAvailable, 'canRun' => $canRun && $featureOn, 'history' => $renderedHistory]) ?>

</div>
