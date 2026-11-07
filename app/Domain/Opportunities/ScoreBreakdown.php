<?php

declare(strict_types=1);

namespace SeoControlCenter\Domain\Opportunities;

/**
 * One breakdown of an opportunity's score (ADR-038, D3), the same in the
 * opportunity page, reports and MCP: demand, relevance, impact,
 * confidence, effort, risk and evidence, each with the basis the analysis
 * recorded, and the priority formula that combines them. It only reads
 * what the analysis stored; it never recomputes a score.
 */
final class ScoreBreakdown
{
    private const array EFFORT = [Opportunity::EFFORT_LOW => 'low', Opportunity::EFFORT_MEDIUM => 'medium', Opportunity::EFFORT_HIGH => 'high'];

    /**
     * @return array{priority: float, formula: string, factors: list<array{key: string, label: string, value: string, basis: string}>}
     */
    public static function of(StoredOpportunity $o): array
    {
        $value = static fn(string ...$labels): string => implode(' ', array_values(array_filter(array_map(static fn(string $l): ?string => $o->evidenceValue($l), $labels), static fn(?string $v): bool => $v !== null && $v !== '')));
        [$confidence, $reasons] = self::confidence($o);
        $relevance = array_values(array_filter([
            $o->verdict() !== null ? ucfirst((string) $o->verdict()) : null,
            $o->businessPotential() !== null ? 'business potential ' . $o->businessPotential() . '/3' : null,
            $o->evidenceValue('Intent'),
        ]));
        $risk = $o->zeroClickRisk();

        return [
            'priority' => $o->priority,
            'formula' => sprintf('%d impact × %d confidence / 100 / %d effort = %.2f priority', $o->impact, $o->confidence, $o->effort, $o->priority),
            'factors' => [
                ['key' => 'demand', 'label' => 'Demand', 'value' => $o->estimatedClicks !== null ? sprintf('about %s extra clicks a month', self::number($o->estimatedClicks)) : 'not estimated', 'basis' => $value('Impact basis', 'Value-weighted exposure', 'Estimated gain')],
                ['key' => 'relevance', 'label' => 'Relevance', 'value' => $relevance !== [] ? implode(' · ', $relevance) : 'not scored', 'basis' => $value('BID verdict', 'Business weight', 'Value assumption')],
                ['key' => 'impact', 'label' => 'Impact', 'value' => $o->impact . ' of 100', 'basis' => $value('Impact calculation')],
                ['key' => 'confidence', 'label' => 'Confidence', 'value' => $confidence . ' of 100', 'basis' => $reasons],
                ['key' => 'effort', 'label' => 'Effort', 'value' => self::EFFORT[$o->effort] ?? (string) $o->effort, 'basis' => $value('Effort calculation', 'Time to value')],
                ['key' => 'risk', 'label' => 'Risk', 'value' => $risk !== null ? $risk . ' zero-click risk' : 'none recorded', 'basis' => $value('Zero-click risk', 'Prerequisites')],
                ['key' => 'evidence', 'label' => 'Evidence', 'value' => (string) ($o->evidenceValue('Required sources') ?? 'not recorded'), 'basis' => count($o->evidence) . ' evidence items, last detected ' . substr($o->lastDetectedAt, 0, 10)],
            ],
        ];
    }

    /** One line for reports: "priority 3.73 · impact 70 · confidence 16 · effort high · about 120 extra clicks a month". */
    public static function summary(StoredOpportunity $o): string
    {
        return sprintf('priority %.2f · impact %d · confidence %d · effort %s', $o->priority, $o->impact, $o->confidence, self::EFFORT[$o->effort] ?? (string) $o->effort)
            . ($o->estimatedClicks !== null ? ' · about ' . self::number($o->estimatedClicks) . ' extra clicks a month' : '');
    }

    /**
     * The confidence score and its reasons from the "Confidence" evidence
     * ("62: 1,200 impressions; stable position").
     *
     * @return array{0: int, 1: string}
     */
    private static function confidence(StoredOpportunity $o): array
    {
        $raw = (string) $o->evidenceValue('Confidence');

        return preg_match('/^(\d+):\s*(.*)$/s', $raw, $m) === 1 ? [$o->confidence, trim($m[2])] : [$o->confidence, $raw];
    }

    private static function number(float $value): string
    {
        return $value >= 10 ? number_format($value) : number_format($value, 1);
    }
}
