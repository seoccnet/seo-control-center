<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\Reports;

use SeoControlCenter\Core\Branding\Branding;
use SeoControlCenter\Domain\SearchConsole\DimensionRow;
use SeoControlCenter\Domain\SearchConsole\Metrics;

/**
 * The plain-text e-mail of a report (ADR-027: e-mails are plain text). The
 * full, printable report is linked at the end.
 */
final class ReportText
{
    private function __construct() {}

    /**
     * @param array<string, mixed> $report from ReportBuilder::build()
     */
    public static function subject(array $report): string
    {
        return sprintf('SEO report %s: %s', $report['project']->name, $report['label']);
    }

    /**
     * @param array{
     *     project: \SeoControlCenter\Domain\Projects\Project, frequency: string, period: \SeoControlCenter\Domain\Shared\DateRange, previousPeriod: \SeoControlCenter\Domain\Shared\DateRange, label: string,
     *     search: array{google: ?Metrics, googlePrevious: ?Metrics, bing: ?Metrics, bingPrevious: ?Metrics, combined: Metrics, combinedPrevious: Metrics}|null,
     *     queries: list<DimensionRow>, pages: list<DimensionRow>,
     *     rankings: array{day: \SeoControlCenter\Domain\Rankings\RankingDay, previous: ?\SeoControlCenter\Domain\Rankings\RankingDay, winners: list<\SeoControlCenter\Domain\Rankings\RankingMove>, losers: list<\SeoControlCenter\Domain\Rankings\RankingMove>, device: string}|null,
     *     openOpportunities: int, opportunities: list<\SeoControlCenter\Domain\Opportunities\StoredOpportunity>,
     *     siteAudit: array{date: string, score: ?int, pages: int, errors: int, warnings: int, previousScore: ?int, previousDate: ?string, issues: list<array{label: string, severity: string, pages: int}>}|null
     * } $report
     */
    public static function body(array $report, string $reportUrl, ?Branding $branding = null, ReportSections $sections = new ReportSections()): string
    {
        $project = $report['project'];
        $lines = [
            sprintf('SEO report · %s (%s)', $project->name, $project->primaryDomain),
            sprintf('%s (%s – %s), compared with %s – %s', $report['label'], $report['period']->fromString(), $report['period']->toString(), $report['previousPeriod']->fromString(), $report['previousPeriod']->toString()),
            '',
        ];

        $search = $report['search'];
        if ($sections->has('search')) {
            if ($search === null) {
                $lines[] = 'SEARCH: no Search Console or Bing data linked yet.';
            } else {
                $g = $search['google'];
                $b = $search['bing'];
                $lines[] = 'SEARCH' . ($g !== null && $b !== null ? ' (Google + Bing)' : ($g !== null ? ' (Google)' : ' (Bing)'));
                $c = $search['combined'];
                $p = $search['combinedPrevious'];
                $lines[] = self::row('Clicks', self::int($c->clicks), self::change($c->clicks, $p->clicks), $g !== null && $b !== null ? sprintf('Google %s · Bing %s', self::int($g->clicks), self::int($b->clicks)) : '');
                $lines[] = self::row('Impressions', self::int($c->impressions), self::change($c->impressions, $p->impressions), $g !== null && $b !== null ? sprintf('Google %s · Bing %s', self::int($g->impressions), self::int($b->impressions)) : '');
                $lines[] = self::row('CTR', self::percent($c->ctr()), sprintf('%+.1f pts', ($c->ctr() - $p->ctr()) * 100), '');
                if ($search['google'] !== null && $search['google']->impressions > 0) {
                    $before = $search['googlePrevious'];
                    $lines[] = self::row('Avg. position', number_format($search['google']->position, 1), $before !== null && $before->impressions > 0 ? sprintf('%+.1f', $search['google']->position - $before->position) : '', 'Google');
                }
                $lines[] = '';
                foreach ([['TOP PAGES', $report['pages'], true], ['TOP QUERIES', $report['queries'], false]] as [$title, $rows, $isUrl]) {
                    if ($rows === []) {
                        continue;
                    }
                    $lines[] = $title;
                    foreach ($rows as $i => $row) {
                        $key = $isUrl ? (preg_replace('#^https?://[^/]+#', '', $row->key) ?: '/') : $row->key;
                        $lines[] = sprintf('%3d. %s: %s clicks, %s impressions, position %s', $i + 1, mb_strimwidth($key, 0, 70, '…'), self::int($row->metrics->clicks), self::int($row->metrics->impressions), number_format($row->metrics->position, 1));
                    }
                    $lines[] = '';
                }
            }

        }
        $rankings = $report['rankings'];
        if ($sections->has('rankings') && $rankings !== null) {
            $day = $rankings['day'];
            $previous = $rankings['previous'];
            $lines[] = sprintf('RANKINGS (%d keywords, %s, check of %s)', $day->keywords, $rankings['device'], $day->date);
            $lines[] = self::row('Visibility', number_format($day->visibility, 1) . ' %', $previous !== null ? sprintf('%+.1f pts', $day->visibility - $previous->visibility) : '', '');
            $lines[] = self::row('Top 3', (string) $day->top3, $previous !== null ? sprintf('%+d', $day->top3 - $previous->top3) : '', '');
            $lines[] = self::row('Top 10', (string) $day->top10, $previous !== null ? sprintf('%+d', $day->top10 - $previous->top10) : '', '');
            foreach ([['Winners', $rankings['winners']], ['Losers', $rankings['losers']]] as [$title, $moves]) {
                if ($moves !== []) {
                    $lines[] = $title . ': ' . implode(', ', array_map(static fn($m): string => sprintf('%s (%s → %s)', $m->keyword, $m->from ?? '>100', $m->to ?? '>100'), $moves));
                }
            }
            $lines[] = '';
        }

        $analytics = $report['analytics'] ?? null;
        if ($sections->has('analytics') && $analytics !== null) {
            $meta = $analytics['metadata'];
            $lines[] = 'GA4 ORGANIC-SEARCH LANDING PAGES (' . $meta['currency'] . ', ' . $meta['timezone'] . ')';
            $lines[] = 'Reporting period: ' . $meta['from'] . ' – ' . $meta['to'] . ', last import ' . $meta['fetched_at'] . ' UTC';
            if ($analytics['partial'] || $meta['limited'] || $meta['thresholded']) {
                $lines[] = 'Figures may be incomplete: period coverage, row limits or Google privacy thresholds apply.';
            }
            foreach ($analytics['rows'] as $row) {
                $lines[] = sprintf('%s: %s sessions, %.2f conversions, %.2f %s purchase revenue', $row['url'], self::int($row['sessions']), $row['conversions'], $row['revenue'], $meta['currency']);
            }
            $lines[] = '';
        }
        $audit = $report['siteAudit'];
        if ($sections->has('audit') && $audit !== null) {
            $lines[] = sprintf('SITE AUDIT (%d pages, %s)', $audit['pages'], $audit['date']);
            $lines[] = self::row('Health score', $audit['score'] !== null ? $audit['score'] . ' / 100' : '–', $audit['score'] !== null && $audit['previousScore'] !== null ? sprintf('%+d', $audit['score'] - $audit['previousScore']) : '', '');
            $lines[] = self::row('Errors', (string) $audit['errors'], '', '');
            $lines[] = self::row('Warnings', (string) $audit['warnings'], '', '');
            foreach ($audit['issues'] as $issue) {
                $lines[] = sprintf('  - %s: %d page%s', $issue['label'], $issue['pages'], $issue['pages'] === 1 ? '' : 's');
            }
            $lines[] = '';
        }

        $rendered = $report['renderedAudit'] ?? null;
        if ($sections->has('audit') && $rendered !== null) {
            $lines[] = sprintf('BROWSER AUDIT (%d selected pages, %s)', $rendered['pages'], $rendered['date']);
            foreach ($rendered['issues'] as $issue) {
                $lines[] = sprintf('  - %s: %d pages. %s', $issue['label'], $issue['pages'], $issue['advice']);
            }
            $lines[] = '';
        }

        if ($sections->has('opportunities')) {
            $lines[] = sprintf('OPPORTUNITIES: %d open', $report['openOpportunities']);
            foreach ($report['opportunities'] as $opportunity) {
                $lines[] = '  - ' . $opportunity->title;
            }
            $lines[] = '';
        }
        $lines[] = 'Full report (charts, print or save as PDF):';
        $lines[] = $reportUrl;
        $lines[] = '';
        $lines[] = '--';
        $branding ??= Branding::none();
        $lines[] = $branding->reportFooter ?? sprintf('Sent by %s. Change or stop this report under Project → Reports.', $branding->name());

        return implode("\n", $lines) . "\n";
    }

    private static function row(string $label, string $value, string $change, string $note): string
    {
        return rtrim(sprintf('  %-14s %12s  %-11s %s', $label, $value, $change, $note));
    }

    private static function change(int $now, int $before): string
    {
        return $before > 0 ? sprintf('%+.1f %%', ($now - $before) / $before * 100) : '';
    }

    private static function int(int $value): string
    {
        return number_format($value);
    }

    private static function percent(float $fraction): string
    {
        return number_format($fraction * 100, 1) . ' %';
    }
}
