<?php

declare(strict_types=1);

/**
 * Mobile against desktop rankings (1.6.0).
 *
 * @var SeoControlCenter\Core\View\Template $view
 * @var SeoControlCenter\Domain\Projects\Project $project
 * @var SeoControlCenter\Domain\Rankings\RankingSettings $settings
 * @var SeoControlCenter\Domain\Rankings\RankingDay|null $primary
 * @var SeoControlCenter\Domain\Rankings\RankingDay|null $other
 * @var list<array{keyword: SeoControlCenter\Domain\Rankings\RankedKeyword, primary: ?int, other: ?int, gap: ?int}> $rows
 * @var int $compared
 */

$base = '/projects/' . $project->publicId . '/rankings';
$view->extend('layouts/app', ['title' => 'Mobile vs desktop · ' . $project->name, 'crumbs' => [['/projects', 'Projects'], ['/projects/' . $project->publicId, $project->name], [$base, 'Rankings'], [null, 'Mobile vs desktop']]]);
echo $view->partial('partials/project-header', ['project' => $project, 'active' => 'rankings']);
$first = ucfirst($settings->device->value);
$second = ucfirst($settings->otherDevice()->value);
$pos = static fn(?int $p): string => $p === null ? '>100' : (string) $p;
?>
<div class="improve-workspace">
<header class="improve-intro"><div><p class="portfolio-eyebrow"><a href="<?= $view->e($base) ?>">Rankings</a></p><h2>Mobile vs desktop</h2><p class="muted">Keywords that rank 5 or more places apart on the two devices, or on one only. Large gaps on mobile usually point to the mobile page: layout, speed, intrusive pop-ups or content hidden on small screens.</p></div></header>

<?php if (!$settings->bothDevices && $other === null): ?>
<section class="card"><p>Only <?= $view->e($settings->device->value) ?> rankings are checked. Tick <em>Also check the other device</em> under <a href="<?= $view->e($base) ?>">Tracking settings</a> to compare.</p></section>
<?php else: ?>
<div class="stat-grid">
    <?php foreach ([[$first, $primary], [$second, $other]] as [$label, $day]): ?>
    <section class="stat"><h2 class="stat-label"><?= $view->e($label . ' visibility') ?></h2><p class="stat-value"><?= $view->e($day !== null ? number_format($day->visibility, 1) . ' %' : '—') ?></p><p class="stat-delta muted"><?= $view->e($day !== null ? 'Top 10: ' . $day->top10 . ' / ' . $day->keywords . ' · ' . $day->date : 'No check yet') ?></p></section>
    <?php endforeach; ?>
</div>
<section class="card" aria-labelledby="devices-heading">
    <h2 id="devices-heading">Keywords with a gap</h2>
    <p class="muted"><?= $view->e(number_format(count($rows))) ?> of <?= $view->e(number_format($compared)) ?> keywords checked on both devices.</p>
    <?php if ($rows !== []): ?>
    <div class="table-scroll"><table class="table table-dense numeric">
        <thead><tr><th scope="col">Keyword</th><th scope="col"><?= $view->e($first) ?></th><th scope="col"><?= $view->e($second) ?></th><th scope="col"><?= $view->e($second . ' vs ' . $first) ?></th><th scope="col">Ranking page (<?= $view->e($settings->device->value) ?>)</th></tr></thead>
        <tbody>
        <?php foreach ($rows as $row): ?>
            <tr>
                <th scope="row"><a href="<?= $view->e($base . '/' . $row['keyword']->publicId) ?>"><?= $view->e($row['keyword']->keyword) ?></a></th>
                <td><?= $view->e($pos($row['primary'])) ?></td>
                <td><?= $view->e($pos($row['other'])) ?></td>
                <td><?= $view->e($row['gap'] === null ? ($row['other'] === null ? 'Only ' . $settings->device->value : 'Only ' . $settings->otherDevice()->value) : ($row['gap'] > 0 ? $row['gap'] . ' places lower' : abs($row['gap']) . ' places higher')) ?></td>
                <td class="url-cell"><?= $view->e($row['keyword']->url ?? '—') ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table></div>
    <?php else: ?><p>No large gaps: the keywords rank about the same on both devices.</p><?php endif; ?>
</section>
<?php endif; ?>
</div>
