<?php
declare(strict_types=1);
/** @var list<array<string, string|null>> $records */
?>
<details class="annotation-context"><summary>SEO change context · <?= count($records) ?> notes</summary>
<p class="muted small">Most recent 100 notes within the displayed dates. These manually recorded changes are context, not proof of causation.</p>
<?php if ($records === []): ?><p>No recorded changes in this period.</p><?php endif; ?>
<ol class="annotation-timeline"><?php foreach ($records as $record): ?><li class="annotation-entry"><time><?= $view->e($record['date']) ?></time> · <strong><?= $view->e($record['title']) ?></strong><p class="url-cell muted small"><?= $view->e($record['url'] ?? 'Site-wide') ?></p><?php if ($record['note'] !== ''): ?><p class="annotation-note"><?= $view->e($record['note']) ?></p><?php endif; ?><p><a href="<?= $view->e('/projects/' . $project->publicId . '/annotations/' . $record['annotation_id'] . '/impact') ?>">Review impact</a></p></li><?php endforeach; ?></ol>
<a href="<?= $view->e('/projects/' . $project->publicId . '/annotations') ?>">View or record changes</a>
</details>
