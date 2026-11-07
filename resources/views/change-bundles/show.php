<?php

declare(strict_types=1);

/**
 * Manage → Change bundles → one bundle (ADR-042, 0.40.0): baseline, approval, version guard, deployment record, verification, rollback.
 *
 * @var SeoControlCenter\Core\View\Template $view
 * @var SeoControlCenter\Domain\Projects\Project $project
 * @var array{public_id: string, title: string, url: string, status: string, version: int, created_at: string, updated_at: string, data: array<string, mixed>} $bundle
 * @var list<array{field: string, before: string, after: string}> $edits
 * @var list<array{kind: string, detail: string, created_at: string}> $events
 * @var string $hash
 * @var ?string $block
 * @var string $today
 * @var bool $canEdit
 */

use SeoControlCenter\Domain\ChangeBundles\BundleRules;

$d = $bundle['data'];
$base = '/projects/' . $project->publicId . '/change-bundles/' . $bundle['public_id'];
$view->extend('layouts/app', ['title' => $bundle['title'] . ' · Change bundles · ' . $project->name]);
echo $view->partial('partials/project-header', ['project' => $project, 'active' => 'change-bundles']);
$status = $bundle['status'];
$pending = is_string($d['pending'] ?? null) ? $d['pending'] : null;
$checks = is_array($d['verification']['checks'] ?? null) ? $d['verification']['checks'] : [];
$applied = is_array($d['deployment']['applied'] ?? null) ? $d['deployment']['applied'] : [];
$button = static fn(string $purpose, string $label) => '<form method="post" action="' . $view->e($base . '/check') . '">' . $view->csrfField() . '<input type="hidden" name="version" value="' . (int) $bundle['version'] . '"><input type="hidden" name="purpose" value="' . $view->e($purpose) . '"><button class="button button-secondary button-small"' . ($pending !== null ? ' disabled' : '') . '>' . $view->e($label) . '</button></form>';
?>
<div class="manage-workspace">
<header class="workspace-intro"><p class="portfolio-eyebrow">Manage · Change bundles</p><h2><?= $view->e($bundle['title']) ?></h2>
    <p><span class="badge"><?= $view->e(BundleRules::STATUSES[$status] ?? $status) ?></span> <a href="<?= $view->e($bundle['url']) ?>" rel="noopener noreferrer" target="_blank" class="small"><?= $view->e($bundle['url']) ?></a></p>
    <p class="small"><a href="<?= $view->e('/projects/' . $project->publicId . '/change-bundles') ?>">All change bundles</a><?php if (is_string($d['annotation_id'] ?? null)): ?> · <a href="<?= $view->e('/projects/' . $project->publicId . '/annotations/' . $d['annotation_id'] . '/impact') ?>">Recorded change, its impact and measurement plan</a><?php endif; ?></p>
</header>

<?php if ($pending !== null): ?><section class="card"><p role="status">A live check (<?= $view->e($pending) ?>) is queued. Its result appears after cron runs; reload the page in a few minutes.</p></section><?php endif; ?>
<?php if (is_array($d['check_error'] ?? null)): ?><section class="card"><p class="notice notice-warning small" role="status">The last live read (<?= $view->e((string) ($d['check_error']['purpose'] ?? '')) ?>) failed: <?= $view->e((string) ($d['check_error']['message'] ?? '')) ?></p></section><?php endif; ?>

<section class="card" aria-labelledby="bundle-what">
    <h2 id="bundle-what">What and why</h2>
    <dl class="lab-config small">
        <dt>Problem</dt><dd><?= $view->e((string) ($d['problem'] ?? '')) ?></dd>
        <dt>Evidence</dt><dd><?= $view->e(($d['evidence'] ?? '') !== '' ? (string) $d['evidence'] : 'None recorded') ?></dd>
        <dt>Expected effects</dt><dd><?= $view->e((string) ($d['expected'] ?? '')) ?></dd>
        <dt>How to validate</dt><dd><?= $view->e((string) ($d['validation'] ?? '')) ?></dd>
    </dl>
    <div class="table-scroll"><table class="table table-dense" data-no-table-controls><caption class="small muted">Exact edits</caption>
        <thead><tr><th scope="col">Field</th><th scope="col">Now</th><th scope="col">New</th><?php if ($applied !== []): ?><th scope="col">Deployed</th><?php endif; ?><?php if ($checks !== []): ?><th scope="col">Live check</th><?php endif; ?></tr></thead>
        <tbody><?php foreach ($edits as $i => $edit): $check = $checks[$i] ?? null; ?><tr>
            <th scope="row"><?= $view->e(BundleRules::FIELDS[$edit['field']] ?? $edit['field']) ?></th>
            <td class="small"><?= $view->e($edit['before'] !== '' ? $edit['before'] : '—') ?></td>
            <td class="small"><?= $view->e($edit['after']) ?></td>
            <?php if ($applied !== []): ?><td class="small"><?= ($applied[$i] ?? false) ? 'Applied' : 'Not applied' ?></td><?php endif; ?>
            <?php if ($checks !== []): ?><td class="small"><?php if (is_array($check)): ?><span class="<?= ($check['status'] ?? '') === 'matched' ? 'badge badge-ok' : (($check['status'] ?? '') === 'mismatch' ? 'badge badge-warning' : 'badge') ?>"><?= $view->e(BundleRules::CHECK_STATUSES[$check['status'] ?? ''] ?? '') ?></span><?= is_string($check['live'] ?? null) ? ' ' . $view->e($check['live']) : '' ?><?php endif; ?></td><?php endif; ?>
        </tr><?php endforeach; ?></tbody>
    </table></div>
