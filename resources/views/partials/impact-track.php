<?php
/**
 * Countdown of a recorded or published change (Modules\Annotations\ImpactWindow):
 * phase, day counter, the expected signal window on a track and the date of
 * the full 28-day comparison. The graphic is decorative; the text carries
 * the same information.
 *
 * @var SeoControlCenter\Core\View\Template $view
 * @var SeoControlCenter\Modules\Annotations\ImpactWindow $window
 * @var string|null $startLabel e.g. "Changed" or "Published"
 */
use SeoControlCenter\Modules\Annotations\ImpactWindow;

$track = $window->track();
$plural = static fn(int $n, string $word): string => $n . ' ' . $word . ($n === 1 ? '' : 's');
$countdown = match ($window->phase) {
    ImpactWindow::WAITING => 'Signal expected in ' . $plural($window->daysToSignal(), 'day'),
    ImpactWindow::SIGNAL => $window->comparisonReady() ? 'Signal window closes in ' . $plural($window->daysLeft(), 'day') : 'Full comparison in ' . $plural(max(0, ImpactWindow::COMPARISON_DAYS + 1 - $window->elapsed), 'day'),
    ImpactWindow::READY => 'Full comparison available since ' . $window->comparisonDate,
    default => 'Recorded ' . $plural($window->elapsed, 'day') . ' ago',
};
?>
<div class="impact-track impact-phase-<?= $view->e($window->phase) ?>">
    <div class="impact-track-head">
        <span class="impact-phase-pill"><?= $view->e($window->label()) ?></span>
        <?php if ($window->phase !== ImpactWindow::SETTLED): ?><span class="impact-day"><strong>Day <?= $window->elapsed ?></strong><?= $window->elapsed < $window->horizon ? ' of ' . $window->horizon : '' ?></span><?php endif; ?>
        <span class="impact-countdown"><?= $view->e($countdown) ?></span>
    </div>
    <?php if ($window->phase !== ImpactWindow::SETTLED): ?>
    <svg class="impact-track-bar" viewBox="0 0 100 12" preserveAspectRatio="none" aria-hidden="true" focusable="false">
        <rect class="impact-track-base" x="0" y="4" width="100" height="4"/>
        <rect class="impact-track-window" x="<?= $track['signal_from'] ?>" y="2" width="<?= max(0.5, $track['signal_to'] - $track['signal_from']) ?>" height="8"/>
        <rect class="impact-track-progress" x="0" y="4" width="<?= $track['today'] ?>" height="4"/>
        <rect class="impact-track-today" x="<?= max(0, min(99.2, $track['today'] - 0.4)) ?>" y="0" width="0.8" height="12"/>
    </svg>
    <div class="impact-track-scale">
        <span><?= $view->e(($startLabel ?? 'Changed') . ' ' . $window->date) ?></span>
        <span>Signal usually day <?= $window->signalFrom ?>–<?= $window->signalTo ?></span>
        <span>28-day comparison <?= $view->e($window->comparisonDate) ?></span>
    </div>
    <p class="impact-watch"><strong>Watch:</strong> <?= $view->e($window->watch) ?>. <?= $view->e($window->reason) ?></p>
    <?php endif; ?>
</div>
