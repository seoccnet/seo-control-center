<?php
/**
 * @var SeoControlCenter\Core\View\Template $view
 * @var SeoControlCenter\Domain\Projects\Project $project
 * @var SeoControlCenter\Domain\Rankings\RankingSettings $settings
 * @var SeoControlCenter\Domain\Rankings\RankingSyncState|null $sync
 * @var int $keywordCount
 * @var bool $canManage
 * @var bool $providerAvailable
 * @var float $costPerCheck
 * @var SeoControlCenter\Domain\Rankings\RankingDay|null $latest
 * @var SeoControlCenter\Domain\Rankings\RankingDay|null $compare
 * @var list<SeoControlCenter\Domain\Rankings\RankingDay> $chart
 * @var list<SeoControlCenter\Domain\Rankings\RankingMove> $winners
 * @var list<SeoControlCenter\Domain\Rankings\RankingMove> $losers
 * @var list<SeoControlCenter\Domain\Rankings\RankedKeyword> $keywords
 * @var SeoControlCenter\Domain\Rankings\PositionFilter|null $filter
 * @var int $page
 * @var int $pages
 * @var array<string, string> $errors
 * @var string|null $assetVersion
 */
use SeoControlCenter\Domain\Integrations\BudgetPolicy;
use SeoControlCenter\Domain\Rankings\PositionFilter;

$view->extend('layouts/app', ['title' => 'Rankings · ' . $project->name, 'crumbs' => [['/projects', 'Projects'], ['/projects/' . $project->publicId, $project->name], [null, 'Rankings']]]);
$base = '/projects/' . $project->publicId;
$position = static fn(?int $p): string => $p === null ? '>100' : (string) $p;

/** Change as HTML: $good decides the colour, the arrow shows the direction of the value. */
$delta = static function (float $diff, string $text, bool $good) use ($view): string {
    if (abs($diff) < 0.005) {
        return '<span class="delta">no change</span>';
    }

    return sprintf('<span class="delta %s">%s %s</span>', $good ? 'delta-good' : 'delta-bad', $diff > 0 ? '▲' : '▼', $view->e($text));
};
$moveText = static fn(int $change): string => sprintf('%+d', $change);
$featureLabels = ['featured_snippet' => 'Featured snippet', 'people_also_ask' => 'People also ask', 'local_pack' => 'Local pack', 'images' => 'Images', 'video' => 'Videos', 'top_stories' => 'Top stories', 'ai_overview' => 'AI overview', 'shopping' => 'Shopping'];
?>
<?php
if (!$settings->enabled) {
    [$meta, $metaStatus] = ['Google ' . $settings->device->value . ' · tracking is off', 'warn'];
} elseif ($sync !== null && $sync->status === 'error') {
    [$meta, $metaStatus] = ['Google ' . $settings->device->value . ' · last check failed', 'error'];
} elseif ($sync !== null && in_array($sync->status, ['pending', 'running'], true)) {
    [$meta, $metaStatus] = ['Google ' . $settings->device->value . ' · check in progress', 'ok'];
} else {
    [$meta, $metaStatus] = ['Google ' . $settings->device->value . ' · ' . $settings->frequency . ($sync?->lastCheckedOn !== null ? ' · last check ' . $sync->lastCheckedOn . ' (UTC)' : ''), 'ok'];
}
$actions = null;
if ($canManage && $settings->enabled && $providerAvailable && $keywordCount > 0) {
    $actions = '<form method="post" action="' . $view->e($base) . '/rankings/check" class="inline-form">' . $view->csrfField()
        . '<button type="submit" class="button">' . $view->icon('refresh') . 'Check now</button></form>';
}
?>
<?= $view->partial('partials/project-header', ['project' => $project, 'active' => 'rankings', 'meta' => $meta, 'metaStatus' => $metaStatus, 'actions' => $actions]) ?>
<div class="research-workspace research-rankings">
<header class="research-intro"><div><p class="portfolio-eyebrow">Research · Rankings</p><h2>Search ranking performance</h2><p class="muted">Follow visibility, keyword movement and the latest results from your rank checks.</p></div></header>

<?php if ($sync !== null && $sync->status === 'error' && $sync->lastError !== null): ?>
    <p class="notice notice-error" role="alert">Last check failed: <?= $view->e($sync->lastError) ?></p>
<?php endif; ?>
<?php if ($keywordCount === 0): ?>
    <section class="card empty">
        <h2>No keywords yet</h2>
        <p>Rank tracking checks the project's keywords. <a href="<?= $view->e($base) ?>/keywords">Add keywords</a> first.</p>
    </section>
<?php endif; ?>