</section>

<section class="card" aria-labelledby="bundle-steps">
    <h2 id="bundle-steps">Steps</h2>
    <ol class="bundle-steps">
        <li><h3>Live baseline</h3>
            <?php if (is_array($d['baseline'] ?? null)): ?><p class="small">Captured <?= $view->e((string) $d['baseline_at']) ?> UTC: HTTP <?= $view->e(BundleRules::display($d['baseline']['status'] ?? null)) ?>, title "<?= $view->e(BundleRules::display($d['baseline']['title'] ?? null)) ?>", <?= $view->e(BundleRules::display($d['baseline']['words'] ?? null)) ?> words.</p><?php else: ?><p class="small muted">Not captured yet. It records the page as it is before the edits.</p><?php endif; ?>
            <?php if ($canEdit && in_array($status, ['draft', 'approved'], true)): ?><?= $button('baseline', is_array($d['baseline'] ?? null) ? 'Capture again' : 'Capture live baseline') ?><?php endif; ?>
        </li>
        <li><h3>Approval</h3>
            <?php if (is_string($d['approved_hash'] ?? null)): ?><p class="small">Approved <?= $view->e((string) $d['approved_at']) ?> UTC for content and baseline hash <span class="mono"><?= $view->e(substr($d['approved_hash'], 0, 12)) ?></span><?= $d['approved_hash'] === $hash ? '' : ' — the bundle changed since' ?>.</p><?php else: ?><p class="small muted">Not approved. The approval covers this exact content and baseline (hash <span class="mono"><?= $view->e(substr($hash, 0, 12)) ?></span>); any edit clears it.</p><?php endif; ?>
            <?php if ($canEdit && $status === 'draft'): ?><form method="post" action="<?= $view->e($base . '/approve') ?>"><?= $view->csrfField() ?><input type="hidden" name="version" value="<?= (int) $bundle['version'] ?>"><button class="button button-secondary button-small"<?= is_array($d['baseline'] ?? null) ? '' : ' disabled' ?>>Approve</button></form><?php endif; ?>
        </li>
        <li><h3>Version guard</h3>
            <?php if (is_array($d['guard'] ?? null)): ?>
                <p class="small"><?= ($d['guard']['matches'] ?? false) ? '<span class="badge badge-ok">Matches</span>' : '<span class="badge badge-warning">Changed</span>' ?> Checked <?= $view->e((string) ($d['guard']['checked_at'] ?? '')) ?> UTC.</p>
                <?php if (is_array($d['guard']['changes'] ?? null) && $d['guard']['changes'] !== []): ?><ul class="small"><?php foreach ($d['guard']['changes'] as $field => $change): ?><li><?= $view->e((BundleRules::GUARDED[$field] ?? (string) $field) . ': ' . ($change['before'] ?? '') . ' → ' . ($change['after'] ?? '')) ?></li><?php endforeach; ?></ul><?php endif; ?>
            <?php else: ?><p class="small muted">Before you deploy, SEOcc reads the live page again and compares it with the approved baseline. A deployment can only be recorded after a matching check from the last <?= BundleRules::GUARD_HOURS ?> hours.</p><?php endif; ?>
            <?php if ($canEdit && $status === 'approved'): ?><?= $button('guard', 'Check the live page') ?><?php endif; ?>
        </li>
        <li><h3>Deployment</h3>
            <?php if (is_array($d['deployment'] ?? null)): ?><p class="small">Recorded for <?= $view->e((string) ($d['deployment']['deployed_on'] ?? '')) ?><?= ($d['deployment']['note'] ?? '') !== '' ? ': ' . $view->e((string) $d['deployment']['note']) : '' ?>.</p>
            <?php elseif ($status === 'approved'): ?>
                <?php if ($block !== null): ?><p class="small" role="status"><strong>Blocked:</strong> <?= $view->e($block) ?></p><?php endif; ?>
                <?php if ($canEdit && $block === null): ?>
                <p class="small muted">Make the edits in your CMS, then record here which were applied. SEOcc adds the change to the change timeline, where a measurement plan can follow.</p>
                <form method="post" action="<?= $view->e($base . '/deploy') ?>" class="stack"><?= $view->csrfField() ?><input type="hidden" name="version" value="<?= (int) $bundle['version'] ?>">
                    <fieldset><legend class="small">Applied edits</legend><?php foreach ($edits as $i => $edit): ?><label class="small"><input type="checkbox" name="applied[]" value="<?= $i ?>" checked> <?= $view->e((BundleRules::FIELDS[$edit['field']] ?? $edit['field']) . ': ' . mb_substr($edit['after'], 0, 80)) ?></label><br><?php endforeach; ?></fieldset>
                    <div class="inline-form"><label for="deployed-on" class="small">Day deployed</label><input type="date" id="deployed-on" name="deployed_on" value="<?= $view->e($today) ?>" max="<?= $view->e($today) ?>"></div>
                    <div class="field"><label for="deploy-note">Note (what failed, if anything)</label><input id="deploy-note" name="note" maxlength="2000"></div>
                    <button class="button button-primary button-small">Record deployment</button>
                </form>
                <?php endif; ?>
            <?php else: ?><p class="small muted">Recorded after approval and a matching live check.</p><?php endif; ?>
        </li>
        <li><h3>Verification</h3>
            <?php if (is_array($d['verification'] ?? null)): ?><p class="small">Read back <?= $view->e((string) ($d['verification']['checked_at'] ?? '')) ?> UTC; see the live check column above. Body text and other edits are checked by hand.</p><?php else: ?><p class="small muted">After deployment, SEOcc reads the title, description, main heading, canonical and noindex back from the live page.</p><?php endif; ?>
            <?php if ($canEdit && in_array($status, ['deployed', 'partially_deployed', 'verified'], true)): ?><?= $button('verify', 'Verify on the live page') ?><?php endif; ?>
        </li>
        <li><h3>Rollback</h3>
            <?php if (is_array($d['rollback'] ?? null)): ?><p class="small">Recorded for <?= $view->e((string) ($d['rollback']['rolled_back_on'] ?? '')) ?>: <?= $view->e((string) ($d['rollback']['note'] ?? '')) ?></p>
            <?php elseif ($canEdit && in_array($status, ['deployed', 'partially_deployed', 'verified'], true)): ?>
            <form method="post" action="<?= $view->e($base . '/rollback') ?>" class="stack"><?= $view->csrfField() ?><input type="hidden" name="version" value="<?= (int) $bundle['version'] ?>">
                <div class="field"><label for="rollback-note">What was rolled back and why</label><input id="rollback-note" name="note" required minlength="3" maxlength="2000"></div>
                <div class="inline-form"><label for="rolled-back-on" class="small">Day rolled back</label><input type="date" id="rolled-back-on" name="rolled_back_on" value="<?= $view->e($today) ?>" max="<?= $view->e($today) ?>"></div>
                <button class="button button-ghost button-small">Record rollback</button>
            </form>
            <?php else: ?><p class="small muted">Only after a deployment.</p><?php endif; ?>
        </li>
    </ol>
