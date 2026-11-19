<?php

declare(strict_types=1);

use SeoControlCenter\Modules\Competitors\ShareOfVoiceService;

/**
 * Share of voice (1.8.0).
 *
 * @var SeoControlCenter\Core\View\Template $view
 * @var SeoControlCenter\Domain\Projects\Project $project
 * @var SeoControlCenter\Domain\Rankings\SerpDevice $device
 * @var int $competitors
 * @var ?string $latest
 * @var ?string $compare
 * @var int $measuredDays
 * @var int $checkDays
 * @var ?SeoControlCenter\Domain\Shared\DateRange $range
 * @var list<array{0: string, 1: float}> $series
 * @var list<array{domain: string, own: bool, visibility: float, share: float, previous: ?float}> $rows
 */

$base = '/projects/' . $project->publicId . '/competitors';
$view->extend('layouts/app', ['title' => 'Share of voice · ' . $project->name, 'crumbs' => [['/projects', 'Projects'], ['/projects/' . $project->publicId, $project->name], [$base, 'Competitors'], [null, 'Share of voice']]]);
echo $view->partial('partials/project-header', ['project' => $project, 'active' => 'competitors']);
?>
<div class="research-workspace">
<header class="research-intro"><div><p class="portfolio-eyebrow"><a href="<?= $view->e($base) ?>">Competitors</a></p><h2>Share of voice</h2><p class="muted">Of the clicks you and your competitors are estimated to win on your tracked keywords, the share each site wins (on <?= $view->e($device->value) ?>). Clicks are estimated from each position's usual click-through rate and the keyword's search volume. A share that grows means you are gaining on the competitors you track, even when the market as a whole moves.</p></div></header>

<?php if ($rows === []): ?>
<section class="card empty">
    <h2>Nothing to compare yet</h2>
    <?php if ($competitors === 0): ?>
    <p>Add at least one competitor. Their positions are read from the result pages your rank checks already fetch, at no extra cost.</p>
    <p><a class="button button-secondary" href="<?= $view->e($base) ?>">Add competitors</a></p>
    <?php else: ?>
    <p>Share of voice needs a rank check on which every competitor was measured. Competitors are measured with every rank check from the day they were added.</p>
    <p><a class="button button-secondary" href="/projects/<?= $view->e($project->publicId) ?>/rankings">Rankings</a></p>
    <?php endif; ?>
</section>
<?php else: ?>
<section class="card" aria-labelledby="sov-heading">
    <div class="card-header"><h2 id="sov-heading">Latest check · <?= $view->e((string) $latest) ?></h2></div>
    <div class="table-scroll"><table class="table table-dense">
        <thead><tr><th scope="col">Site</th><th scope="col" class="numeric">Share of voice</th><th scope="col">Share</th><th scope="col" class="numeric">Change<?= $compare !== null ? ' since ' . $view->e($compare) : '' ?></th><th scope="col" class="numeric">Visibility</th></tr></thead>
        <tbody>
        <?php foreach ($rows as $row): ?>
            <tr>
                <td><?= $view->e($row['domain']) ?><?= $row['own'] ? ' <span class="badge">You</span>' : '' ?></td>
                <td class="numeric"><?= $view->e(number_format($row['share'], 1)) ?> %</td>
                <td><meter min="0" max="100" value="<?= $view->e((string) $row['share']) ?>" aria-label="<?= $view->e(number_format($row['share'], 1)) ?> %"></meter></td>
                <td class="numeric"><?php if ($row['previous'] === null): ?>–<?php else: $diff = round($row['share'] - $row['previous'], 1); ?><span class="delta<?= $diff > 0 ? ' delta-good' : ($diff < 0 ? ' delta-bad' : '') ?>"><?= $diff === 0.0 ? 'no change' : $view->e(sprintf('%+.1f pts', $diff)) ?></span><?php endif; ?></td>
                <td class="numeric"><?= $view->e(number_format($row['visibility'], 1)) ?> %</td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table></div>
    <p class="muted small">Visibility: the share of the possible clicks a site wins on your keywords if ranking first everywhere were 100 %. Share of voice divides each site's visibility by the total of all sites listed, so adding or removing a competitor changes everyone's share.</p>
</section>

<?php if ($range !== null && count($series) > 1): ?>
<?= $view->partial('partials/line-chart', ['title' => 'Your share of voice', 'series' => $series, 'range' => $range, 'suffix' => ' %', 'wide' => true]) ?>
<script type="module" src="/assets/js/line-chart.js?v=<?= $view->e($assetVersion ?? '0') ?>"></script>
<?php endif; ?>
<?php if ($measuredDays < $checkDays): ?>
<p class="notice"><?= $view->e(number_format($checkDays - $measuredDays)) ?> of the <?= $view->e(number_format($checkDays)) ?> checks in the last <?= $view->e((string) ShareOfVoiceService::DAYS) ?> days are left out because a competitor was not measured on them yet (it was added later).</p>
<?php endif; ?>
<?php endif; ?>
</div>
