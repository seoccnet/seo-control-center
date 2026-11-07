<?php

declare(strict_types=1);

/**
 * One investigation with its dated entries.
 *
 * @var SeoControlCenter\Core\View\Template $view
 * @var SeoControlCenter\Domain\Projects\Project $project
 * @var SeoControlCenter\Domain\Investigations\Investigation $investigation
 * @var bool $canEdit
 * @var array{analyses: list<array{id: string, label: string}>, lookups: list<array{kind: string, subject: ?string, label: string}>} $choices
 * @var array<string, string> $errors
 * @var array<string, mixed> $old
 */

use SeoControlCenter\Domain\Investigations\Investigation;
use SeoControlCenter\Domain\Investigations\InvestigationItem;

$i = $investigation;
$base = '/projects/' . $project->publicId;
$self = $base . '/investigations/' . $i->publicId;
$view->extend('layouts/app', ['title' => $i->title . ' · Investigations']);
echo $view->partial('partials/project-header', ['project' => $project, 'active' => 'keywords']);
$value = static fn(string $key, ?string $default = null): string => is_string($old[$key] ?? null) ? $old[$key] : (string) $default;
$statusLabel = [Investigation::OPEN => 'Open', Investigation::CONCLUDED => 'Concluded', Investigation::ARCHIVED => 'Archived'];
$groups = [InvestigationItem::SOURCE => 'Sources', InvestigationItem::CHECK => 'Checks done', InvestigationItem::LIMITATION => 'Limitations', InvestigationItem::NOTE => 'Notes'];
$byKind = [];
foreach ($i->items as $item) {
    $byKind[$item->kind][] = $item;
}
$link = static function (InvestigationItem $item) use ($base): ?string {
    return match ($item->refType) {
        'analysis' => null,
        'opportunity' => $base . '/opportunities/' . $item->refId,
        'url', 'page' => $item->refId,
        default => null,
    };
};
?>
<div class="research-workspace">
<header class="research-intro"><div><p class="portfolio-eyebrow">Research · Investigation · <?= $view->e($statusLabel[$i->status] ?? $i->status) ?></p><h2><?= $view->e($i->title) ?></h2><?php if ($i->question !== null): ?><p><?= nl2br($view->e($i->question)) ?></p><?php endif; ?><p class="muted small">Started <?= $view->e(substr($i->createdAt, 0, 10)) ?> · updated <?= $view->e(substr($i->updatedAt, 0, 16)) ?> UTC<?= $i->via !== null ? ' · last changed via ' . $view->e($i->via) : '' ?></p></div><nav class="research-shortcuts" aria-label="Investigation"><a href="<?= $view->e($base . '/investigations') ?>">All investigations</a><a href="<?= $view->e($base . '/keywords/research/on-file') ?>">Research on file</a></nav></header>

<?php if ($i->conclusion !== null): ?>
<section class="card"><p class="portfolio-eyebrow">Conclusion</p><p><?= nl2br($view->e($i->conclusion)) ?></p></section>
<?php endif; ?>

<section class="card" id="entries">
    <div class="section-header"><div><p class="portfolio-eyebrow">Evidence</p><h3><?= count($i->items) ?> <?= count($i->items) === 1 ? 'entry' : 'entries' ?></h3></div></div>
    <?php if ($i->items === []): ?><p class="muted">Nothing added yet. Add an analysis or a lookup on file, or write down a source, a check or a limitation.</p><?php endif; ?>
    <?php foreach ($groups as $kind => $heading): ?><?php if (($byKind[$kind] ?? []) !== []): ?>
        <h4><?= $view->e($heading) ?></h4>
        <ul class="investigation-entries">
        <?php foreach ($byKind[$kind] as $item): ?><?php $href = $link($item); ?>
            <li><strong><?php if ($href !== null): ?><a href="<?= $view->e($href) ?>"<?= $item->refType === 'url' || $item->refType === 'page' ? ' rel="noopener noreferrer" target="_blank"' : '' ?>><?= $view->e($item->label) ?></a><?php else: ?><?= $view->e($item->label) ?><?php endif; ?></strong>
                <span class="muted small"><?= $item->observedOn !== null ? ' · observed ' . $view->e($item->observedOn) : '' ?><?= $item->via !== null ? ' · via ' . $view->e($item->via) : '' ?></span>
                <?php if ($item->detail !== null): ?><br><span class="small"><?= nl2br($view->e($item->detail)) ?></span><?php endif; ?>
                <?php if ($canEdit): ?><form method="post" action="<?= $view->e($self . '/items/' . $item->publicId . '/delete') ?>" class="inline-form"><?= $view->csrfField() ?><button type="submit" class="button button-small button-ghost" aria-label="Remove <?= $view->e($item->label) ?>">Remove</button></form><?php endif; ?></li>
        <?php endforeach; ?>
        </ul>
    <?php endif; ?><?php endforeach; ?>
</section>

