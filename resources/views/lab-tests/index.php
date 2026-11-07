<?php

declare(strict_types=1);

/**
 * Improve → Speed tests (ADR-040, F2): saved lab test profiles with repeat
 * runs, beside Chrome UX Report field data for the same page and device.
 *
 * @var SeoControlCenter\Core\View\Template $view
 * @var SeoControlCenter\Domain\Projects\Project $project
 * @var list<array{profile: SeoControlCenter\Domain\LabTests\LabProfile, runs: list<SeoControlCenter\Domain\LabTests\LabRun>, field: ?SeoControlCenter\Domain\WebVitals\WebVitalRecord}> $profiles
 * @var bool $can_edit
 * @var bool $has_key
 * @var int $limit
 */

use SeoControlCenter\Domain\LabTests\LabProfile;
use SeoControlCenter\Domain\LabTests\LabRun;
use SeoControlCenter\Modules\LabTests\LabTestClients;

$base = '/projects/' . $project->publicId . '/lab-tests';
$view->extend('layouts/app', ['title' => 'Speed tests · ' . $project->name]);
echo $view->partial('partials/project-header', ['project' => $project, 'active' => 'lab-tests']);
$path = static function (string $url): string {
    $parts = parse_url($url);

    return is_array($parts) ? ((string) ($parts['path'] ?? '/')) . (isset($parts['query']) ? '?' . $parts['query'] : '') : $url;
};
$ms = static fn(?float $v): string => $v === null ? '—' : number_format($v) . ' ms';
$sources = [LabProfile::PAGESPEED => 'PageSpeed Insights (free)', LabProfile::DATAFORSEO => 'DataForSEO Lighthouse (paid)'];
$kb = static fn(?int $bytes): string => $bytes === null ? '—' : number_format($bytes / 1024, $bytes < 10240 ? 1 : 0) . ' KB';
$savings = static fn(array $s): string => implode(', ', array_map(static fn(string $m, int $v): string => $m === 'CLS' ? 'CLS −' . number_format($v / 1000, 3) : $m . ' −' . number_format($v) . ' ms', array_keys($s), $s));
?>
<div class="improve-workspace">
<header class="improve-intro"><div><p class="portfolio-eyebrow">Improve · Speed tests</p><h2>Speed tests</h2><p class="muted">Save a test setup once (page, device, source) and repeat it, so results stay comparable. Lab results come from Lighthouse under fixed, simulated conditions. Field data from real Chrome users (CrUX) is shown beside them and never mixed in. Lab results can differ from what visitors experience.</p></div></header>

<?php if ($can_edit): ?>
<section class="card" aria-labelledby="lab-add-heading">
    <h2 id="lab-add-heading">New profile</h2>
    <?php if (!$has_key): ?><p class="muted small">PageSpeed Insights needs the workspace's Google API key (the same key as Chrome UX Report): add it under <a href="/integrations/web-vitals">Integrations → Core Web Vitals</a> then enable the PageSpeed Insights API in its Google Cloud project and allow it in the key's API restrictions.</p><?php endif; ?>
    <form method="post" action="<?= $view->e($base) ?>" class="stack"><?= $view->csrfField() ?>
        <div class="field"><label for="lab-url">Page address</label><input id="lab-url" name="url" type="url" required maxlength="2048" placeholder="<?= $view->e('https://' . $project->primaryDomain . '/') ?>"></div>
        <div class="inline-form"><label for="lab-device" class="small">Device</label><select id="lab-device" name="device"><option value="mobile">Mobile</option><option value="desktop">Desktop</option></select>
        <label for="lab-source" class="small">Source</label><select id="lab-source" name="source"><option value="pagespeed">PageSpeed Insights (free)</option><option value="dataforseo">DataForSEO Lighthouse (paid, about $<?= $view->e(number_format(LabTestClients::PAID_COST, 3)) ?> per run)</option></select></div>
        <label class="small lab-weekly"><input type="checkbox" name="weekly" value="1"> Repeat weekly</label>
        <button class="button button-primary">Save profile</button>
        <p class="muted small">Up to <?= $limit ?> profiles per project. A PageSpeed profile runs once right away. Weekly paid profiles cost one run each week.</p>
    </form>
</section>
<?php endif; ?>

