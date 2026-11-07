<?php
/**
 * Local → ranking grid: the form and the newest grid of each keyword as a heatmap.
 *
 * @var SeoControlCenter\Core\View\Template $view
 * @var SeoControlCenter\Domain\Projects\Project $project
 * @var list<array{id: int, keyword: string, position: ?int, previous: ?int, checked: bool, top: list<SeoControlCenter\Domain\Local\LocalResult>}> $keywords
 * @var array<int, SeoControlCenter\Domain\Local\LocalGrid> $grids
 * @var bool $gridQueued
 * @var array<int, float> $gridCosts
 * @var bool $canCheck
 * @var array<string, string> $errors
 * @var array<string, string> $old
 */
use SeoControlCenter\Domain\Local\LocalGrid;

$self = '/projects/' . $project->publicId . '/local';
$names = array_column($keywords, 'keyword', 'id');
$tone = static fn(int|false|null $position): string => match (true) {
    $position === false => 'grid-pending',
    $position === null => 'grid-none',
    $position <= 3 => 'grid-top',
    $position <= 10 => 'grid-mid',
    default => 'grid-low',
};
?>
<section class="card" id="grid"<?= $gridQueued ? ' data-refresh-seconds="15"' : '' ?>>
    <h2>Ranking grid</h2>
    <p class="muted small">Your Google Maps position for one keyword at a grid of points around your location: it shows how far your visibility reaches. Green is the top 3, amber places 4–10, orange 11–20, red not found.</p>
    <?php if ($canCheck && $keywords !== []): ?>
        <form method="post" action="<?= $view->e($self) ?>/grid" class="filter-form" novalidate>
            <?= $view->csrfField() ?>
            <label class="filter-field"><span>Keyword</span>
                <select id="grid_keyword" name="grid_keyword"<?= $view->invalid($errors, 'grid_keyword', 'grid_keyword') ?>>
                    <?php foreach ($keywords as $k): ?>
                        <option value="<?= $view->e($k['keyword']) ?>"<?= ($old['grid_keyword'] ?? '') === $k['keyword'] ? ' selected' : '' ?>><?= $view->e($k['keyword']) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label class="filter-field"><span>Grid</span>
                <select name="grid_size">
                    <?php foreach (LocalGrid::SIZES as $size): ?>
                        <option value="<?= $view->e($size) ?>"<?= $size === 5 ? ' selected' : '' ?>><?= $view->e($size . ' × ' . $size) ?> (about <?= $view->e('$' . number_format($gridCosts[$size] ?? 0, 2)) ?>)</option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label class="filter-field"><span>Distance between points</span>
                <select name="grid_spacing">
                    <?php foreach (LocalGrid::SPACINGS as $metres => $label): ?>
                        <option value="<?= $view->e($metres) ?>"<?= $metres === 1000 ? ' selected' : '' ?>><?= $view->e($label) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <div class="filter-actions"><button type="submit" class="button button-small"<?= $gridQueued ? ' disabled' : '' ?>>Check grid</button></div>
        </form>
        <?= $view->fieldError($errors, 'grid_keyword', 'grid_keyword') ?>
    <?php endif; ?>
    <?php if ($gridQueued): ?>
        <p class="muted small">A grid is being checked; the points fill in with the next cron runs.</p>
    <?php endif; ?>
    <?php if ($grids === [] && !$gridQueued): ?>
        <p class="muted">No grid checked yet.</p>
    <?php endif; ?>
    <div class="grid-list">
        <?php foreach ($grids as $keywordId => $grid): ?>
            <figure class="grid-card">
                <figcaption>
                    <strong><?= $view->e($names[$keywordId] ?? '') ?></strong>
                    <span class="muted small"><?= $view->e($grid->size . ' × ' . $grid->size) ?>, <?= $view->e(LocalGrid::SPACINGS[$grid->spacing] ?? $grid->spacing . ' m') ?> apart · <?= $view->e($grid->checkedOn) ?><?= $grid->status === 'failed' ? ' · failed' : '' ?></span>
                </figcaption>
                <div class="rank-grid rank-grid-<?= (int) $grid->size ?>" role="img" aria-label="<?= $view->e(sprintf('Found at %d of %d points, average position %s', $grid->found(), $grid->points(), $grid->averagePosition() !== null ? number_format($grid->averagePosition(), 1) : '–')) ?>">
                    <?php foreach ($grid->rows() as $r => $row): ?>
                        <?php foreach ($row as $c => $position): ?>
                            <span class="grid-cell <?= $tone($position) ?><?= $r === intdiv($grid->size - 1, 2) && $c === intdiv($grid->size - 1, 2) ? ' grid-center' : '' ?>"><?= $position === false ? '·' : ($position === null ? '–' : $view->e($position)) ?></span>
                        <?php endforeach; ?>
                    <?php endforeach; ?>
                </div>
                <p class="small muted">Found at <?= $view->e($grid->found()) ?> of <?= $view->e(count($grid->positions)) ?> points<?= $grid->averagePosition() !== null ? ' · average ' . $view->e(number_format($grid->averagePosition(), 1)) : '' ?>. North is up; the outlined cell is your location.</p>
            </figure>
        <?php endforeach; ?>
    </div>
</section>