<?php if ($canManage): ?>
    <details class="card settings-card"<?= !$settings->enabled || $errors !== [] ? ' open' : '' ?>>
        <summary><h2>Tracking settings</h2><span class="muted small"><?= $view->e($settings->enabled ? 'On · ' . $settings->device->value . ' · ' . $settings->frequency : 'Off') ?></span></summary>
        <form method="post" action="<?= $view->e($base) ?>/rankings/settings" class="form" novalidate>
            <?= $view->csrfField() ?>
            <div class="field<?= isset($errors['enabled']) ? ' has-error' : '' ?>">
                <label><input type="checkbox" name="enabled" value="1"<?= $settings->enabled ? ' checked' : '' ?><?= $view->invalid($errors, 'enabled', 'enabled') ?>> Track rankings of this project's keywords</label>
                <?= $view->fieldError($errors, 'enabled', 'enabled') ?>
            </div>
            <div class="field-pair">
                <div class="field<?= isset($errors['device']) ? ' has-error' : '' ?>">
                    <label for="device">Device</label>
                    <select id="device" name="device">
                        <option value="desktop"<?= $settings->device->value === 'desktop' ? ' selected' : '' ?>>Desktop</option>
                        <option value="mobile"<?= $settings->device->value === 'mobile' ? ' selected' : '' ?>>Mobile</option>
                    </select>
                </div>
                <div class="field<?= isset($errors['frequency']) ? ' has-error' : '' ?>">
                    <label for="frequency">Frequency</label>
                    <select id="frequency" name="frequency">
                        <option value="daily"<?= $settings->frequency === 'daily' ? ' selected' : '' ?>>Daily</option>
                        <option value="weekly"<?= $settings->frequency === 'weekly' ? ' selected' : '' ?>>Weekly</option>
                    </select>
                </div>
            </div>
            <p class="muted small">
                Each check requests the top 100 Google results for every keyword (<?= $view->e(number_format($keywordCount)) ?> keyword<?= $keywordCount === 1 ? '' : 's' ?>),
                estimated at up to $<?= $view->e(BudgetPolicy::money($costPerCheck)) ?> per check; DataForSEO's reported costs are shown under
                <a href="/integrations/dataforseo">Integrations → DataForSEO</a> and the workspace budgets apply.
            </p>
            <div class="actions"><button type="submit" class="button">Save settings</button></div>
        </form>
    </details>
<?php endif; ?>

<?php if ($latest === null): ?>
    <section class="card">
        <h2>No rankings yet</h2>
        <p class="muted">Rankings appear here after the first check<?= $settings->enabled ? ', which runs in the background within the next few minutes' : '. Switch tracking on to start' ?>.</p>
    </section>
<?php else: ?>
<?= $view->partial('partials/insight-bars',['title'=>'Where your keywords rank','eyebrow'=>'Ranking coverage','note'=>'Exclusive groups from the latest recorded check. Outside the top 10 includes keywords not found in the checked results.','bars'=>[['label'=>'Positions 1–3','value'=>$latest->top3],['label'=>'Positions 4–10','value'=>max(0,$latest->top10-$latest->top3)],['label'=>'Outside top 10 or not found','value'=>max(0,$latest->keywords-$latest->top10)]]]) ?>
    <div class="stat-grid">
        <?php
        $since = $compare !== null ? 'vs ' . $compare->date : '';
        $tiles = [
            ['Visibility', number_format($latest->visibility, 1) . ' %', $compare !== null ? $delta($latest->visibility - $compare->visibility, sprintf('%+.1f pts', $latest->visibility - $compare->visibility), $latest->visibility > $compare->visibility) : ''],
            ['Average position', $latest->averagePosition !== null ? number_format($latest->averagePosition, 1) : '—', $compare?->averagePosition !== null && $latest->averagePosition !== null
                ? $delta($latest->averagePosition - $compare->averagePosition, sprintf('%+.1f', $latest->averagePosition - $compare->averagePosition), $latest->averagePosition < $compare->averagePosition) : ''],
            ['Top 3', $latest->top3 . ' / ' . $latest->keywords, $compare !== null ? $delta($latest->top3 - $compare->top3, sprintf('%+d', $latest->top3 - $compare->top3), $latest->top3 > $compare->top3) : ''],
            ['Top 10', $latest->top10 . ' / ' . $latest->keywords, $compare !== null ? $delta($latest->top10 - $compare->top10, sprintf('%+d', $latest->top10 - $compare->top10), $latest->top10 > $compare->top10) : ''],
        ];
        ?>
        <?php foreach ($tiles as [$label, $value, $change]): ?>
            <section class="stat">
                <h2 class="stat-label"><?= $view->e($label) ?></h2>
                <p class="stat-value"><?= $view->e($value) ?></p>
                <?php if ($change !== ''): ?><p class="stat-delta"><?= $change ?> <span class="muted"><?= $view->e($since) ?></span></p><?php endif; ?>
            </section>
        <?php endforeach; ?>
    </div>
    <p class="muted small">Check of <?= $view->e($latest->date) ?> (UTC). Visibility estimates the share of possible clicks, weighted by search volume: 100 % means position 1 for every keyword.</p>

    <?php if (count($chart) >= 2): ?>
        <?= $view->partial('partials/line-chart', [
            'title' => 'Visibility (%)',
            'series' => array_map(static fn($d): array => [$d->date, round($d->visibility, 2)], $chart),
            'range' => new SeoControlCenter\Domain\Shared\DateRange($chart[0]->date, $latest->date),
            'suffix' => ' %',
            'wide' => true,
        ]) ?>
        <script type="module" src="/assets/js/line-chart.js?v=<?= $view->e($assetVersion ?? '0') ?>"></script>
    <?php endif; ?>

    <?php if ($compare !== null): ?>
        <div class="table-grid">
            <?php foreach ([['Winners', $winners, 'No keyword moved up.'], ['Losers', $losers, 'No keyword moved down.']] as [$title, $moves, $empty]): ?>
                <section class="card">
                    <h2><?= $view->e($title) ?> <span class="muted small">since <?= $view->e($compare->date) ?></span></h2>
                    <?php if ($moves === []): ?>
                        <p class="muted"><?= $view->e($empty) ?></p>
                    <?php else: ?>
                        <table class="table table-dense numeric">
                            <thead><tr><th scope="col">Keyword</th><th scope="col">From</th><th scope="col">To</th><th scope="col">Change</th></tr></thead>
                            <tbody>
                                <?php foreach ($moves as $move): ?>
                                    <tr>
                                        <td><?= $view->e($move->keyword) ?></td>
                                        <td><?= $view->e($position($move->from)) ?></td>
                                        <td><?= $view->e($position($move->to)) ?></td>
                                        <td><?= $delta($move->change(), $moveText($move->change()), $move->change() > 0) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php endif; ?>
                </section>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
