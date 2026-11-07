<?php

declare(strict_types=1);

/**
 * The reviewed content of a change bundle (ADR-042, 0.40.0): problem, evidence, page, exact edits, expected effects, validation.
 *
 * @var SeoControlCenter\Core\View\Template $view
 * @var SeoControlCenter\Domain\Projects\Project $project
 * @var string $action
 * @var array{title: string, url: string, problem: string, evidence: string, edits: list<array{field: string, before: string, after: string}>, expected: string, validation: string, version: int} $value
 * @var string $submit
 * @var string|null $opportunity the opportunity a new bundle starts from (0.41.0)
 */

use SeoControlCenter\Domain\ChangeBundles\BundleRules;

$rows = [...$value['edits'], ...array_fill(0, max(2, 4 - count($value['edits'])), ['field' => '', 'before' => '', 'after' => ''])];
$rows = array_slice($rows, 0, BundleRules::MAX_EDITS);
?>
<form method="post" action="<?= $view->e($action) ?>" class="stack"><?= $view->csrfField() ?><input type="hidden" name="version" value="<?= (int) $value['version'] ?>"><?php if (($opportunity ?? null) !== null): ?><input type="hidden" name="opportunity" value="<?= $view->e((string) $opportunity) ?>"><?php endif; ?>
    <div class="field"><label for="bundle-title">Title</label><input id="bundle-title" name="title" required maxlength="200" value="<?= $view->e($value['title']) ?>" placeholder="Clearer title for the mold guide"></div>
    <div class="field"><label for="bundle-url">Page</label><input id="bundle-url" name="url" type="url" required maxlength="2048" value="<?= $view->e($value['url']) ?>" placeholder="<?= $view->e('https://' . $project->primaryDomain . '/') ?>"></div>
    <div class="field"><label for="bundle-problem">Problem</label><textarea id="bundle-problem" name="problem" rows="2" required maxlength="2000"><?= $view->e($value['problem']) ?></textarea></div>
    <div class="field"><label for="bundle-evidence">Evidence (sources, figures, links)</label><textarea id="bundle-evidence" name="evidence" rows="2" maxlength="4000"><?= $view->e($value['evidence']) ?></textarea></div>
    <fieldset class="bundle-edits"><legend>Exact edits (up to <?= BundleRules::MAX_EDITS ?>; empty rows are ignored)</legend>
    <?php foreach ($rows as $i => $row): $n = $i + 1; ?>
        <div class="bundle-edit-row">
            <div class="field"><label for="edit-field-<?= $n ?>">Edit <?= $n ?>: field</label><select id="edit-field-<?= $n ?>" name="edit_field[]"><option value="">—</option><?php foreach (BundleRules::FIELDS as $key => $label): ?><option value="<?= $view->e($key) ?>"<?= $row['field'] === $key ? ' selected' : '' ?>><?= $view->e($label) ?></option><?php endforeach; ?></select></div>
            <div class="field"><label for="edit-before-<?= $n ?>">Now</label><textarea id="edit-before-<?= $n ?>" name="edit_before[]" rows="2" maxlength="2000"><?= $view->e($row['before']) ?></textarea></div>
            <div class="field"><label for="edit-after-<?= $n ?>">New</label><textarea id="edit-after-<?= $n ?>" name="edit_after[]" rows="2" maxlength="2000"><?= $view->e($row['after']) ?></textarea></div>
        </div>
    <?php endforeach; ?>
    </fieldset>
    <div class="field"><label for="bundle-expected">Expected effects</label><textarea id="bundle-expected" name="expected" rows="2" required maxlength="2000"><?= $view->e($value['expected']) ?></textarea></div>
    <div class="field"><label for="bundle-validation">How to validate</label><textarea id="bundle-validation" name="validation" rows="2" required maxlength="2000"><?= $view->e($value['validation']) ?></textarea></div>
    <button class="button button-primary"><?= $view->e($submit) ?></button>
</form>
