<?php
/**
 * Visits from AI assistants, from GA4 (0.43.0).
 *
 * @var SeoControlCenter\Core\View\Template $view
 * @var SeoControlCenter\Domain\Projects\Project $project
 * @var array{linked: bool, import: array{from: ?string, to: ?string, currency: ?string, limited: bool, fetched_at: ?string, error: ?string, error_at: ?string}|null, current: ?SeoControlCenter\Domain\Shared\DateRange, previous: ?SeoControlCenter\Domain\Shared\DateRange, comparable: bool, totals: array{sessions: int, previous: int, key_events: float, revenue: float}, assistants: list<array{assistant: string, sources: list<string>, sessions: int, previous: int, key_events: float, revenue: float}>} $referrals
 */
$base = '/projects/' . $project->publicId;
$currency = $referrals['import']['currency'] ?? null;
$money = static fn(float $v): string => number_format($v, 2) . ($currency !== null && $currency !== 'Unknown' ? ' ' . $currency : '');
$change = static function (int $now, int $before) use ($view, $referrals): string {
    if (!$referrals['comparable']) {
        return '';
    }
    if ($before === 0) {
        return $now > 0 ? '<span class="delta delta-good">new</span>' : '';
    }
    $pct = ($now - $before) / $before * 100;

    return abs($pct) < 0.5 ? '<span class="delta">no change</span>' : sprintf('<span class="delta %s">%s %s %%</span>', $pct > 0 ? 'delta-good' : 'delta-bad', $pct > 0 ? '▲' : '▼', $view->e(sprintf('%+.0f', $pct)));
};
?>
<section class="card" id="ai-referrals">
    <h2>Visits from AI assistants</h2>
    <?php if (!$referrals['linked']): ?>
        <p class="muted">Link a GA4 property under <a href="<?= $view->e($base) ?>/analytics">Analytics</a> to see the visits, key events and revenue that ChatGPT, Perplexity, Gemini, Copilot and other assistants send to your site.</p>
    <?php elseif ($referrals['current'] === null): ?>
        <p class="muted">The visits appear after the next GA4 import<?= ($referrals['import']['error'] ?? null) !== null ? '' : ' (daily, or start one under Analytics)' ?>.</p>
        <?php if (($referrals['import']['error'] ?? null) !== null): ?>
            <p class="notice notice-error small">The last AI referral import failed: <?= $view->e((string) $referrals['import']['error']) ?></p>
        <?php endif; ?>
    <?php else: ?>
        <p class="muted small"><?= $view->e($referrals['current']->fromString()) ?> to <?= $view->e($referrals['current']->toString()) ?> from GA4<?= $referrals['comparable'] && $referrals['previous'] !== null ? ', compared with the 28 days before' : '' ?>, all channels.</p>
        <div class="kpis">
            <div class="kpi"><p class="kpi-label">Sessions</p><p class="kpi-value"><?= $view->e(number_format($referrals['totals']['sessions'])) ?></p><p class="kpi-note"><?= $referrals['comparable'] ? $view->e(number_format($referrals['totals']['previous'])) . ' before ' : '' ?><?= $change($referrals['totals']['sessions'], $referrals['totals']['previous']) ?></p></div>
            <div class="kpi"><p class="kpi-label">Key events</p><p class="kpi-value"><?= $view->e(number_format($referrals['totals']['key_events'], 0)) ?></p><p class="kpi-note">from these sessions</p></div>
            <div class="kpi"><p class="kpi-label">Revenue</p><p class="kpi-value"><?= $view->e($money($referrals['totals']['revenue'])) ?></p><p class="kpi-note">purchase revenue</p></div>
        </div>
        <?php if ($referrals['assistants'] === []): ?>
            <p class="muted">GA4 recorded no visits from AI assistants in these 56 days.</p>
        <?php else: ?>
            <div class="table-scroll">
                <table class="table table-dense">
                    <thead><tr><th scope="col">Assistant</th><th scope="col" class="num">Sessions</th><th scope="col" class="num">Before</th><th scope="col" class="num">Key events</th><th scope="col" class="num">Revenue</th></tr></thead>
                    <tbody>
                        <?php foreach ($referrals['assistants'] as $row): ?>
                            <tr>
                                <td><?= $view->e($row['assistant']) ?><?php if ($row['sources'] !== []): ?> <span class="muted small"><?= $view->e(implode(', ', array_slice($row['sources'], 0, 3))) ?></span><?php endif; ?></td>
                                <td class="num"><?= $view->e(number_format($row['sessions'])) ?> <?= $change($row['sessions'], $row['previous']) ?></td>
                                <td class="num"><?= $referrals['comparable'] ? $view->e(number_format($row['previous'])) : '—' ?></td>
                                <td class="num"><?= $view->e(number_format($row['key_events'], 0)) ?></td>
                                <td class="num"><?= $view->e($money($row['revenue'])) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
        <?php if (($referrals['import']['limited'] ?? false) === true): ?><p class="notice notice-warning small">GA4 cut this report off at its row limit; the numbers may be low.</p><?php endif; ?>
        <p class="small muted">GA4 counts a visit for an assistant only when the assistant passes its address or a utm_source. Visits from assistant apps often arrive without one and count as direct traffic, so these numbers are a lower bound.</p>
    <?php endif; ?>
</section>