<?php endif; ?>

<?php if ($keywordCount > 0): ?>
    <section class="card">
        <div class="card-header">
            <h2>Keywords</h2>
            <?php if ($latest !== null): ?><a class="button button-ghost button-small" href="<?= $view->e($base . '/rankings/export' . ($filter !== null ? '?filter=' . $filter->value : '')) ?>"><?= $view->icon('download') ?>Export CSV</a><?php endif; ?>
        </div>
        <?php if ($latest !== null): ?>
        <nav class="filter-row" aria-label="Filter by position">
            <a class="chip" href="<?= $view->e($base) ?>/rankings"<?= $filter === null ? ' aria-current="true"' : '' ?>>All</a>
            <?php foreach (PositionFilter::cases() as $case): ?>
                <a class="chip" href="<?= $view->e($base) ?>/rankings?filter=<?= $view->e($case->value) ?>"<?= $filter === $case ? ' aria-current="true"' : '' ?>><?= $view->e($case->label()) ?></a>
            <?php endforeach; ?>
        </nav>
        <?php endif; ?>
        <?php if ($keywords === []): ?><p class="muted">No keyword matches this filter in the latest check.</p><?php endif; ?>
        <div class="table-scroll">
            <table class="table table-dense numeric">
                <thead>
                    <tr>
                        <th scope="col">Keyword</th>
                        <th scope="col">Position</th>
                        <th scope="col">Change</th>
                        <th scope="col">Best</th>
                        <th scope="col">Volume</th>
                        <th scope="col">Ranking URL</th>
                        <th scope="col">SERP features</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($keywords as $k): ?>
                        <tr>
                            <td><a href="<?= $view->e($base) ?>/rankings/<?= $view->e($k->publicId) ?>"><?= $view->e($k->keyword) ?></a> <span class="muted small"><?= $view->e($k->locale->label()) ?></span></td>
                            <td><?= $k->checkedOn === null ? '<span class="muted">not checked</span>' : $view->e($position($k->position)) ?></td>
                            <td><?= $k->change() !== null ? $delta($k->change(), $moveText($k->change()), $k->change() > 0) : '—' ?></td>
                            <td><?= $k->bestPosition !== null ? $view->e($k->bestPosition) : '—' ?></td>
                            <td><?= $k->searchVolume !== null ? $view->e(number_format($k->searchVolume)) : '—' ?></td>
                            <td class="muted small"><?= $k->url !== null ? $view->e(mb_strimwidth((string) (parse_url($k->url, PHP_URL_PATH) ?: '/'), 0, 60, '…')) : '—' ?></td>
                            <td class="muted small"><?= $view->e(implode(', ', array_map(static fn(string $f): string => $featureLabels[$f] ?? str_replace('_', ' ', $f), $k->features))) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php if ($pages > 1): ?>
            <nav class="pagination" aria-label="Pages">
                <?php if ($page > 1): ?><a class="button button-small button-ghost" href="<?= $view->e($base) ?>/rankings?page=<?= $view->e($page - 1) ?><?= $filter !== null ? '&amp;filter=' . $view->e($filter->value) : '' ?>">Previous</a><?php endif; ?>
                <span class="muted small">Page <?= $view->e($page) ?> of <?= $view->e($pages) ?></span>
                <?php if ($page < $pages): ?><a class="button button-small button-ghost" href="<?= $view->e($base) ?>/rankings?page=<?= $view->e($page + 1) ?><?= $filter !== null ? '&amp;filter=' . $view->e($filter->value) : '' ?>">Next</a><?php endif; ?>
            </nav>
        <?php endif; ?>
    </section>
<?php endif; ?>

</div>
