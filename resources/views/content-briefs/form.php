<?php

declare(strict_types=1);

use SeoControlCenter\Modules\ContentBriefs\BriefInput;
$base = '/projects/' . $project->publicId . '/content-briefs';
$value = static fn(string $key): string => is_string($input[$key] ?? null) ? $input[$key] : '';
$view->extend('layouts/app', ['title' => ($brief === null ? 'Create' : 'Edit') . ' content brief · ' . $project->name]);
echo $view->partial('partials/project-header', ['project' => $project, 'active' => 'content-briefs']);
?>
<div class="improve-workspace improve-briefs">
<p><a href="<?= $view->e($base . ($brief === null ? '' : '/' . $brief->publicId)) ?>">Back to <?= $brief === null ? 'content briefs' : 'brief' ?></a></p>
<section class="card"><h2><?= $brief === null ? 'Create a content brief' : 'Edit content brief' ?></h2><p>Plan an existing page or a new one. Review every suggestion before sharing it with a writer. Saving is free and uses stored SEOcc evidence.</p>
<?php if (isset($errors['conflict'])): ?><p class="notice notice-error" role="alert"><?= $view->e($errors['conflict']) ?></p><p><a href="<?= $view->e($base . '/' . $brief->publicId) ?>">Open latest saved brief</a></p><?php endif; ?>
<?php $existing = array_values(array_filter($existing ?? [], static fn(array $r): bool => $r['article'])); if ($existing !== []): ?>
<div class="notice article-idea-inventory" role="note"><strong>Your content inventory already has this topic.</strong> Check these before planning a new page:
    <ul><?php foreach ($existing as $row): ?><li><?= $view->e($row['state_label']) ?>: <?= $row['url'] !== null ? '<a href="' . $view->e($row['url']) . '" rel="noopener noreferrer" target="_blank">' . $view->e($row['title'] ?? $row['url']) . '</a>' : $view->e((string) $row['title']) ?></li><?php endforeach; ?></ul>
    A brief for an existing page should name its address.</div>
<?php endif; ?>
<form method="post" action="<?= $view->e($base . ($brief === null ? '' : '/' . $brief->publicId)) ?>" class="stack">
<?= $view->csrfField() ?>
<?php foreach (['keyword_id', 'opportunity_id', 'revision'] as $key): ?><input type="hidden" name="<?= $view->e($key) ?>" value="<?= $view->e($value($key)) ?>"><?php endforeach; ?>
<div class="field"><label for="brief-title">Brief title</label><input id="brief-title" name="title" maxlength="160" required value="<?= $view->e($value('title')) ?>"<?= $view->invalid($errors, 'title', 'brief-title') ?>><?= $view->fieldError($errors, 'title', 'brief-title') ?></div>
<div class="brief-field-grid"><div class="field"><label for="brief-keyword">Target keyword</label><input id="brief-keyword" name="keyword" maxlength="200" required value="<?= $view->e($value('keyword')) ?>"<?= $view->invalid($errors, 'keyword', 'brief-keyword') ?>><?= $view->fieldError($errors, 'keyword', 'brief-keyword') ?></div><div class="field"><label for="brief-status">Workflow status</label><select id="brief-status" name="status"<?= $view->invalid($errors, 'status', 'brief-status') ?>><?php foreach (BriefInput::STATUSES as $key => $label): ?><option value="<?= $view->e($key) ?>"<?= $value('status') === $key ? ' selected' : '' ?>><?= $view->e($label) ?></option><?php endforeach; ?></select><?= $view->fieldError($errors, 'status', 'brief-status') ?></div></div>
<div class="field"><label for="brief-url">Exact page URL (optional for a new page)</label><input id="brief-url" name="url" type="url" maxlength="2048" value="<?= $view->e($value('url')) ?>"<?= $view->invalid($errors, 'url', 'brief-url') ?>><?= $view->fieldError($errors, 'url', 'brief-url') ?></div>
<p class="muted small">Published means you have already made the change in your CMS. It does not publish the page or create an annotation automatically.</p>
<?php foreach (BriefInput::FIELDS as $key => $label): $limit = match ($key) { 'seo_title' => 200, 'meta_description' => 500, default => 4000 }; ?><div class="field"><label for="brief-<?= $view->e($key) ?>"><?= $view->e($label) ?></label><textarea id="brief-<?= $view->e($key) ?>" name="<?= $view->e($key) ?>" rows="<?= in_array($key, ['seo_title', 'meta_description', 'intent'], true) ? 3 : 5 ?>" maxlength="<?= $limit ?>"<?= $view->invalid($errors, $key, 'brief-' . $key) ?>><?= $view->e($value($key)) ?></textarea><?= $view->fieldError($errors, $key, 'brief-' . $key) ?></div><?php endforeach; ?>
<div class="actions"><button type="submit" class="button button-primary"<?= isset($errors['conflict']) ? ' disabled' : '' ?>>Save content brief</button><a class="button button-ghost" href="<?= $view->e($base . ($brief === null ? '' : '/' . $brief->publicId)) ?>">Cancel</a></div>
</form></section>
<?= $view->partial('content-briefs/evidence', ['evidence' => $evidence, 'preview' => $brief === null]) ?>

</div>