</section>

<?php if ($canEdit && in_array($status, ['draft', 'approved'], true)): ?>
<section class="card" aria-labelledby="bundle-edit-title">
    <details><summary id="bundle-edit-title" class="button button-small button-secondary">Edit the bundle<?= $status === 'approved' ? ' (clears the approval)' : '' ?></summary>
    <?= $view->partial('change-bundles/_form', ['project' => $project, 'action' => $base, 'value' => ['title' => $bundle['title'], 'url' => $bundle['url'], 'problem' => (string) ($d['problem'] ?? ''), 'evidence' => (string) ($d['evidence'] ?? ''), 'edits' => $edits, 'expected' => (string) ($d['expected'] ?? ''), 'validation' => (string) ($d['validation'] ?? ''), 'version' => $bundle['version']], 'submit' => 'Save']) ?>
    </details>
    <form method="post" action="<?= $view->e($base . '/delete') ?>" data-confirm="Delete this bundle and its history? It was never deployed."><?= $view->csrfField() ?><input type="hidden" name="version" value="<?= (int) $bundle['version'] ?>"><button class="button button-ghost button-small">Delete bundle</button></form>
    <p class="muted small">Only bundles that were never deployed can be deleted; a deployed bundle stays as the record of what changed.</p>
</section>
<?php endif; ?>

<section class="card" aria-labelledby="bundle-history">
    <h2 id="bundle-history">History</h2>
    <ol class="indexing-timeline small"><?php foreach ($events as $event): ?><li><span class="mono"><?= $view->e($event['created_at']) ?></span> <?= $view->e($event['detail']) ?></li><?php endforeach; ?></ol>
</section>
</div>
