<?php

declare(strict_types=1);

namespace SeoControlCenter\Domain\Comparison;

/**
 * One "Why they outrank you" run for a saved topic (ADR-038).
 */
final readonly class Analysis
{
    public const string QUEUED = 'queued';
    public const string RUNNING = 'running';
    public const string DONE = 'done';
    public const string FAILED = 'failed';
    public const string STORED = 'stored';
    public const string PAID = 'paid';

    /**
     * @param list<string> $keywords the topic's searches, main search first
     * @param list<array{position: int, url: string, domain: string, title: ?string, type: string, confidence: string, signals: list<string>, own: bool, fetched: bool}> $results
     * @param array<string, PageStructure> $pages fetched pages by URL
     * @param array<string, mixed> $verdict format, own_type, coverage, diagnosis
     */
    public function __construct(
        public string $publicId,
        public int $projectId,
        public int $workspaceId,
        public string $clusterId,
        public string $label,
        public string $keyword,
        public array $keywords,
        public float $demand,
        public string $status,
        public string $serpSource,
        public int $locationCode,
        public string $languageCode,
        public array $results,
        public ?string $ownUrl,
        public ?int $ownPosition,
        public array $pages,
        public array $verdict,
        public ?float $cost,
        public ?string $error,
        public string $requestedAt,
        public ?string $finishedAt,
    ) {}

    public function active(): bool
    {
        return $this->status === self::QUEUED || $this->status === self::RUNNING;
    }

    /** @return list<array{type: string, title: string, what: string, evidence: list<string>, effort: string, weight: float}> */
    public function actions(): array
    {
        $raw = $this->verdict['diagnosis']['actions'] ?? [];
        $actions = [];
        foreach (is_array($raw) ? $raw : [] as $a) {
            if (is_array($a) && is_string($a['type'] ?? null) && is_string($a['title'] ?? null) && is_string($a['what'] ?? null) && is_array($a['evidence'] ?? null) && is_string($a['effort'] ?? null) && is_numeric($a['weight'] ?? null)) {
                $actions[] = ['type' => $a['type'], 'title' => $a['title'], 'what' => $a['what'], 'evidence' => array_values(array_filter($a['evidence'], is_string(...))), 'effort' => $a['effort'], 'weight' => (float) $a['weight']];
            }
        }

        return $actions;
    }

    /**
     * The validated AI explanation (D2), or null without one.
     *
     * @return array<string, mixed>|null
     */
    public function explanation(): ?array
    {
        $value = $this->verdict['explanation'] ?? null;

        return is_array($value) ? $value : null;
    }

    /**
     * The AI text for one action by its index, when the explanation has it.
     *
     * @return array{what_to_do: string, impact_rationale: string, outline: list<string>}|null
     */
    public function actionText(int $index): ?array
    {
        $explanation = $this->explanation();
        foreach (is_array($explanation['actions'] ?? null) ? $explanation['actions'] : [] as $a) {
            if (is_array($a) && ($a['index'] ?? null) === $index && is_string($a['what_to_do'] ?? null)) {
                $outline = [];
                foreach (is_array($explanation['brief_seeds'] ?? null) ? $explanation['brief_seeds'] : [] as $seed) {
                    if (is_array($seed) && ($seed['action_index'] ?? null) === $index && is_array($seed['outline'] ?? null)) {
                        $outline = array_values(array_filter($seed['outline'], is_string(...)));
                    }
                }

                return ['what_to_do' => $a['what_to_do'], 'impact_rationale' => is_string($a['impact_rationale'] ?? null) ? $a['impact_rationale'] : '', 'outline' => $outline];
            }
        }

        return null;
    }

    /**
     * The internal-link recommendations (D2). Placement and reason are the
     * rule-based ones: the AI does not see the source pages, so it cannot
     * place a link on them (0.19.1).
     *
     * @return list<array{source: string, target: string, anchors: list<string>, placement: string, reason: string, kind: string, impressions: int}>
     */
    public function links(): array
    {
        $raw = $this->verdict['links']['links'] ?? [];
        $links = [];
        foreach (is_array($raw) ? $raw : [] as $l) {
            if (!is_array($l) || !is_string($l['source'] ?? null) || !is_string($l['target'] ?? null)) {
                continue;
            }
            $links[] = ['source' => $l['source'], 'target' => $l['target'], 'anchors' => is_array($l['anchors'] ?? null) ? array_values(array_filter($l['anchors'], is_string(...))) : [], 'placement' => (string) ($l['placement'] ?? ''), 'reason' => (string) ($l['reason'] ?? ''), 'kind' => (string) ($l['kind'] ?? ''), 'impressions' => is_numeric($l['impressions'] ?? null) ? (int) $l['impressions'] : 0];
        }

        return $links;
    }

    public function bottleneck(): ?string
    {
        $value = $this->verdict['diagnosis']['bottleneck'] ?? null;

        return is_string($value) ? $value : null;
    }
}
