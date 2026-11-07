<?php

declare(strict_types=1);

/**
 * Manage → Change bundles (ADR-042, 0.40.0).
 *
 * @var SeoControlCenter\Core\View\Template $view
 * @var SeoControlCenter\Domain\Projects\Project $project
 * @var list<array{public_id: string, title: string, url: string, status: string, version: int, created_at: string, updated_at: string, data: array<string, mixed>}> $bundles
 * @var bool $canEdit
 * @var array{value: array{title: string, url: string, problem: string, evidence: string, edits: list<array{field: string, before: string, after: string}>, expected: string, validation: string, version: int}, source: array<string, mixed>}|null $draft
 */

use SeoControlCenter\Domain\ChangeBundles\BundleRules;

$base = '/projects/' . $project->publicId . '/change-bundles';
$view->extend('layouts/app', ['title' => 'Change bundles · ' . $project->name]);
echo $view->partial('partials/project-header', ['project' => $project, 'active' => 'change-bundles']);
$badge = static fn(string $status): string => match ($status) {
    'verified' => 'badge badge-ok',
    'approved', 'deployed' => 'badge badge-info',
    'partially_deployed', 'rolled_back' => 'badge badge-warning',
    default => 'badge',
};
?>
<div class="manage-workspace">
<header class="workspace-intro"><p class="portfolio-eyebrow">Manage · Change bundles</p><h2>Change bundles</h2><p class="muted">A bundle is a reviewed set of exact edits to one page, with the problem, the evidence, what you expect and how to check it. SEOcc never edits your site. It captures the live page as a baseline, binds the approval to it, checks before deployment that the live page has not changed since (the version guard), records what you deployed in your CMS, and reads the edits back from the live page.</p></header>

<section class="card" aria-labelledby="bundles-title">
    <h2 id="bundles-title">Bundles</h2>
    <?php if ($bundles === []): ?><p class="muted">No bundles yet.</p><?php else: ?>
    <div class="table-scroll"><table class="table table-dense" data-no-table-controls>
        <thead><tr><th scope="col">Bundle</th><th scope="col">Page</th><th scope="col">Status</th><th scope="col">Updated</th></tr></thead>
        <tbody><?php foreach ($bundles as $bundle): ?><tr>
            <td><a href="<?= $view->e($base . '/' . $bundle['public_id']) ?>"><?= $view->e($bundle['title']) ?></a></td>
            <td class="small key-cell" title="<?= $view->e($bundle['url']) ?>"><?= $view->e((string) parse_url($bundle['url'], PHP_URL_PATH)) ?></td>
            <td><span class="<?= $badge($bundle['status']) ?>"><?= $view->e(BundleRules::STATUSES[$bundle['status']] ?? $bundle['status']) ?></span></td>
            <td class="small cell-nowrap"><?= $view->e(substr($bundle['updated_at'], 0, 16)) ?> UTC</td>
        </tr><?php endforeach; ?></tbody>
    </table></div>
    <?php endif; ?>
</section>

<?php if ($canEdit): ?>
<section class="card" id="new-bundle" aria-labelledby="bundle-new-title">
    <h2 id="bundle-new-title">New bundle</h2>
    <?php if ($draft !== null): ?><p class="notice small" role="note">Started from an opportunity: its finding, evidence and action plan are filled in. Enter the exact new text for each edit. When you record the deployment, SEOcc drafts a measurement plan for <?= $view->e(SeoControlCenter\Domain\Measurement\MeasurementResult::METRICS[$draft['source']['metric']] ?? 'the metric') ?> on the recorded change.</p><?php endif; ?>
    <?= $view->partial('change-bundles/_form', ['project' => $project, 'action' => $base, 'value' => $draft['value'] ?? ['title' => '', 'url' => '', 'problem' => '', 'evidence' => '', 'edits' => [], 'expected' => '', 'validation' => '', 'version' => 0], 'submit' => 'Save draft', 'opportunity' => $draft['source']['opportunity_id'] ?? null]) ?>
</section>
<?php endif; ?>
</div>
