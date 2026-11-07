<?php
/**
 * HTML version of the report e-mail (ADR-027 amendment): tables and inline
 * styles only, no images, scripts or remote resources. The plain-text part
 * (ReportText) carries the same figures.
 *
 * @var SeoControlCenter\Core\View\Template $view
 * @var array{
 *     project: SeoControlCenter\Domain\Projects\Project, frequency: string, period: SeoControlCenter\Domain\Shared\DateRange, previousPeriod: SeoControlCenter\Domain\Shared\DateRange, label: string,
 *     search: array{google: ?SeoControlCenter\Domain\SearchConsole\Metrics, googlePrevious: ?SeoControlCenter\Domain\SearchConsole\Metrics, bing: ?SeoControlCenter\Domain\SearchConsole\Metrics, bingPrevious: ?SeoControlCenter\Domain\SearchConsole\Metrics, combined: SeoControlCenter\Domain\SearchConsole\Metrics, combinedPrevious: SeoControlCenter\Domain\SearchConsole\Metrics}|null,
 *     queries: list<SeoControlCenter\Domain\SearchConsole\DimensionRow>, pages: list<SeoControlCenter\Domain\SearchConsole\DimensionRow>, clicks: list<array{0: string, 1: float}>,
 *     rankings: array{day: SeoControlCenter\Domain\Rankings\RankingDay, previous: ?SeoControlCenter\Domain\Rankings\RankingDay, winners: list<SeoControlCenter\Domain\Rankings\RankingMove>, losers: list<SeoControlCenter\Domain\Rankings\RankingMove>, device: string}|null,
 *     openOpportunities: int, opportunities: list<SeoControlCenter\Domain\Opportunities\StoredOpportunity>,
 *     siteAudit: array{date: string, score: ?int, pages: int, errors: int, warnings: int, previousScore: ?int, previousDate: ?string, issues: list<array{label: string, severity: string, pages: int}>}|null
 * } $report
 * @var string $url full report
 * @var (Closure(): SeoControlCenter\Core\Branding\Branding)|null $brand
 */
