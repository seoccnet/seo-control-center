<?php
/** AI visibility at a glance: AI Overview citations, GA4 visits from assistants, assistant mentions, most-cited domains. Saved evidence only. */

use SeoControlCenter\Domain\Competitors\Competitor;

$ai = $evidence['ai'];
$referrals = $evidence['aiReferrals'];
$domains = $evidence['aiDomains'];
$own = Competitor::normalizeDomain($project->primaryDomain);
$snapshots = $evidence['mentions'];
$latestMentions = $snapshots[0]['mentions'][$own] ?? null;
$earlierMentions = isset($snapshots[1]) ? ($snapshots[1]['mentions'][$own] ?? []) : null;
$visits = $referrals['current'];
$visitTotal = array_sum(array_column($visits, 'sessions'));
$previousTotal = array_sum(array_column($referrals['previous'], 'sessions'));
$hasAny = $ai['current'] !== null || $visits !== [] || $snapshots !== [] || $domains !== [];
$pct = static fn(float $v): string => number_format($v * 100, 0) . ' %';
$delta = static function (int|float $now, int|float|null $before, string $unit = ' %'): string {
    if ($before === null) {
        return '';
    }
    if ($unit === ' pp') {
        $diff = $now - $before;
        $class = abs($diff) < 0.5 ? '' : ($diff > 0 ? ' delta-good' : ' delta-bad');

        return '<span class="overview-audience-delta' . $class . '">' . sprintf('%+.0f', $diff) . ' pp</span>';
    }
    if ($before == 0) {
        return $now > 0 ? '<span class="overview-audience-delta delta-good">new</span>' : '';
    }
    $percent = ($now - $before) / $before * 100;
    $class = abs($percent) < 0.5 ? '' : ($percent > 0 ? ' delta-good' : ' delta-bad');

    return '<span class="overview-audience-delta' . $class . '">' . sprintf('%+.0f', $percent) . ' %</span>';
};
$bar = static fn(float $share, string $tone = 'ai'): string => '<svg class="overview-country-bar" viewBox="0 0 100 4" preserveAspectRatio="none" aria-hidden="true" focusable="false"><rect class="track" x="0" y="0" width="100" height="4"/><rect class="tone-' . $tone . '" x="0" y="0" width="' . round(max(0.0, min(1.0, $share)) * 100, 3) . '" height="4"/></svg>';
$tabs = array_filter(['overviews' => 'AI Overviews', 'visits' => 'AI visits', 'mentions' => $snapshots !== [] ? 'Mentions' : null]);
?>
<section class="card overview-audience overview-ai" aria-labelledby="overview-ai-heading">
    <div class="card-header"><div><p class="integration-kicker">AI search</p><h2 id="overview-ai-heading">AI visibility</h2></div><a class="small" href="<?= $view->e($base) ?>/ai-visibility">AI visibility →</a></div>
    <?php if (!$hasAny): ?>
    <p class="muted small">No AI citation checks, assistant mentions or GA4 visits from AI assistants are saved yet.</p>
    <ul class="overview-ai-setup">
        <li><a href="<?= $view->e($base) ?>/ai-visibility">Check Google AI Overviews for tracked keywords →</a></li>
        <li><a href="<?= $view->e($base) ?>/analytics"><?= $evidence['analytics']['propertyId'] === null ? 'Connect GA4 to count visits from ChatGPT, Perplexity and others' : 'Import visits from AI assistants in GA4' ?> →</a></li>
    </ul>
    <?php else: ?>
    <div class="overview-ai-tabs" data-insight-tabs>
    <div class="insight-segments overview-ai-segments" data-insight-tablist hidden aria-label="AI evidence"><?php foreach ($tabs as $key => $label): ?><button type="button" data-insight-tab="<?= $view->e($key) ?>"><?= $view->e($label) ?></button><?php endforeach; ?></div>

    <div class="overview-ai-panel" data-insight-panel="overviews">
        <h3 class="overview-subheading">Google AI Overviews<?php if ($ai['date'] !== null): ?> <span class="muted small"><?= $view->e($ai['date'] . ' · ' . $ai['device']) ?></span><?php endif; ?></h3>
        <?php if ($ai['current'] === null): ?>
        <p class="muted small">No AI Overview checks saved. <a href="<?= $view->e($base) ?>/ai-visibility">Start checks →</a></p>
        <?php else: $c = $ai['current']; $p = $ai['previous']; $rate = $c['present'] > 0 ? $c['cited'] / $c['present'] : null; $prevRate = $p !== null && $p['present'] > 0 ? $p['cited'] / $p['present'] : null; ?>
        <p class="overview-ai-headline"><strong><?= $rate === null ? '—' : $pct($rate) ?></strong><span>cited in <?= $c['cited'] ?> of <?= $c['present'] ?> AI Overviews<?= $rate !== null && $prevRate !== null ? ' ' . $delta($rate * 100, $prevRate * 100, ' pp') : '' ?></span></p>
        <ol class="overview-country-list">
            <li><span class="overview-country-name">Show an AI Overview</span><span class="overview-country-value"><strong><?= number_format($c['present']) ?></strong> <span class="muted small">of <?= number_format($c['keywords']) ?></span></span><span class="overview-country-share muted small"><?= $c['keywords'] > 0 ? $pct($c['present'] / $c['keywords']) : '—' ?></span><?= $bar($c['keywords'] > 0 ? $c['present'] / $c['keywords'] : 0.0, 'ai') ?></li>
            <li><span class="overview-country-name">Cite your site</span><span class="overview-country-value"><strong><?= number_format($c['cited']) ?></strong> <span class="muted small">of <?= number_format($c['keywords']) ?></span></span><span class="overview-country-share muted small"><?= $c['keywords'] > 0 ? $pct($c['cited'] / $c['keywords']) : '—' ?></span><?= $bar($c['keywords'] > 0 ? $c['cited'] / $c['keywords'] : 0.0, 'own') ?></li>
        </ol>
        <?php endif; ?>
        <?php if ($domains !== []): $maxDomain = max(1, ...array_column($domains, 'keywords')); ?>
        <h3 class="overview-subheading overview-ai-subheading">Most cited domains <span class="muted small">keywords citing them</span></h3>
        <ol class="overview-country-list">
            <?php foreach (array_slice($domains, 0, 3) as $row): $isOwn = Competitor::normalizeDomain($row['domain']) === $own; ?>
            <li<?= $isOwn ? ' class="is-own"' : '' ?>><span class="overview-country-name" title="<?= $view->e($row['domain']) ?>"><?= $view->e($row['domain']) ?><?= $isOwn ? ' <span class="you">you</span>' : '' ?></span><span class="overview-country-value"><strong><?= number_format($row['keywords']) ?></strong></span><span class="overview-country-share"></span><?= $bar($row['keywords'] / $maxDomain, $isOwn ? 'own' : 'ai') ?></li>
            <?php endforeach; ?>
        </ol>
        <?php endif; ?>
    </div>

    <div class="overview-ai-panel" data-insight-panel="visits">
        <h3 class="overview-subheading">Visits from AI assistants <span class="muted small">GA4 sessions</span></h3>
        <?php if ($visits === []): ?>
        <p class="muted small"><?= $referrals['imported'] === null ? 'Not imported yet.' : 'No sessions from AI assistants on these dates.' ?> <a href="<?= $view->e($base) ?>/analytics"><?= $evidence['analytics']['propertyId'] === null ? 'Connect GA4' : 'Review GA4' ?> →</a></p>
        <?php else: $maxVisits = max(1, $visits[0]['sessions']); $prevBy = array_column($referrals['previous'], 'sessions', 'assistant'); ?>
        <p class="overview-ai-headline"><strong><?= number_format($visitTotal) ?></strong><span>sessions<?= $referrals['previous'] !== [] ? ' ' . $delta($visitTotal, $previousTotal) : '' ?> · <?= number_format(array_sum(array_column($visits, 'keyEvents')), 0) ?> key events</span></p>
        <ol class="overview-country-list">
            <?php foreach (array_slice($visits, 0, 4) as $row): ?>
            <li><span class="overview-country-name"><?= $view->e($row['assistant']) ?></span><span class="overview-country-value"><strong><?= number_format($row['sessions']) ?></strong> <?= $referrals['previous'] !== [] ? $delta($row['sessions'], $prevBy[$row['assistant']] ?? 0) : '' ?></span><span class="overview-country-share muted small"><?= $visitTotal > 0 ? $pct($row['sessions'] / $visitTotal) : '' ?></span><?= $bar($row['sessions'] / $maxVisits, 'ai') ?></li>
            <?php endforeach; ?>
        </ol>
        <?php endif; ?>
    </div>

    <?php if ($snapshots !== []): ?>
    <div class="overview-ai-panel" data-insight-panel="mentions">
        <h3 class="overview-subheading">Mentions in AI assistants <span class="muted small"><?= $view->e($snapshots[0]['date']) ?></span></h3>
        <?php if ($latestMentions === null || $latestMentions === []): ?>
        <p class="muted small">Your domain was not mentioned in the latest saved sample.</p>
        <?php else: arsort($latestMentions); $maxMentions = max(1, ...array_values($latestMentions)); ?>
        <p class="overview-ai-headline"><strong><?= number_format(array_sum($latestMentions)) ?></strong><span>mentions<?= $earlierMentions !== null ? ' ' . $delta(array_sum($latestMentions), array_sum($earlierMentions)) : '' ?></span></p>
        <ol class="overview-country-list">
            <?php foreach (array_slice($latestMentions, 0, 4, true) as $platform => $count): ?>
            <li><span class="overview-country-name"><?= $view->e(['chat_gpt' => 'ChatGPT', 'google' => 'Google AI', 'gemini' => 'Gemini', 'perplexity' => 'Perplexity', 'claude' => 'Claude'][(string) $platform] ?? ucfirst(str_replace('_', ' ', (string) $platform))) ?></span><span class="overview-country-value"><strong><?= number_format($count) ?></strong> <?= $earlierMentions !== null ? $delta($count, $earlierMentions[$platform] ?? 0) : '' ?></span><span class="overview-country-share"></span><?= $bar($count / $maxMentions, 'ai') ?></li>
            <?php endforeach; ?>
        </ol>
        <?php endif; ?>
    </div>
    <?php endif; ?>
    </div>

    <details class="overview-about"><summary><?= $view->icon('info') ?>About this data</summary><p>AI Overview figures come from the latest saved check of tracked keywords; the change compares with a check about a week earlier, and the checked sample can differ. Visits are GA4 sessions whose source is a known AI assistant, for the dates above. Mentions are provider-observed samples, not the share of all AI answers. Nothing here is fetched live.</p></details>
    <?php endif; ?>
</section>
