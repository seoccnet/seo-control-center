<?php
/**
 * DataForSEO spending in the top bar (Modules\DataForSEO\SpendWidget).
 *
 * @var SeoControlCenter\Core\View\Template $view
 * @var SeoControlCenter\Domain\Integrations\SpendForecast $forecast
 * @var string|null $balanceCheckedAt
 * @var bool $canManage
 */
$money = static fn(float $amount): string => '$' . number_format($amount, $amount >= 100 ? 0 : 2);
$f = $forecast;
$status = $f->status();
$share = $f->monthShare();
$fill = $share !== null ? (int) min(100, round($share * 20) * 5) : null;
$peak = max([0.0001, ...array_values($f->daily)]);
$balanceDays = $f->balanceDays();
$statusText = match ($status) {
    'over' => 'At this pace a limit will be reached this month.',
    'warn' => 'Close to a limit at this pace.',
    default => null,
};
?>
<details class="topbar-menu spend spend-<?= $view->e($status) ?>" data-topbar-menu>
    <summary class="spend-chip" title="DataForSEO spending this month and the forecast for the month">
        <span class="spend-name">DataForSEO</span>
        <strong><?= $view->e($money($f->month)) ?></strong>
        <span class="spend-forecast">→ <?= $view->e($money($f->projectedMonth)) ?></span>
        <?php if ($fill !== null): ?>
            <span class="spend-meter" aria-hidden="true"><span class="spend-fill w-<?= $fill ?>"></span></span>
        <?php endif; ?>
    </summary>
    <div class="topbar-panel" role="dialog" aria-label="DataForSEO spending">
        <h2>DataForSEO spending</h2>
        <?php if ($statusText !== null): ?>
            <p class="notice notice-<?= $status === 'over' ? 'error' : 'warning' ?> small"><?= $view->e($statusText) ?></p>
        <?php endif; ?>
        <dl class="spend-stats">
            <div><dt>Today</dt><dd><?= $view->e($money($f->today)) ?></dd></div>
            <div><dt>This month</dt><dd><?= $view->e($money($f->month)) ?> <span class="muted small"><?= $view->e(number_format($f->requestsMonth)) ?> requests</span></dd></div>
            <div><dt>Expected by month end</dt><dd><strong><?= $view->e($money($f->projectedMonth)) ?></strong></dd></div>
            <div><dt>Last month</dt><dd><?= $view->e($money($f->lastMonth)) ?></dd></div>
            <div><dt>Monthly budget</dt><dd><?= $f->budget->monthlyLimit !== null ? $view->e($money($f->budget->monthlyLimit) . ' · ' . $money(max(0.0, $f->budget->monthlyLimit - $f->month)) . ' left') : 'no limit' ?></dd></div>
            <div><dt>Daily limit</dt><dd><?= $f->budget->dailyLimit !== null ? $view->e($money($f->budget->dailyLimit) . ' · ' . $money(max(0.0, $f->budget->dailyLimit - $f->today)) . ' left today') : 'no limit' ?></dd></div>
            <?php if ($f->budget->operationLimit !== null): ?>
                <div><dt>Per request</dt><dd>up to <?= $view->e($money($f->budget->operationLimit)) ?></dd></div>
            <?php endif; ?>
            <div><dt>Account balance</dt><dd><?php if ($f->balance !== null): ?><?= $view->e($money($f->balance)) ?><?php if ($balanceDays !== null): ?> <span class="muted small">≈ <?= $view->e(number_format($balanceDays)) ?> days at this pace</span><?php endif; ?><?php else: ?>unknown<?php endif; ?></dd></div>
        </dl>
        <div class="spend-bars" aria-label="Spending per day, last <?= count($f->daily) ?> days">
            <?php foreach ($f->daily as $date => $amount): ?>
                <span class="spend-bar h-<?= (int) round($amount / $peak * 10) ?>" title="<?= $view->e($date . ': ' . $money($amount)) ?>"></span>
            <?php endforeach; ?>
        </div>
        <?php if ($f->byCategory !== []): ?>
            <h3>This month by feature</h3>
            <ul class="spend-categories">
                <?php foreach ($f->byCategory as $label => $amount): ?>
                    <li><span><?= $view->e($label) ?></span><span><?= $view->e($money($amount)) ?></span></li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
        <p class="muted small">The forecast adds the average of the last <?= SeoControlCenter\Domain\Integrations\SpendForecast::PACE_DAYS ?> days (<?= $view->e($money($f->dailyPace)) ?> a day) for the <?= $view->e((string) $f->daysLeft) ?> days left this month. The balance was checked <?= $view->e($balanceCheckedAt !== null ? substr($balanceCheckedAt, 0, 10) : 'never') ?>.</p>
        <?php if ($canManage): ?>
            <p class="small"><a href="/integrations/dataforseo">Budgets and account →</a></p>
        <?php endif; ?>
    </div>
</details>
