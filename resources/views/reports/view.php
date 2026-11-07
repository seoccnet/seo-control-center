<?php
/**
 * The printable project report (also linked from the report e-mail).
 *
 * @var SeoControlCenter\Core\View\Template $view
 * @var string|null $assetVersion
 * @var array{
 *     project: SeoControlCenter\Domain\Projects\Project, frequency: string, period: SeoControlCenter\Domain\Shared\DateRange, previousPeriod: SeoControlCenter\Domain\Shared\DateRange, label: string,
 *     search: array{google: ?SeoControlCenter\Domain\SearchConsole\Metrics, googlePrevious: ?SeoControlCenter\Domain\SearchConsole\Metrics, bing: ?SeoControlCenter\Domain\SearchConsole\Metrics, bingPrevious: ?SeoControlCenter\Domain\SearchConsole\Metrics, combined: SeoControlCenter\Domain\SearchConsole\Metrics, combinedPrevious: SeoControlCenter\Domain\SearchConsole\Metrics}|null,
 *     queries: list<SeoControlCenter\Domain\SearchConsole\DimensionRow>, pages: list<SeoControlCenter\Domain\SearchConsole\DimensionRow>, clicks: list<array{0: string, 1: float}>,
 *     rankings: array{day: SeoControlCenter\Domain\Rankings\RankingDay, previous: ?SeoControlCenter\Domain\Rankings\RankingDay, winners: list<SeoControlCenter\Domain\Rankings\RankingMove>, losers: list<SeoControlCenter\Domain\Rankings\RankingMove>, device: string}|null,
 *     openOpportunities: int, opportunities: list<SeoControlCenter\Domain\Opportunities\StoredOpportunity>,
 *     siteAudit: array{date: string, score: ?int, pages: int, errors: int, warnings: int, previousScore: ?int, previousDate: ?string, issues: list<array{label: string, severity: string, pages: int}>}|null
 * } $report
 * @var string $earlierUrl
 * @var string|null $pdfUrl
 * @var string|null $laterUrl
 * @var (Closure(): SeoControlCenter\Core\Branding\Branding)|null $brand
 */
