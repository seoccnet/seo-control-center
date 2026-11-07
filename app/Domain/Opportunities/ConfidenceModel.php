<?php

declare(strict_types=1);

namespace SeoControlCenter\Domain\Opportunities;

use SeoControlCenter\Domain\Keywords\Keyword;

/** Explicit policy, not a probability or a causal claim. Built once per analysis. */
final class ConfidenceModel
{
    /** @var array<string, list<ConfidenceObservation>> */
    private array $observations = [];

    /** @var array<string, array{volume: ?int, difficulty: ?int, source: string}> */
    private array $research = [];

    /** @var array<string, int|null> */
    private array $volumes = [];

    /** @var array<string, ConfidenceObservation> */
    private array $googlePages = [];

    public function __construct(private readonly ProjectSignals $signals)
    {
        foreach ($signals->googlePages ?? [] as $row) {
            $this->googlePages[$row->key] = new ConfidenceObservation('page', $row->key, 'search', (int) $row->metrics->impressions, (int) $row->metrics->clicks, $row->metrics->position, null, 0, $signals->analysisDate ?? '');
        }
        foreach ($signals->confidenceObservations as $row) {
            if (($signals->coverage[$row->source]['status'] ?? 'missing') === 'available') {
                $this->observations[self::key($row->kind, $row->key)][] = $row;
            }
        }
        foreach (['query' => $signals->queries, 'page' => $signals->pages] as $kind => $rows) {
            foreach ($rows as $row) {
                $key = self::key($kind, $row->key);
                // Merged search totals provide sample size, never a fabricated daily deviation.
                $this->observations[$key][] = new ConfidenceObservation($kind, $row->key, 'search', (int) $row->metrics->impressions, (int) $row->metrics->clicks, $row->metrics->position, null, 0, $signals->analysisDate ?? '');
            }
        }
        if (($signals->coverage['rankings']['status'] ?? 'missing') === 'available') {
            foreach ($signals->keywords as $row) {
                $this->volumes[Keyword::normalize($row->keyword)] = $row->searchVolume;
            }
        }
        foreach (['research_ranked' => $signals->researchRanked, 'research_gap' => array_column($signals->researchGaps, 'keyword')] as $source => $rows) {
            if (($signals->coverage[$source]['status'] ?? 'missing') !== 'available') {
                continue;
            }
            foreach ($rows as $row) {
                $key = Keyword::normalize($row->keyword);
                $this->research[$key] ??= ['volume' => $row->searchVolume, 'difficulty' => $row->difficulty, 'source' => $source];
                if ($source === 'research_ranked' && $row->position !== null) {
                    $this->observations[self::key('query', $key)][] = new ConfidenceObservation('query', $key, $source, null, null, (float) $row->position, null, 1, $signals->coverage[$source]['date'] ?? '');
                }
            }
        }
    }

