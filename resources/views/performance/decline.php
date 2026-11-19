<?php

declare(strict_types=1);

use SeoControlCenter\Modules\SearchConsole\SlowDeclineService;

/**
 * Slow decline (1.8.0).
 *
 * @var SeoControlCenter\Core\View\Template $view
 * @var SeoControlCenter\Domain\Projects\Project $project
 * @var bool $searchData
 * @var bool $enoughData
 * @var list<SeoControlCenter\Domain\Shared\DateRange> $periods
 * @var int $checked
 * @var int $lostClicks
 * @var array<string, int> $counts
 * @var list<array{url: string, clicks: list<int>, drop: float, lost: int, cause: string, positionBefore: float, positionNow: float, impressionsBefore: int, impressionsNow: int}> $rows
 */

$base = '/projects/' . $project->publicId;
$view->extend('layouts/app', ['title' => 'Slow decline · ' . $project->name, 'crumbs' => [['/projects', 'Projects'], ['/projects/' . $project->publicId, $project->name], [$base . '/performance', 'Performance'], [null, 'Slow decline']]]);
echo $view->partial('partials/project-header', ['project' => $project, 'active' => 'performance']);
$causes = [
    SlowDeclineService::RANKINGS => ['Lost positions', 'The page ranks lower than a year ago. Compare it with the pages now above it: newer information, more depth, better answers. Refresh the content and its date only when the content really changed.'],
    SlowDeclineService::DEMAND => ['Fewer searches', 'The page holds its position but is shown less often: people search less for its topics, or it ranks for fewer of them. Check whether the topic moved on and whether newer searches deserve a section.'],
    SlowDeclineService::CTR => ['Fewer clicks per view', 'The page is shown as often at a similar position but clicked less: AI Overviews, other result features or more attractive competitors take the clicks. Review the title and description.'],
];
$path = static function (string $url): string {
    $p = (string) parse_url($url, PHP_URL_PATH);
    $q = parse_url($url, PHP_URL_QUERY);

    return ($p !== '' ? $p : '/') . (is_string($q) ? '?' . $q : '');
};
?>
<div class="insight-workspace">
<header class="improve-intro"><div><p class="portfolio-eyebrow"><a href="<?= $view->e($base . '/performance') ?>">Performance</a></p><h2>Slow decline</h2><p class="muted">Pages whose clicks fell quarter after quarter over the last year, by at least <?= $view->e((string) (int) (SlowDeclineService::MIN_DROP * 100)) ?> % overall, without a single sudden drop. Sudden drops show up in alerts; a slow slide is easy to miss and usually means the content is ageing. Each page needed at least <?= $view->e((string) SlowDeclineService::MIN_CLICKS) ?> clicks in the first quarter.</p></div></header>

<?php if (!$searchData || !$enoughData): ?>
<section class="card empty">
    <h2><?= !$searchData ? 'Connect Search Console first' : 'Not enough history yet' ?></h2>
    <p>The comparison needs a full year of Search Console data<?= $periods !== [] ? ', from ' . $view->e($periods[0]->fromString()) : '' ?>. SEOcc imports up to 16 months when a property is linked.</p>
    <p><a class="button button-secondary" href="<?= $view->e($base . '/performance') ?>">Performance</a></p>
</section>
<?php else: ?>
<div class="stat-grid">
    <section class="stat"><h2 class="stat-label">Pages declining</h2><p class="stat-value"><?= $view->e(number_format(array_sum($counts))) ?></p><p class="stat-note">of <?= $view->e(number_format($checked)) ?> with clicks</p></section>
    <section class="stat"><h2 class="stat-label">Clicks lost per quarter</h2><p class="stat-value"><?= $view->e(number_format($lostClicks)) ?></p><p class="stat-note">first against last quarter</p></section>
    <?php foreach ($causes as $key => [$label]): ?>
    <section class="stat"><h2 class="stat-label"><?= $view->e($label) ?></h2><p class="stat-value"><?= $view->e(number_format($counts[$key])) ?></p></section>
    <?php endforeach; ?>
</div>

<?php if ($rows === []): ?>
<section class="card empty"><h2>No slow decline found</h2><p>No page lost clicks quarter after quarter. Pages that dropped suddenly are not listed here.</p></section>
<?php else: ?>
<section class="card" aria-labelledby="decline-heading">
    <h2 id="decline-heading">Declining pages</h2>
    <div class="table-scroll"><table class="table table-dense">
        <thead><tr><th scope="col">Page</th><th scope="col">Likely cause</th>
            <?php foreach ($periods as $period): ?><th scope="col" class="numeric">Clicks <?= $view->e(substr($period->fromString(), 0, 7)) ?> – <?= $view->e(substr($period->toString(), 0, 7)) ?></th><?php endforeach; ?>
            <th scope="col" class="numeric">Drop</th><th scope="col" class="numeric">Position</th><th scope="col" class="numeric">Impressions</th></tr></thead>
        <tbody>
        <?php foreach ($rows as $row): ?>
            <tr>
                <td class="small"><a href="<?= $view->e($row['url']) ?>" rel="noopener noreferrer" target="_blank"><?= $view->e($path($row['url'])) ?></a></td>
                <td><span class="badge"><?= $view->e($causes[$row['cause']][0]) ?></span></td>
                <?php foreach ($row['clicks'] as $clicks): ?><td class="numeric"><?= $view->e(number_format($clicks)) ?></td><?php endforeach; ?>
                <td class="numeric"><span class="delta delta-bad">−<?= $view->e(number_format($row['drop'], 0)) ?> %</span></td>
                <td class="numeric"><?= $view->e(number_format($row['positionBefore'], 1)) ?> → <?= $view->e(number_format($row['positionNow'], 1)) ?></td>
                <td class="numeric"><?= $view->e(number_format($row['impressionsBefore'])) ?> → <?= $view->e(number_format($row['impressionsNow'])) ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table></div>
</section>
<section class="card" aria-labelledby="decline-causes">
    <h2 id="decline-causes">What the causes mean</h2>
    <dl>
    <?php foreach ($causes as [$label, $advice]): ?>
        <dt><?= $view->e($label) ?></dt><dd class="muted"><?= $view->e($advice) ?></dd>
    <?php endforeach; ?>
    </dl>
    <p class="muted small">Position and impressions: the first quarter against the last. The cause is a first guess from these numbers; open the page in Performance for its queries.</p>
</section>
<?php endif; ?>
<?php endif; ?>
</div>
