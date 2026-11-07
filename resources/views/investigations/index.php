<?php

declare(strict_types=1);

/**
 * Research → Investigations: saved research notebooks of a project.
 *
 * @var SeoControlCenter\Core\View\Template $view
 * @var SeoControlCenter\Domain\Projects\Project $project
 * @var list<SeoControlCenter\Domain\Investigations\Investigation> $investigations
 * @var int $total
 * @var ?string $filter
 * @var bool $canEdit
 * @var array<string, string> $errors
 * @var array<string, mixed> $old
 */

use SeoControlCenter\Domain\Investigations\Investigation;

$base = '/projects/' . $project->publicId . '/investigations';
$view->extend('layouts/app', ['title' => 'Investigations · ' . $project->name]);
echo $view->partial('partials/project-header', ['project' => $project, 'active' => 'keywords']);
$value = static fn(string $key): string => is_string($old[$key] ?? null) ? $old[$key] : '';
$statusLabel = [Investigation::OPEN => 'Open', Investigation::CONCLUDED => 'Concluded', Investigation::ARCHIVED => 'Archived'];
?>
<div class="research-workspace">
<header class="research-intro"><div><p class="portfolio-eyebrow">Research · Investigations</p><h2>Investigations</h2><p class="muted">Keep the evidence for one question together: the dated sources you used, the checks you made, what the data cannot show, and your conclusion. Adding stored analyses and lookups never buys new data.</p></div><nav class="research-shortcuts" aria-label="Research"><a href="<?= $view->e('/projects/' . $project->publicId . '/keywords/research/on-file') ?>">Research on file</a><a href="<?= $view->e('/projects/' . $project->publicId . '/keywords/topics') ?>">Topics & owners</a></nav></header>

<?php if ($canEdit): ?>
<section class="card">
    <form method="post" action="<?= $view->e($base) ?>" class="stack">
        <?= $view->csrfField() ?>
        <div class="field"><label for="investigation-title">What are you investigating?</label><input id="investigation-title" name="title" maxlength="200" required value="<?= $view->e($value('title')) ?>" placeholder="Why did sunflower seed traffic drop in October?"<?= $view->invalid($errors, 'title', 'investigation-title') ?>><?= $view->fieldError($errors, 'title', 'investigation-title') ?></div>
        <div class="field"><label for="investigation-question">Question or hypothesis (optional)</label><textarea id="investigation-question" name="question" rows="2" maxlength="2000"<?= $view->invalid($errors, 'question', 'investigation-question') ?>><?= $view->e($value('question')) ?></textarea><?= $view->fieldError($errors, 'question', 'investigation-question') ?></div>
        <button type="submit" class="button button-small">Start investigation</button>
    </form>
</section>
<?php endif; ?>

<section class="card">
    <div class="section-header"><div><p class="portfolio-eyebrow">Notebooks</p><h3><?= $total ?> <?= $total === 1 ? 'investigation' : 'investigations' ?></h3></div>
        <nav class="segmented" aria-label="Filter by status"><a href="<?= $view->e($base) ?>"<?= $filter === null ? ' aria-current="page"' : '' ?>>All</a><?php foreach ($statusLabel as $key => $label): ?><a href="<?= $view->e($base . '?status=' . $key) ?>"<?= $filter === $key ? ' aria-current="page"' : '' ?>><?= $view->e($label) ?></a><?php endforeach; ?></nav></div>
    <?php if ($investigations === []): ?>
        <div class="empty-state"><h3>No investigations yet</h3><p><?= $canEdit ? 'Start one above, or add a finished analysis from its page.' : 'Project editors can start one.' ?></p></div>
    <?php else: ?>
    <div class="table-scroll"><table class="table table-dense"><thead><tr><th scope="col">Investigation</th><th scope="col">Status</th><th scope="col" class="numeric">Sources</th><th scope="col" class="numeric">Checks</th><th scope="col" class="numeric">Limitations</th><th scope="col">Updated</th></tr></thead><tbody>
    <?php foreach ($investigations as $i): ?>
        <tr><td><a href="<?= $view->e($base . '/' . $i->publicId) ?>"><?= $view->e($i->title) ?></a><?= $i->via !== null ? ' <span class="badge">via ' . $view->e($i->via) . '</span>' : '' ?></td>
            <td><span class="badge<?= $i->status === Investigation::CONCLUDED ? ' badge-ok' : '' ?>"><?= $view->e($statusLabel[$i->status] ?? $i->status) ?></span></td>
            <td class="numeric"><?= (int) ($i->counts['source'] ?? 0) ?></td><td class="numeric"><?= (int) ($i->counts['check'] ?? 0) ?></td><td class="numeric"><?= (int) ($i->counts['limitation'] ?? 0) ?></td>
            <td class="small"><?= $view->e(substr($i->updatedAt, 0, 16)) ?> UTC</td></tr>
    <?php endforeach; ?>
    </tbody></table></div>
    <?php endif; ?>
</section>
</div>