$branding = $reportBranding ?? (isset($brand) ? $brand() : SeoControlCenter\Core\Branding\Branding::none());
$sections ??= new SeoControlCenter\Modules\Reports\ReportSections();
$project = $report['project'];
$base = '/projects/' . $project->publicId;
$view->extend('layouts/app', ['title' => 'Report ' . $report['label'] . ' · ' . $project->name, 'crumbs' => [['/projects', 'Projects'], [$base, $project->name], [$base . '/reports', 'Reports'], [null, $report['label']]]]);
$num = static fn(float|int $v): string => number_format((float) $v);
$pct = static fn(float $v): string => number_format($v * 100, 1) . ' %';
$search = $report['search'];
$rankings = $report['rankings'];
$both = $search !== null && $search['google'] !== null && $search['bing'] !== null;
$notes = [];
if ($both) {
    $notes = [
        'Clicks' => 'Google ' . $num($search['google']->clicks) . ' · Bing ' . $num($search['bing']->clicks),
        'Impressions' => 'Google ' . $num($search['google']->impressions) . ' · Bing ' . $num($search['bing']->impressions),
        'Average position' => 'Google only',
    ];
}
?>
<div class="report manage-workspace manage-reports">
    <link rel="stylesheet" href="<?= $view->e($base . '/reports/branding.css') ?>">
    <div class="section-header no-print">
        <nav class="segmented" aria-label="Report period">
            <a href="?frequency=weekly"<?= $report['frequency'] === 'weekly' ? ' aria-current="true"' : '' ?>>Week</a>
            <a href="?frequency=monthly"<?= $report['frequency'] === 'monthly' ? ' aria-current="true"' : '' ?>>Month</a>
        </nav>
        <div class="actions">
            <a class="button button-ghost button-small" href="<?= $view->e($earlierUrl) ?>">← Earlier</a>
            <?php if ($laterUrl !== null): ?><a class="button button-ghost button-small" href="<?= $view->e($laterUrl) ?>">Later →</a><?php endif; ?>
            <?php if (isset($pdfUrl)): ?><a class="button button-small" href="<?= $view->e($pdfUrl) ?>"><?= $view->icon('download') ?>Download PDF</a><?php endif; ?>
            <button type="button" class="button button-ghost button-small" data-print>Print</button>
        </div>
    </div>

    <header class="report-head">
        <p class="report-brand"><?php if (($reportLogoUrl ?? null) !== null): ?><img class="logo-mark brand-logo" src="<?= $view->e($reportLogoUrl) ?>" alt=""><?php else: ?><?= $view->partial('partials/logo-mark', ['brand' => static fn() => $branding]) ?><?php endif; ?><span><?= $view->e($branding->name()) ?></span><span class="muted small">SEO report · <?= $view->e($project->primaryDomain) ?></span></p>
        <h1><?= $view->e($project->name) ?>: <?= $view->e($report['label']) ?></h1>
        <p class="muted small"><?= $view->e($report['period']->fromString()) ?> – <?= $view->e($report['period']->toString()) ?>, compared with <?= $view->e($report['previousPeriod']->fromString()) ?> – <?= $view->e($report['previousPeriod']->toString()) ?><?= $both ? ' · Google + Bing' : '' ?></p>
    </header>

    <?php if ($sections->has('search')): ?>
    <?php if ($search === null): ?>
        <section class="card empty"><p>No Search Console or Bing data is linked to this project yet.</p></section>
    <?php else: ?>
        <?= $view->partial('partials/search-kpis', ['totals' => $search['combined'], 'previous' => $search['combinedPrevious'], 'hasPrevious' => $search['combinedPrevious']->impressions > 0, 'range' => $report['period'], 'showPosition' => $search['google'] !== null, 'notes' => $notes]) ?>
        <?= $view->partial('partials/line-chart', ['title' => $both ? 'Clicks per day · Google + Bing' : 'Clicks per day', 'series' => $report['clicks'], 'range' => $report['period'], 'wide' => true]) ?>

        <div class="table-grid">
            <?php foreach ([['Top pages', 'Page', $report['pages'], true], ['Top queries', 'Query', $report['queries'], false]] as [$title, $column, $rows, $isUrl]): ?>
                <section class="card">
                    <h2><?= $view->e($title) ?></h2>
                    <?php if ($rows === []): ?>
                        <p class="muted">No rows for this period.</p>
                    <?php else: ?>
                        <table class="table table-dense numeric">
                            <thead><tr><th scope="col"><?= $view->e($column) ?></th><th scope="col">Clicks</th><th scope="col">Impr.</th><th scope="col">CTR</th><th scope="col">Pos.</th></tr></thead>
                            <tbody>
                                <?php foreach ($rows as $row): ?>
                                    <tr>
                                        <td class="key-cell"><?= $view->e($isUrl ? (preg_replace('#^https?://[^/]+#', '', $row->key) ?: '/') : $row->key) ?></td>
                                        <td><?= $view->e($num($row->metrics->clicks)) ?></td>
                                        <td><?= $view->e($num($row->metrics->impressions)) ?></td>
                                        <td><?= $view->e($pct($row->metrics->ctr())) ?></td>
                                        <td><?= $row->metrics->impressions > 0 ? $view->e(number_format($row->metrics->position, 1)) : '–' ?></td>
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
    <div class="table-grid">
        <?php if ($sections->has('rankings')): ?>
        <section class="card">
            <h2>Rankings</h2>
            <?php if ($rankings === null): ?>
                <p class="muted">No rank check in this period.</p>
            <?php else: ?>
                <?php $day = $rankings['day']; $before = $rankings['previous']; ?>
                <p class="muted small"><?= $view->e($day->keywords) ?> keywords · <?= $view->e($rankings['device']) ?> · check of <?= $view->e($day->date) ?><?= $before !== null ? ' vs ' . $view->e($before->date) : '' ?></p>
                <dl class="details">
                    <dt>Visibility</dt><dd><?= $view->e(number_format($day->visibility, 1)) ?> %<?= $before !== null ? ' (' . $view->e(sprintf('%+.1f pts', $day->visibility - $before->visibility)) . ')' : '' ?></dd>
                    <dt>Top 3</dt><dd><?= $view->e($day->top3) ?><?= $before !== null ? ' (' . $view->e(sprintf('%+d', $day->top3 - $before->top3)) . ')' : '' ?></dd>
                    <dt>Top 10</dt><dd><?= $view->e($day->top10) ?><?= $before !== null ? ' (' . $view->e(sprintf('%+d', $day->top10 - $before->top10)) . ')' : '' ?></dd>
                    <dt>Average position</dt><dd><?= $day->averagePosition !== null ? $view->e(number_format($day->averagePosition, 1)) : '–' ?></dd>
                </dl>
                <?php foreach ([['Winners', $rankings['winners']], ['Losers', $rankings['losers']]] as [$title, $moves]): ?>
                    <?php if ($moves !== []): ?>
                        <h3><?= $view->e($title) ?></h3>
                        <ul class="plain-list small">
                            <?php foreach ($moves as $move): ?>
                                <li><?= $view->e($move->keyword) ?>: <?= $view->e($move->from ?? '>100') ?> → <?= $view->e($move->to ?? '>100') ?></li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>
                <?php endforeach; ?>
            <?php endif; ?>
        </section>

        <?php endif; ?>
        <?php if ($sections->has('analytics') && $report['analytics'] !== null): ?><section class="card"><h2>Organic-search conversions and revenue</h2>
            <?= $view->partial('analytics/metadata', ['metadata' => $report['analytics']['metadata']]) ?>
            <?php if ($report['analytics']['partial']): ?><p role="status">GA4 imports do not cover every date in this report period. These figures cover only available imported dates.</p><?php endif; ?>
            <div class="table-scroll"><table class="table table-dense"><thead><tr><th scope="col">Landing URL</th><th scope="col">Sessions</th><th scope="col">Conversions</th><th scope="col">Purchase revenue</th></tr></thead><tbody>
            <?php foreach ($report['analytics']['rows'] as $row): ?><tr><td><?= $view->e($row['url']) ?></td><td><?= $view->e(number_format($row['sessions'])) ?></td><td><?= $view->e(number_format($row['conversions'], 2)) ?></td><td><?= $view->e(number_format($row['revenue'], 2) . ' ' . $report['analytics']['metadata']['currency']) ?></td></tr><?php endforeach; ?></tbody></table></div>
        </section><?php endif; ?>
        <?php if ($sections->has('audit') && $report['siteAudit'] !== null): ?>
            <?php $audit = $report['siteAudit']; ?>
            <section class="card">
                <h2>Site audit</h2>
                <p class="muted small"><?= $view->e(number_format($audit['pages'])) ?> pages · audit of <?= $view->e($audit['date']) ?><?= $audit['previousDate'] !== null ? ' vs ' . $view->e($audit['previousDate']) : '' ?></p>
                <dl class="details">
                    <dt>Health score</dt><dd><?= $audit['score'] !== null ? $view->e($audit['score']) . ' / 100' : '–' ?><?= $audit['score'] !== null && $audit['previousScore'] !== null ? ' (' . $view->e(sprintf('%+d', $audit['score'] - $audit['previousScore'])) . ')' : '' ?></dd>
                    <dt>Errors</dt><dd><?= $view->e($audit['errors']) ?></dd>
                    <dt>Warnings</dt><dd><?= $view->e($audit['warnings']) ?></dd>
                </dl>
                <?php if ($audit['issues'] !== []): ?>
                    <ul class="plain-list small">
                        <?php foreach ($audit['issues'] as $issue): ?>
                            <li><?= $view->e($issue['label']) ?>: <?= $view->e($issue['pages']) ?> page<?= $issue['pages'] === 1 ? '' : 's' ?></li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </section>
        <?php endif; ?>

        <?php if ($sections->has('audit') && ($report['renderedAudit'] ?? null) !== null): ?>
        <section class="card"><h2>Browser audit</h2><p><?= $view->e($report['renderedAudit']['pages']) ?> saved pages · <?= $view->e($report['renderedAudit']['date']) ?> UTC. Browser findings are separate from Google field measurements.</p><ul><?php foreach ($report['renderedAudit']['issues'] as $issue): ?><li><?= $view->e($issue['label']) ?>: <?= $view->e($issue['pages']) ?> pages. <?= $view->e($issue['advice']) ?></li><?php endforeach; ?></ul></section>
        <?php endif; ?>

        <?php if ($sections->has('opportunities')): ?><section class="card">
            <h2>Opportunities</h2>
            <p class="muted small"><?= $view->e($report['openOpportunities']) ?> open, highest priority first</p>
            <?php if ($report['opportunities'] === []): ?>
                <p class="muted">No open opportunities.</p>
            <?php else: ?>
                <ol class="small">
                    <?php foreach ($report['opportunities'] as $opportunity): ?>
                        <li><?= $view->e($opportunity->title) ?> <span class="muted">(<?= $view->e($opportunity->type->label()) ?>)</span><br><span class="muted small"><?= $view->e(SeoControlCenter\Domain\Opportunities\ScoreBreakdown::summary($opportunity)) ?></span></li>
                    <?php endforeach; ?>
                </ol>
            <?php endif; ?>
        </section>
        <?php endif; ?>
    </div>
    <p class="muted small"><?= $view->e($branding->reportFooter ?? 'Generated by ' . $branding->name() . ' from the data imported on this server.') ?> Search Console and Bing report the last days of a period with a short delay.</p>
</div>
<script type="module" src="/assets/js/line-chart.js?v=<?= $view->e($assetVersion ?? '0') ?>"></script>
<script type="module" src="/assets/js/print.js?v=<?= $view->e($assetVersion ?? '0') ?>"></script>