<?php if ($canEdit): ?>
<section class="card">
    <div class="section-header"><div><p class="portfolio-eyebrow">Add</p><h3>Add evidence</h3></div></div>
    <?php if ($choices['analyses'] !== []): ?>
    <form method="post" action="<?= $view->e($self . '/items') ?>" class="inline-form"><?= $view->csrfField() ?><input type="hidden" name="from" value="analysis">
        <label for="add-analysis" class="small">Analysis</label><select id="add-analysis" name="analysis_id"><?php foreach ($choices['analyses'] as $a): ?><option value="<?= $view->e($a['id']) ?>"><?= $view->e($a['label']) ?></option><?php endforeach; ?></select>
        <button type="submit" class="button button-small">Add with its sources, checks and limitations</button></form>
    <?php endif; ?>
    <?php if ($choices['lookups'] !== []): ?>
    <form method="post" action="<?= $view->e($self . '/items') ?>" class="inline-form"><?= $view->csrfField() ?><input type="hidden" name="from" value="lookup">
        <label for="add-lookup" class="small">Lookup on file</label><select id="add-lookup" name="lookup"><?php foreach ($choices['lookups'] as $l): ?><option value="<?= $view->e($l['kind'] . '|' . (string) $l['subject']) ?>"><?= $view->e($l['label']) ?></option><?php endforeach; ?></select>
        <button type="submit" class="button button-small">Add as a source</button></form>
    <?php endif; ?>
    <form method="post" action="<?= $view->e($self . '/items') ?>" class="stack"><?= $view->csrfField() ?><input type="hidden" name="from" value="manual">
        <div class="annotation-form-grid">
            <div class="field"><label for="item-kind">Type</label><select id="item-kind" name="kind"<?= $view->invalid($errors, 'kind', 'item-kind') ?>><?php foreach ($groups as $kind => $heading): ?><option value="<?= $view->e($kind) ?>"<?= $value('kind', 'source') === $kind ? ' selected' : '' ?>><?= $view->e(rtrim($heading === 'Checks done' ? 'Check' : $heading, 's')) ?></option><?php endforeach; ?></select><?= $view->fieldError($errors, 'kind', 'item-kind') ?></div>
            <div class="field"><label for="item-observed">Observed on (optional)</label><input id="item-observed" name="observed_on" type="date" value="<?= $view->e($value('observed_on')) ?>"<?= $view->invalid($errors, 'observed_on', 'item-observed') ?>><?= $view->fieldError($errors, 'observed_on', 'item-observed') ?></div>
        </div>
        <div class="field"><label for="item-label">What is it?</label><input id="item-label" name="label" maxlength="255" required value="<?= $view->e($value('label')) ?>"<?= $view->invalid($errors, 'label', 'item-label') ?>><?= $view->fieldError($errors, 'label', 'item-label') ?></div>
        <div class="field"><label for="item-url">URL (optional)</label><input id="item-url" name="url" type="url" maxlength="2048" value="<?= $view->e($value('url')) ?>"<?= $view->invalid($errors, 'ref_id', 'item-url') ?>><?= $view->fieldError($errors, 'ref_id', 'item-url') ?></div>
        <div class="field"><label for="item-detail">Details (optional)</label><textarea id="item-detail" name="detail" rows="2" maxlength="2000"<?= $view->invalid($errors, 'detail', 'item-detail') ?>><?= $view->e($value('detail')) ?></textarea><?= $view->fieldError($errors, 'detail', 'item-detail') ?></div>
        <button type="submit" class="button button-small">Add entry</button>
        <?= isset($errors['items']) ? '<p class="field-error">' . $view->e($errors['items']) . '</p>' : '' ?>
    </form>
</section>

<section class="card">
    <details<?= isset($errors['title']) || isset($errors['conclusion']) || isset($errors['status']) || isset($errors['question']) ? ' open' : '' ?>><summary>Edit, conclude or archive</summary>
    <form method="post" action="<?= $view->e($self) ?>" class="stack"><?= $view->csrfField() ?><input type="hidden" name="version" value="<?= $i->revision ?>">
        <div class="field"><label for="edit-title">Title</label><input id="edit-title" name="title" maxlength="200" required value="<?= $view->e($value('title', $i->title)) ?>"<?= $view->invalid($errors, 'title', 'edit-title') ?>><?= $view->fieldError($errors, 'title', 'edit-title') ?></div>
        <div class="field"><label for="edit-question">Question or hypothesis</label><textarea id="edit-question" name="question" rows="2" maxlength="2000"<?= $view->invalid($errors, 'question', 'edit-question') ?>><?= $view->e($value('question', $i->question)) ?></textarea><?= $view->fieldError($errors, 'question', 'edit-question') ?></div>
        <div class="field"><label for="edit-status">Status</label><select id="edit-status" name="status"<?= $view->invalid($errors, 'status', 'edit-status') ?>><?php foreach ($statusLabel as $key => $label): ?><option value="<?= $view->e($key) ?>"<?= $value('status', $i->status) === $key ? ' selected' : '' ?>><?= $view->e($label) ?></option><?php endforeach; ?></select><?= $view->fieldError($errors, 'status', 'edit-status') ?></div>
        <div class="field"><label for="edit-conclusion">Conclusion</label><textarea id="edit-conclusion" name="conclusion" rows="4" maxlength="5000"<?= $view->invalid($errors, 'conclusion', 'edit-conclusion') ?>><?= $view->e($value('conclusion', $i->conclusion)) ?></textarea><?= $view->fieldError($errors, 'conclusion', 'edit-conclusion') ?></div>
        <button type="submit" class="button button-small">Save</button>
    </form></details>
</section>
<?php endif; ?>
</div>