<?php if ($profiles === []): ?>
<section class="card"><p class="muted">No speed test profiles yet.</p></section>
<?php endif; ?>
<?php foreach ($profiles as ['profile' => $profile, 'runs' => $runs, 'field' => $field]): ?>
<?php
$done = array_values(array_filter($runs, static fn(LabRun $r): bool => $r->result !== null));
// A run differs in setup from the next older finished run when Lighthouse or its throttling changed (0.38.0).
$differs = [];
foreach ($done as $i => $r) {
    if (isset($done[$i + 1]) && ($why = $r->result->incompatibleWith($done[$i + 1]->result)) !== null) {
        $differs[$r->id] = $why;
    }
}
$latest = $done[0] ?? null;
$diag = $latest?->result->diagnostics;
?>
<section class="card lab-profile" aria-label="<?= $view->e($path($profile->url) . ' · ' . $profile->device) ?>">
    <div class="sync-row"><div><h2><a href="<?= $view->e($profile->url) ?>" rel="noopener" target="_blank" title="<?= $view->e($profile->url) ?>"><?= $view->e($path($profile->url)) ?></a></h2>
        <p class="muted small"><?= $view->e(ucfirst($profile->device)) ?> · <?= $view->e($sources[$profile->source] ?? $profile->source) ?><?= $profile->weekly ? ' · weekly, next ' . $view->e(substr((string) $profile->nextRunAt, 0, 16)) . ' UTC' : '' ?></p></div>
        <?php if ($done !== []): ?><span class="indexing-badge">Score <?= (int) round($done[0]->result->score * 100) ?><?php if (isset($done[1])): ?><?php if (isset($differs[$done[0]->id])): ?> (not comparable with the previous run: <?= $view->e($differs[$done[0]->id]) ?>)<?php else: ?> (<?= sprintf('%+d', (int) round(($done[0]->result->score - $done[1]->result->score) * 100)) ?> since the previous run)<?php endif; ?><?php endif; ?></span><?php endif; ?></div>
    <div class="lab-columns">
        <div>
            <h3>Lab runs</h3>
            <?php if ($runs === []): ?><p class="muted small">No runs yet.</p><?php else: ?>
            <div class="table-scroll"><table class="table table-dense" data-no-table-controls><thead><tr><th scope="col">Run</th><th scope="col" class="num">Score</th><th scope="col" class="num">LCP</th><th scope="col" class="num">CLS</th><th scope="col" class="num">TBT</th><th scope="col" class="num">FCP</th><th scope="col" class="num">Speed Index</th></tr></thead><tbody>
            <?php foreach ($runs as $run): ?>
                <tr><td class="lab-run-date"><?= $view->e(substr($run->finishedAt ?? $run->createdAt, 0, 16)) ?><?= $run->trigger === 'weekly' ? ' <span class="muted small">weekly</span>' : '' ?><?php if (isset($differs[$run->id])): ?> <span class="badge badge-warning" title="<?= $view->e('Not comparable with the run before: ' . $differs[$run->id]) ?>">Different setup</span><?php endif; ?></td>
                <?php if ($run->result !== null): ?>
                    <td class="num"><?= (int) round($run->result->score * 100) ?></td><td class="num"><?= $view->e($ms($run->result->lcpMs)) ?></td><td class="num"><?= $view->e(number_format($run->result->cls, 3)) ?></td><td class="num"><?= $view->e($ms($run->result->tbtMs)) ?></td><td class="num"><?= $view->e($ms($run->result->fcpMs)) ?></td><td class="num"><?= $view->e($ms($run->result->siMs)) ?></td>
                <?php else: ?>
                    <td colspan="6"><?= $run->active() ? '<span class="muted">' . ($run->status === LabRun::QUEUED ? 'Waiting for cron' : 'Testing') . '…</span>' : $view->e('Failed: ' . ($run->error ?? 'unknown error')) ?></td>
                <?php endif; ?></tr>
            <?php endforeach; ?>
            </tbody></table></div>
            <p class="muted small">Lighthouse<?= $done !== [] && $done[0]->result->version !== null ? ' ' . $view->e($done[0]->result->version) : '' ?> under the source's fixed throttling; compare runs of this profile with each other, not with another source or device. Runs marked <em>Different setup</em> used another Lighthouse version or throttling and are not compared. TBT stands in for INP in the lab.</p>
            <?php endif; ?>
        </div>
        <div>
            <?= $view->partial('web-vitals/record', ['record' => $field?->toArray(), 'formFactor' => $profile->device === LabProfile::DESKTOP ? 'DESKTOP' : 'PHONE']) ?>
            <p class="muted small">Field data: real Chrome users, 75th percentile over 28 days, fetched separately (Pages → page details). Never averaged with lab runs.</p>
        </div>
    </div>
    <?php if ($latest !== null): ?>
    <details class="lab-diagnostics"><summary>Why the latest run scored <?= (int) round($latest->result->score * 100) ?></summary>
    <?php if ($diag === null): ?><p class="muted small">This run has no diagnostics: runs from before 0.38.0 kept only the metrics. The next run will have them.</p><?php else: ?>
        <?php if ($diag->lcp !== null): ?>
        <h3>Largest Contentful Paint element</h3>
        <p><?= $view->e($diag->lcp['element'] ?? 'Unnamed element') ?><?php if ($diag->lcp['resource'] !== null): ?> · image <a href="<?= $view->e($diag->lcp['resource']) ?>" rel="noopener noreferrer" target="_blank"><?= $view->e($path($diag->lcp['resource'])) ?></a><?php else: ?> <span class="muted small">(no image address: text or a background)</span><?php endif; ?></p>
        <?php if ($diag->lcp['snippet'] !== null): ?><pre class="lab-snippet small"><code><?= $view->e($diag->lcp['snippet']) ?></code></pre><?php endif; ?>
        <?php if ($diag->lcp['phases'] !== []): $phaseTotal = max(1, array_sum(array_column($diag->lcp['phases'], 'ms'))); ?><div class="table-scroll"><table class="table table-dense" data-no-table-controls><caption class="small muted">How the observed page load split the LCP time</caption><thead><tr><th scope="col">Phase</th><th scope="col" class="num">Share</th><th scope="col" class="num">Observed</th></tr></thead><tbody><?php foreach ($diag->lcp['phases'] as $phase): ?><tr><td><?= $view->e($phase['phase']) ?></td><td class="num"><?= (int) round($phase['ms'] / $phaseTotal * 100) ?> %</td><td class="num"><?= $view->e(number_format($phase['ms'])) ?> ms</td></tr><?php endforeach; ?></tbody></table></div>
        <p class="muted small">Lighthouse measures these phases in the page load it observed, before applying its simulated throttling. They total <?= $view->e(number_format($phaseTotal)) ?> ms, so they don't add up to the run's simulated LCP of <?= $view->e(number_format($latest->result->lcpMs)) ?> ms. Read them as shares: the largest one is where to start.</p><?php endif; ?>
        <?php if ($diag->lcp['checks'] !== []): ?><ul class="small lab-checks"><?php foreach ($diag->lcp['checks'] as $check): ?><li><?= $check['passed'] ? '<span class="badge badge-ok">Passed</span>' : '<span class="badge badge-warning">To fix</span>' ?> <?= $view->e($check['label']) ?></li><?php endforeach; ?></ul><?php endif; ?>
        <?php endif; ?>
        <h3>Render-blocking resources</h3>
        <?php if ($diag->blocking === []): ?><p class="muted small">None reported.</p><?php else: ?>
        <div class="table-scroll"><table class="table table-dense" data-no-table-controls><thead><tr><th scope="col">Resource</th><th scope="col" class="num">Delay</th><th scope="col" class="num">Size</th></tr></thead><tbody><?php foreach ($diag->blocking as $b): ?><tr><td class="key-cell" title="<?= $view->e($b['url']) ?>"><?= $view->e($path($b['url'])) ?></td><td class="num"><?= $b['wasted_ms'] !== null ? $view->e(number_format($b['wasted_ms'])) . ' ms' : '—' ?></td><td class="num"><?= $view->e($kb($b['bytes'])) ?></td></tr><?php endforeach; ?></tbody></table></div>
        <?php endif; ?>
        <h3>Top diagnostics</h3>
        <?php if ($diag->diagnostics === []): ?><p class="muted small">No diagnostic below a good score.</p><?php else: ?>
        <ul class="small"><?php foreach ($diag->diagnostics as $d): ?><li><strong><?= $view->e($d['title']) ?></strong><?= $d['display'] !== null ? ' · ' . $view->e($d['display']) : '' ?><?= $d['savings'] !== [] ? ' <span class="muted">(estimated ' . $view->e($savings($d['savings'])) . ')</span>' : '' ?></li><?php endforeach; ?></ul>
        <p class="muted small">Lighthouse's own estimates for this run; they are not additive and not a promise.</p>
        <?php endif; ?>
        <?php if ($diag->also !== []): ?><p class="small"><strong>Also flagged</strong> (no estimated saving): <?= $view->e(implode(', ', $diag->also)) ?>.</p><?php endif; ?>
        <?php if ($diag->resources !== []): ?>
        <h3>Requests by type</h3>
        <div class="table-scroll"><table class="table table-dense" data-no-table-controls><thead><tr><th scope="col">Type</th><th scope="col" class="num">Requests</th><th scope="col" class="num">Transferred</th></tr></thead><tbody><?php foreach ($diag->resources as $r): ?><tr><td><?= $view->e($r['type']) ?></td><td class="num"><?= $view->e(number_format($r['requests'])) ?></td><td class="num"><?= $view->e($kb($r['bytes'])) ?></td></tr><?php endforeach; ?></tbody></table></div>
        <?php endif; ?>
        <?php if ($diag->largest !== []): ?>
        <h3>Largest requests</h3>
        <ul class="small"><?php foreach ($diag->largest as $r): ?><li title="<?= $view->e($r['url']) ?>"><?= $view->e($path($r['url'])) ?> <span class="muted">· <?= $view->e($r['type']) ?> · <?= $view->e($kb($r['bytes'])) ?><?= $r['ms'] !== null ? ' · ' . $view->e(number_format($r['ms'])) . ' ms' : '' ?></span></li><?php endforeach; ?></ul>
        <?php endif; ?>
        <h3>Test configuration</h3>
        <?php $c = $diag->config; ?>
        <dl class="lab-config small">
            <dt>Device</dt><dd><?= $view->e(ucfirst($c['form_factor']) . ($c['screen'] !== null ? ', screen ' . $c['screen'] : '')) ?></dd>
            <dt>Throttling</dt><dd><?php if ($c['throttling_method'] === null): ?><?= $view->e($profile->source === LabProfile::PAGESPEED ? 'PageSpeed Insights uses its fixed ' . ($c['form_factor'] === 'desktop' ? 'desktop' : 'mobile (a mid-range phone on a slow 4G connection)') . ' settings and does not report them. Its runs are compared by Lighthouse version only.' : 'Not reported. Runs are compared by Lighthouse version only.') ?><?php else: ?><?= $view->e($c['throttling_method'] . ($c['rtt_ms'] !== null ? ', ' . number_format($c['rtt_ms']) . ' ms round trip' : '') . ($c['throughput_kbps'] !== null ? ', ' . number_format($c['throughput_kbps']) . ' Kbps' : '') . ($c['cpu_slowdown'] !== null ? ', CPU ' . rtrim(rtrim(number_format($c['cpu_slowdown'], 1), '0'), '.') . '× slower' : '')) ?><?php endif; ?></dd>
            <dt>Lighthouse</dt><dd><?= $view->e(($latest->result->version ?? 'version not reported') . ($c['channel'] !== null ? ' (' . $c['channel'] . ')' : '') . ($c['benchmark_index'] !== null ? ', test machine benchmark ' . number_format($c['benchmark_index']) : '')) ?></dd>
            <dt>Tested address</dt><dd><?= $view->e($c['final_url'] ?? $profile->url) ?></dd>
            <?php if ($c['user_agent'] !== null): ?><dt>User agent</dt><dd><?= $view->e($c['user_agent']) ?></dd><?php endif; ?>
            <dt>Raw report</dt><dd>SHA-256 <span class="mono"><?= $view->e(substr($diag->report['sha256'], 0, 16)) ?>…</span>, <?= $view->e($kb($diag->report['bytes'])) ?><?= $c['fetch_time'] !== null ? ', fetched ' . $view->e($c['fetch_time']) . ' UTC' : '' ?><?= $latest->taskId !== null ? ', DataForSEO task <span class="mono">' . $view->e($latest->taskId) . '</span>' : '' ?>. The full report is not stored; the hash identifies it.</dd>
        </dl>
    <?php endif; ?>
    </details>
    <?php endif; ?>
    <?php if ($can_edit): ?>
    <div class="inline-form">
        <form method="post" action="<?= $view->e($base . '/' . $profile->publicId . '/run') ?>" class="inline-form"><?= $view->csrfField() ?><?php if ($profile->paid()): ?><label class="small"><input type="checkbox" name="confirm_paid" value="1" required> Pay about $<?= $view->e(number_format(LabTestClients::PAID_COST, 3)) ?> for this run</label><?php endif; ?><button class="button button-small">Run now</button></form>
        <form method="post" action="<?= $view->e($base . '/' . $profile->publicId . '/weekly') ?>"><?= $view->csrfField() ?><input type="hidden" name="weekly" value="<?= $profile->weekly ? '0' : '1' ?>"><button class="button button-small button-ghost"><?= $profile->weekly ? 'Stop weekly runs' : 'Repeat weekly' ?></button></form>
        <form method="post" action="<?= $view->e($base . '/' . $profile->publicId . '/delete') ?>" data-confirm="Delete this profile and all its runs?"><?= $view->csrfField() ?><button class="button button-small button-ghost">Delete</button></form>
    </div>
    <?php endif; ?>
</section>
<?php endforeach; ?>
</div>