$branding = $reportBranding ?? (isset($brand) ? $brand() : SeoControlCenter\Core\Branding\Branding::none());
$sections ??= new SeoControlCenter\Modules\Reports\ReportSections();
$accent = $branding->accentColor ?? '#2f5fd0';
$project = $report['project'];
$search = $report['search'];
$rankings = $report['rankings'];
$num = static fn(float|int $v): string => number_format((float) $v);
$pct = static fn(float $v): string => number_format($v * 100, 1) . ' %';
$font = 'font-family:-apple-system,Segoe UI,Roboto,Helvetica,Arial,sans-serif;';
$muted = 'color:#5f6b7a;';
/** Change against the previous period, coloured: green = better. */
$change = static function (float $now, float $before, bool $points = false, bool $lowerIsBetter = false) use ($view): string {
    if (!$points && !$lowerIsBetter && $before <= 0) {
        return '';
    }
    $diff = $points ? ($now - $before) * 100 : ($lowerIsBetter ? $now - $before : ($now - $before) / $before * 100);
    if (abs($diff) < 0.05) {
        return '<span style="color:#5f6b7a;">no change</span>';
    }
    $good = $lowerIsBetter ? $diff < 0 : $diff > 0;
    $text = $points ? sprintf('%+.1f pts', $diff) : ($lowerIsBetter ? sprintf('%+.1f', $diff) : sprintf('%+.1f %%', $diff));

    return '<span style="color:' . ($good ? '#1b6b43' : '#a64614') . ';">' . ($diff > 0 ? '▲' : '▼') . ' ' . $view->e($text) . '</span>';
};
$tile = static fn(string $label, string $value, string $delta, string $note) => '<td style="padding:12px 14px;border:1px solid #dde2e8;border-radius:8px;vertical-align:top;width:25%;">'
    . '<div style="font-size:12px;' . 'color:#5f6b7a;">' . $view->e($label) . '</div>'
    . '<div style="font-size:24px;font-weight:600;color:#141a22;margin:4px 0;">' . $view->e($value) . '</div>'
    . ($note !== '' ? '<div style="font-size:11px;color:#5f6b7a;">' . $view->e($note) . '</div>' : '')
    . ($delta !== '' ? '<div style="font-size:12px;margin-top:2px;">' . $delta . '</div>' : '')
    . '</td>';
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= $view->e($project->name . ': ' . $report['label']) ?></title>
</head>
<body style="margin:0;padding:0;background:#f5f6f8;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f5f6f8;">
    <tr><td align="center" style="padding:24px 12px;">
        <table role="presentation" width="640" cellpadding="0" cellspacing="0" style="max-width:640px;width:100%;background:#ffffff;border:1px solid #dde2e8;border-radius:10px;<?= $font ?>color:#141a22;">
            <tr><td style="padding:24px 24px 8px;">
                <div style="font-size:12px;<?= $muted ?>"><span style="color:<?= $view->e($accent) ?>;font-weight:600;"><?= $view->e($branding->name()) ?></span> · SEO report · <?= $view->e($project->primaryDomain) ?></div>
                <h1 style="font-size:22px;margin:6px 0 4px;"><?= $view->e($project->name) ?>: <?= $view->e($report['label']) ?></h1>
                <div style="font-size:13px;<?= $muted ?>"><?= $view->e($report['period']->fromString()) ?> – <?= $view->e($report['period']->toString()) ?>, compared with <?= $view->e($report['previousPeriod']->fromString()) ?> – <?= $view->e($report['previousPeriod']->toString()) ?></div>
            </td></tr>

            <?php if ($sections->has('search')): ?>
            <?php if ($search === null): ?>
                <tr><td style="padding:16px 24px;font-size:14px;">No Search Console or Bing data is linked to this project yet.</td></tr>
            <?php else: ?>
                <?php
                $g = $search['google'];
                $b = $search['bing'];
                $c = $search['combined'];
                $p = $search['combinedPrevious'];
                $split = static fn(int $google, int $bing): string => $g !== null && $b !== null ? 'Google ' . number_format($google) . ' · Bing ' . number_format($bing) : '';
                ?>
                <tr><td style="padding:16px 24px 4px;">
                    <div style="font-size:13px;font-weight:600;margin-bottom:8px;">Search<?= $g !== null && $b !== null ? ' · Google + Bing' : ($g !== null ? ' · Google' : ' · Bing') ?></div>
                    <table role="presentation" width="100%" cellpadding="0" cellspacing="6" style="border-collapse:separate;">
                        <tr>
                            <?= $tile('Clicks', $num($c->clicks), $change($c->clicks, $p->clicks), $split($g->clicks ?? 0, $b->clicks ?? 0)) ?>
                            <?= $tile('Impressions', $num($c->impressions), $change($c->impressions, $p->impressions), $split($g->impressions ?? 0, $b->impressions ?? 0)) ?>
                            <?= $tile('CTR', $pct($c->ctr()), $p->impressions > 0 ? $change($c->ctr(), $p->ctr(), points: true) : '', '') ?>
                            <?php if ($g !== null && $g->impressions > 0): ?>
                                <?= $tile('Avg. position', number_format($g->position, 1), $search['googlePrevious'] !== null && $search['googlePrevious']->impressions > 0 ? $change($g->position, $search['googlePrevious']->position, lowerIsBetter: true) : '', 'Google') ?>
                            <?php endif; ?>
                        </tr>
                    </table>
                </td></tr>
                <?php foreach ([['Top pages', 'Page', $report['pages'], true], ['Top queries', 'Query', $report['queries'], false]] as [$title, $column, $rows, $isUrl]): ?>
                    <?php if ($rows !== []): ?>
                        <tr><td style="padding:16px 24px 4px;">
                            <div style="font-size:13px;font-weight:600;margin-bottom:6px;"><?= $view->e($title) ?></div>
                            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="font-size:13px;border-collapse:collapse;">
                                <tr style="<?= $muted ?>font-size:11px;text-align:right;">
                                    <td style="text-align:left;padding:4px 0;border-bottom:1px solid #dde2e8;"><?= $view->e($column) ?></td>
                                    <td style="padding:4px 0 4px 8px;border-bottom:1px solid #dde2e8;">Clicks</td>
                                    <td style="padding:4px 0 4px 8px;border-bottom:1px solid #dde2e8;">Impr.</td>
                                    <td style="padding:4px 0 4px 8px;border-bottom:1px solid #dde2e8;">Pos.</td>
                                </tr>
                                <?php foreach ($rows as $row): ?>
                                    <tr style="text-align:right;">
                                        <td style="text-align:left;padding:5px 0;border-bottom:1px solid #edf0f3;word-break:break-word;"><?= $view->e($isUrl ? (preg_replace('#^https?://[^/]+#', '', $row->key) ?: '/') : $row->key) ?></td>
                                        <td style="padding:5px 0 5px 8px;border-bottom:1px solid #edf0f3;"><?= $view->e($num($row->metrics->clicks)) ?></td>
                                        <td style="padding:5px 0 5px 8px;border-bottom:1px solid #edf0f3;"><?= $view->e($num($row->metrics->impressions)) ?></td>
                                        <td style="padding:5px 0 5px 8px;border-bottom:1px solid #edf0f3;"><?= $row->metrics->impressions > 0 ? $view->e(number_format($row->metrics->position, 1)) : '–' ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </table>
                        </td></tr>
                    <?php endif; ?>
                <?php endforeach; ?>
            <?php endif; ?>

            <?php endif; ?>
            <?php if ($sections->has('rankings') && $rankings !== null): ?>
                <?php $day = $rankings['day']; $before = $rankings['previous']; ?>
                <tr><td style="padding:16px 24px 4px;font-size:13px;">
                    <div style="font-weight:600;margin-bottom:6px;">Rankings <span style="font-weight:400;<?= $muted ?>">· <?= $view->e($day->keywords) ?> keywords, <?= $view->e($rankings['device']) ?>, check of <?= $view->e($day->date) ?></span></div>
                    Visibility <strong><?= $view->e(number_format($day->visibility, 1)) ?> %</strong><?= $before !== null ? ' ' . $change($day->visibility / 100, $before->visibility / 100, points: true) : '' ?>
                    · Top 3 <strong><?= $view->e($day->top3) ?></strong> · Top 10 <strong><?= $view->e($day->top10) ?></strong>
                    <?php foreach ([['Winners', $rankings['winners']], ['Losers', $rankings['losers']]] as [$title, $moves]): ?>
                        <?php if ($moves !== []): ?>
                            <div style="margin-top:6px;"><?= $view->e($title) ?>: <?= $view->e(implode(', ', array_map(static fn($m): string => sprintf('%s (%s → %s)', $m->keyword, $m->from ?? '>100', $m->to ?? '>100'), $moves))) ?></div>
                        <?php endif; ?>
                    <?php endforeach; ?>
                </td></tr>
            <?php endif; ?>

            <?php if ($sections->has('analytics') && ($report['analytics'] ?? null) !== null): ?>
                <?php $analytics = $report['analytics']; $meta = $analytics['metadata']; ?>
                <tr><td style="padding:18px 24px;"><div style="font-weight:600;">Organic-search conversions and revenue (GA4)</div>
                    <p><?= $view->e($meta['from'] . ' – ' . $meta['to'] . ', ' . $meta['timezone'] . ', ' . $meta['currency']) ?></p>
                    <?php if ($analytics['partial'] || $meta['limited'] || $meta['thresholded']): ?><p>Figures may be incomplete: period coverage, row limits or Google privacy thresholds apply.</p><?php endif; ?>
                    <table role="presentation" width="100%" cellpadding="5" cellspacing="0"><tbody>
                    <?php foreach ($analytics['rows'] as $row): ?><tr><td><?= $view->e($row['url']) ?></td><td><?= $view->e(number_format($row['sessions'])) ?> sessions</td><td><?= $view->e(number_format($row['conversions'], 2)) ?> conversions</td><td><?= $view->e(number_format($row['revenue'], 2) . ' ' . $meta['currency']) ?></td></tr><?php endforeach; ?>
                    </tbody></table>
                </td></tr>
            <?php endif; ?>
            <?php if ($sections->has('audit') && $report['siteAudit'] !== null): ?>
                <?php $audit = $report['siteAudit']; ?>
                <tr><td style="padding:16px 24px 4px;font-size:13px;">
                    <div style="font-weight:600;margin-bottom:6px;">Site audit <span style="font-weight:400;<?= $muted ?>">· <?= $view->e(number_format($audit['pages'])) ?> pages, audit of <?= $view->e($audit['date']) ?></span></div>
                    Health score <strong><?= $audit['score'] !== null ? $view->e($audit['score']) . ' / 100' : '–' ?></strong><?= $audit['score'] !== null && $audit['previousScore'] !== null ? ' (' . $view->e(sprintf('%+d', $audit['score'] - $audit['previousScore'])) . ')' : '' ?>
                    · Errors <strong><?= $view->e($audit['errors']) ?></strong> · Warnings <strong><?= $view->e($audit['warnings']) ?></strong>
                    <?php if ($audit['issues'] !== []): ?>
                        <div style="margin-top:6px;"><?= $view->e(implode(', ', array_map(static fn(array $i): string => sprintf('%s (%d)', $i['label'], $i['pages']), $audit['issues']))) ?></div>
                    <?php endif; ?>
                </td></tr>
            <?php endif; ?>

            <?php if ($sections->has('opportunities')): ?><tr><td style="padding:16px 24px 4px;font-size:13px;">
                <div style="font-weight:600;margin-bottom:6px;">Opportunities <span style="font-weight:400;<?= $muted ?>">· <?= $view->e($report['openOpportunities']) ?> open</span></div>
                <?php if ($report['opportunities'] !== []): ?>
                    <ol style="margin:0;padding-left:20px;">
                        <?php foreach ($report['opportunities'] as $opportunity): ?>
                            <li style="margin:3px 0;"><?= $view->e($opportunity->title) ?></li>
                        <?php endforeach; ?>
                    </ol>
                <?php endif; ?>
            </td></tr>

                <?php endif; ?>
            <tr><td style="padding:20px 24px 24px;">
                <a href="<?= $view->e($url) ?>" style="display:inline-block;background:<?= $view->e($accent) ?>;color:#ffffff;text-decoration:none;padding:10px 16px;border-radius:6px;font-size:14px;font-weight:600;">Open the full report</a>
                <div style="font-size:12px;<?= $muted ?>margin-top:14px;"><?= $view->e($branding->reportFooter ?? 'Sent by ' . $branding->name() . '. Change or stop this report under Project → Reports.') ?></div>
            </td></tr>
        </table>
    </td></tr>
</table>
</body>
</html>
