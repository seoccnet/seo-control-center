<?php

declare(strict_types=1);

use SeoControlCenter\Domain\Rankings\Volatility;
use SeoControlCenter\Modules\Rankings\RankingService;

/**
 * Ranking volatility (1.7.0).
 *
 * @var SeoControlCenter\Core\View\Template $view
 * @var SeoControlCenter\Domain\Projects\Project $project
 * @var SeoControlCenter\Domain\Rankings\SerpDevice $device
 * @var ?float $usual
 * @var ?array{date: string, pairs: int, moved: int, average: float, level: string, ratio: ?float} $latest
 * @var list<array{date: string, pairs: int, moved: int, average: float, level: string, ratio: ?float, updates: list<string>}> $days
 */

$base = '/projects/' . $project->publicId . '/rankings';
$view->extend('layouts/app', ['title' => 'Ranking volatility · ' . $project->name, 'crumbs' => [['/projects', 'Projects'], ['/projects/' . $project->publicId, $project->name], [$base, 'Rankings'], [null, 'Volatility']]]);
echo $view->partial('partials/project-header', ['project' => $project, 'active' => 'rankings']);
$labels = [Volatility::CALM => 'Calm', Volatility::MOVING => 'Moving', Volatility::TURBULENT => 'Turbulent', Volatility::UNKNOWN => 'Not enough history'];
$tone = [Volatility::CALM => 'badge badge-ok', Volatility::MOVING => 'badge badge-warning', Volatility::TURBULENT => 'badge badge-error', Volatility::UNKNOWN => 'badge'];
$widest = max([1.0, ...array_map(static fn(array $d): float => $d['average'], $days)]);
?>
<div class="improve-workspace">
<header class="improve-intro"><div><p class="portfolio-eyebrow"><a href="<?= $view->e($base) ?>">Rankings</a></p><h2>Ranking volatility</h2><p class="muted">How far your tracked keywords moved between two checks, on <?= $view->e($device->value) ?>. When most keywords move at once, the cause is usually Google: an update or a test. When only a few move, look at those pages instead.</p></div></header>

<?php if ($days === []): ?>
<section class="card empty">
    <h2>No movement measured yet</h2>
    <p>Volatility needs two checks of at least <?= $view->e((string) Volatility::MIN_PAIRS) ?> keywords on the same device, at most 8 days apart. Imported history is left out: it comes from other tools.</p>
    <p><a class="button button-secondary" href="<?= $view->e($base) ?>">Rankings</a></p>
</section>
<?php else: ?>
<div class="stat-grid">
    <section class="stat"><h2 class="stat-label">Latest check</h2><p class="stat-value"><span class="<?= $view->e($tone[$latest['level'] ?? Volatility::UNKNOWN]) ?>"><?= $view->e($labels[$latest['level'] ?? Volatility::UNKNOWN]) ?></span></p><p class="stat-note"><?= $view->e($latest['date'] ?? '') ?></p></section>
    <section class="stat"><h2 class="stat-label">Average move</h2><p class="stat-value"><?= $view->e(number_format($latest['average'] ?? 0.0, 2)) ?></p><p class="stat-note">places per keyword</p></section>
    <section class="stat"><h2 class="stat-label">Usual move</h2><p class="stat-value"><?= $usual !== null ? $view->e(number_format($usual, 2)) : '–' ?></p><p class="stat-note">median of the last <?= $view->e((string) RankingService::VOLATILITY_DAYS) ?> days</p></section>
    <section class="stat"><h2 class="stat-label">Keywords that moved</h2><p class="stat-value"><?= $view->e(number_format($latest['moved'] ?? 0)) ?> / <?= $view->e(number_format($latest['pairs'] ?? 0)) ?></p></section>
</div>
<?php if ($usual === null): ?>
<p class="notice">Days are rated once <?= $view->e((string) Volatility::MIN_DAYS) ?> checks show the project's usual movement.</p>
<?php endif; ?>

<section class="card" aria-labelledby="volatility-heading">
    <h2 id="volatility-heading">Movement per check</h2>
    <div class="table-scroll"><table class="table table-dense">
        <thead><tr><th scope="col">Date</th><th scope="col">Rating</th><th scope="col" class="numeric">Average move</th><th scope="col">Compared with usual</th><th scope="col" class="numeric">Moved</th><th scope="col">Google update rolling out</th></tr></thead>
        <tbody>
        <?php foreach ($days as $day): ?>
            <tr>
                <td><?= $view->e($day['date']) ?></td>
                <td><span class="<?= $view->e($tone[$day['level']]) ?>"><?= $view->e($labels[$day['level']]) ?></span></td>
                <td class="numeric"><?= $view->e(number_format($day['average'], 2)) ?></td>
                <td><meter min="0" max="100" value="<?= $view->e((string) round($day['average'] / $widest * 100)) ?>" aria-label="<?= $view->e(number_format($day['average'], 2)) ?> places"></meter><?= $day['ratio'] !== null ? ' <span class="muted small">' . $view->e(number_format($day['ratio'], 1)) . '×</span>' : '' ?></td>
                <td class="numeric"><?= $view->e(number_format($day['moved'])) ?> / <?= $view->e(number_format($day['pairs'])) ?></td>
                <td class="small"><?= $view->e(implode(', ', $day['updates'])) ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table></div>
    <p class="muted small">Average move: the mean number of places each keyword moved since its previous check. Positions beyond 20 count as 21, and keywords outside the top 20 on both checks are left out. <em>Moving</em> is at least 1.5 times the usual move, <em>turbulent</em> at least 2.5 times; moves under half a place are always calm.</p>
</section>
<?php endif; ?>
</div>
