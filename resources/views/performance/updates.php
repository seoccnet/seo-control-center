<?php

declare(strict_types=1);

/**
 * How Google's ranking updates moved the site (1.6.0).
 *
 * @var SeoControlCenter\Core\View\Template $view
 * @var SeoControlCenter\Domain\Projects\Project $project
 * @var array{first: string, last: string}|null $span
 * @var list<array{slug: string, name: string, start: string, end: ?string, status: string, before: ?SeoControlCenter\Domain\SearchConsole\Metrics, after: ?SeoControlCenter\Domain\SearchConsole\Metrics}> $updates
 * @var array<string, mixed>|null $selected
 */

use SeoControlCenter\Domain\SearchConsole\Metrics;
use SeoControlCenter\Domain\SearchConsole\UpdateImpact;

$base = '/projects/' . $project->publicId . '/performance';
$view->extend('layouts/app', ['title' => 'Google updates · ' . $project->name, 'crumbs' => [['/projects', 'Projects'], ['/projects/' . $project->publicId, $project->name], [$base, 'Performance'], [null, 'Google updates']]]);
echo $view->partial('partials/project-header', ['project' => $project, 'active' => 'performance']);
$number = static fn(int $n): string => number_format($n);
$change = static function (int|float $before, int|float $after): string {
    $percent = UpdateImpact::percent($before, $after);

    return $percent === null ? '–' : ($percent > 0 ? '+' : '') . number_format($percent, 1) . ' %';
};
$statusText = ['rolling_out' => 'Still rolling out', 'waiting' => 'Waiting for four weeks of data after the rollout', 'incomplete' => 'Search Console data incomplete around the update'];
?>
<div class="insight-workspace">
<header class="improve-intro"><div><p class="portfolio-eyebrow"><a href="<?= $view->e($base) ?>">Performance</a></p><h2>Google updates</h2><p class="muted">For each confirmed Google ranking update, the four weeks before the rollout compared with the four weeks after it. The rollout itself is left out, as rankings swing while it runs. Google Search Console data you imported; nothing is fetched.</p></div></header>

<?php if ($span === null): ?>
<section class="card"><p>No Search Console data yet. <a href="<?= $view->e('/projects/' . $project->publicId . '/search-console') ?>">Link a property</a> and import it first.</p></section>
<?php else: ?>
<section class="card" aria-labelledby="updates-heading">
    <h2 id="updates-heading">Updates since <?= $view->e($span['first']) ?></h2>
    <?php if ($updates === []): ?><p>No confirmed Google update falls within your imported data.</p><?php else: ?>
    <div class="table-scroll"><table class="table table-dense numeric">
        <thead><tr><th scope="col">Update</th><th scope="col">Rollout</th><th scope="col">Clicks before → after</th><th scope="col">Change</th><th scope="col">Impressions change</th><th scope="col">Position before → after</th></tr></thead>
        <tbody>
        <?php foreach ($updates as $u): ?>
            <tr>
                <th scope="row"><?php if ($u['status'] === 'complete'): ?><a href="<?= $view->e($base . '/updates?update=' . $u['slug']) ?>"><?= $view->e($u['name']) ?></a><?php else: ?><?= $view->e($u['name']) ?><?php endif; ?></th>
                <td><?= $view->e($u['start'] . ' to ' . ($u['end'] ?? '…')) ?></td>
                <?php if ($u['before'] instanceof Metrics && $u['after'] instanceof Metrics): ?>
                <td><?= $view->e($number($u['before']->clicks) . ' → ' . $number($u['after']->clicks)) ?></td>
                <td><?= $view->e($change($u['before']->clicks, $u['after']->clicks)) ?></td>
                <td><?= $view->e($change($u['before']->impressions, $u['after']->impressions)) ?></td>
                <td><?= $view->e(number_format($u['before']->position, 1) . ' → ' . number_format($u['after']->position, 1)) ?></td>
                <?php else: ?>
                <td colspan="4" class="muted"><?= $view->e($statusText[$u['status']] ?? '') ?></td>
                <?php endif; ?>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table></div>
    <p class="muted small">A change around an update is a correlation, not proof: seasonality, your own changes and competitors move traffic too. Compare with the same weeks a year earlier and with your <a href="<?= $view->e('/projects/' . $project->publicId . '/annotations') ?>">change timeline</a>.</p>
    <?php endif; ?>
</section>

<?php if (is_array($selected)): ?>
<section class="card" aria-labelledby="update-detail-heading">
    <h2 id="update-detail-heading"><?= $view->e((string) $selected['name']) ?></h2>
    <p class="muted"><?= $view->e($selected['beforeRange']->fromString() . ' to ' . $selected['beforeRange']->toString()) ?> against <?= $view->e($selected['afterRange']->toString() === '' ? '' : $selected['afterRange']->fromString() . ' to ' . $selected['afterRange']->toString()) ?>. Rows with fewer than 3 clicks in both periods are left out.</p>
    <?php foreach (['pages' => 'Pages', 'queries' => 'Queries'] as $key => $label): ?>
    <div class="brief-section-grid">
        <?php foreach (['winners' => 'gained most', 'losers' => 'lost most'] as $side => $title): $rows = $selected[$key][$side]; ?>
        <div>
            <h3><?= $view->e($label . ' that ' . $title) ?></h3>
            <?php if ($rows === []): ?><p class="muted">None.</p><?php else: ?>
            <div class="table-scroll"><table class="table table-dense numeric">
                <thead><tr><th scope="col"><?= $view->e($key === 'pages' ? 'Page' : 'Query') ?></th><th scope="col">Clicks</th><th scope="col">Change</th><th scope="col">Position</th></tr></thead>
                <tbody><?php foreach ($rows as $row): ?><tr><td class="url-cell"><?= $view->e($row['key']) ?></td><td><?= $view->e($number($row['before']->clicks) . ' → ' . $number($row['after']->clicks)) ?></td><td><?= $view->e(($row['delta'] > 0 ? '+' : '') . $number($row['delta'])) ?></td><td><?= $view->e(($row['before']->impressions > 0 ? number_format($row['before']->position, 1) : '–') . ' → ' . ($row['after']->impressions > 0 ? number_format($row['after']->position, 1) : '–')) ?></td></tr><?php endforeach; ?></tbody>
            </table></div>
            <?php endif; ?>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endforeach; ?>
    <p class="muted small">Core updates reassess quality and relevance across a whole site. Look for what the losing pages share (topic, template, age, thin or overlapping content) rather than fixing pages one by one.</p>
</section>
<?php endif; ?>
<?php endif; ?>
</div>