    /** @param list<string> $sources
     * @return array{score: int, reasons: list<string>, sources: list<string>}
     */
    public function assess(Opportunity $opportunity, array $sources): array
    {
        $kind = null;
        $subject = null;
        foreach ($opportunity->evidence as $item) {
            if (in_array($item['label'], ['Query', 'Keyword', 'Page', 'URL'], true)) {
                $kind = in_array($item['label'], ['Page', 'URL'], true) ? 'page' : 'query';
                $subject = $item['value'];
                break;
            }
        }
        $rows = $kind !== null && $subject !== null ? ($this->observations[self::key($kind, $subject)] ?? []) : [];
        if ($opportunity->type === OpportunityType::TrafficDecline && $subject !== null && $this->signals->googlePages !== null) {
            $rows = array_values(array_filter($rows, static fn(ConfidenceObservation $row): bool => $row->source === 'search_console'));
            if (isset($this->googlePages[$subject])) {
                $rows[] = $this->googlePages[$subject];
            }
        }
        $score = 35;
        $reasons = ['base 35'];
        if ($opportunity->type === OpportunityType::TrafficDecline && $subject !== null && ($this->signals->trends?->seasonalRatios[$subject] ?? 1.0) < 1.0) {
            $score -= 10;
            $reasons[] = 'seasonal monthly context makes the exact loss estimate less certain −10';
        }
        $sample = null;
        $clicks = null;
        foreach ($rows as $row) {
            if ($row->impressions !== null && ($sample === null || $row->impressions > $sample)) {
                $sample = $row->impressions;
                $clicks = $row->clicks;
            }
            if ($row->source !== 'search') {
                $sources[] = $row->source;
            } else {
                array_push($sources, ...($opportunity->type === OpportunityType::TrafficDecline ? ['search_console'] : $this->signals->searchSources));
            }
        }
        if ($sample !== null) {
            $points = (int) min(25, round(5 * log10(1 + max(0, $sample))));
            $score += $points;
            $reasons[] = sprintf('%s impressions, %s clicks +%d', number_format($sample), $clicks !== null ? number_format($clicks) : 'unknown', $points);
        } else {
            $score -= 5;
            $reasons[] = 'search sample unknown −5';
        }
        $history = array_values(array_filter($rows, static fn(ConfidenceObservation $r): bool => $r->days >= 3 && $r->deviation !== null));
        if ($history !== []) {
            // Conservative: use the most volatile available series, not the most flattering.
            usort($history, static fn(ConfidenceObservation $a, ConfidenceObservation $b): int => $b->deviation <=> $a->deviation);
            $deviation = $history[0]->deviation ?? 0.0;
            $points = (int) round(15 - min(25.0, 3 * $deviation));
            $score += $points;
            $reasons[] = sprintf('daily position SD ±%.1f across %d observations (%s) %+d', $deviation, $history[0]->days, $history[0]->source, $points);
        } else {
            $score -= 5;
            $reasons[] = 'fewer than 3 daily positions; stability unknown −5';
        }
        $sources = array_values(array_unique($sources));
        $research = $kind === 'query' && $subject !== null ? ($this->research[Keyword::normalize($subject)] ?? null) : null;
        if ($research !== null) {
            $sources[] = $research['source'];
        }
        if ($kind === 'query' && $subject !== null && isset($this->volumes[Keyword::normalize($subject)])) {
            $sources[] = 'rankings';
        }
        $sources = array_values(array_unique($sources));
        $ageRatio = 0.0;
        $freshnessKnown = $this->signals->analysisDate !== null && $sources !== [];
        foreach ($sources as $source) {
            $date = $this->signals->coverage[$source]['date'] ?? null;
            foreach ($rows as $row) {
                if ($row->source === $source && $row->date !== '') {
                    $date = $date === null ? $row->date : min($date, $row->date);
                }
            }
            if ($date === null || $this->signals->analysisDate === null) {
                $freshnessKnown = false;
                continue;
            }
            $age = (int) (new \DateTimeImmutable($date))->diff(new \DateTimeImmutable($this->signals->analysisDate))->format('%r%a');
            $cadence = $this->signals->refreshDays[$source] ?? 30;
            $expectedLag = match ($source) {
                'search_console_previous' => 28, 'ranking_comparison' => 7, default => 0
            };
            $ageRatio = max($ageRatio, max(0, $age - $expectedLag) / max(1, $cadence));
            $reasons[] = sprintf('%s %d days old (refresh %d days%s)', $source, $age, $cadence, $expectedLag > 0 ? ', expected comparison lag ' . $expectedLag . ' days' : '');
        }
        $freshness = $freshnessKnown ? (int) round(10 * max(0.0, 1 - min(1.0, $ageRatio))) : 0;
        $score += $freshness;
        $reasons[] = $freshnessKnown ? 'freshness +' . $freshness : 'freshness unknown +0';
        $positions = [];
        foreach ($rows as $row) {
            if ($row->source !== 'search' && $row->position !== null && $row->position > 0) {
                $positions[$row->source] = $row->position;
            }
        }
        if (count($positions) >= 2) {
            $spread = max($positions) - min($positions);
            $agreement = $spread <= 3 ? 10 : -10;
            $score += $agreement;
            $reasons[] = sprintf('position sources %s differ by %.1f %+d', implode(', ', array_keys($positions)), $spread, $agreement);
        } else {
            $reasons[] = 'independent position agreement unknown +0';
        }
        if ($kind === 'query' && $subject !== null) {
            $key = Keyword::normalize($subject);
            $volume = $this->volumes[$key] ?? $research['volume'] ?? null;
            $difficulty = $research['difficulty'] ?? null;
            $score -= $volume === null ? 10 : 0;
            $score -= $difficulty === null ? 5 : 0;
            $reasons[] = $volume === null ? 'volume unknown −10' : 'volume known +0';
            $reasons[] = $difficulty === null ? 'difficulty unknown −5' : 'difficulty known +0';
        } else {
            $reasons[] = 'keyword volume/difficulty not applicable +0';
        }
        return ['score' => max(0, min(100, $score)), 'reasons' => $reasons, 'sources' => array_values(array_unique($sources))];
    }

    private static function key(string $kind, string $key): string
    {
        return $kind . ':' . ($kind === 'query' ? Keyword::normalize($key) : $key);
    }
}
