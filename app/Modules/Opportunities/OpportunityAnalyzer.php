<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\Opportunities;

use Psr\Log\LoggerInterface;
use SeoControlCenter\Domain\Opportunities\OpportunityRepository;
use SeoControlCenter\Domain\Opportunities\OpportunityRule;
use SeoControlCenter\Domain\Rankings\RankingRepository;

/**
 * Runs all rules on a project's stored data and syncs the result. Uses no
 * provider and no budget, so it can run on a schedule and on demand.
 */
final readonly class OpportunityAnalyzer
{
    /** Confidence ceiling for findings from sampled AI answers (ADR-032 amendment). */
    public const int SAMPLED_CONFIDENCE = 30;
    /** Keywords considered for topic clusters, by demand. */
    private const int TOPIC_KEYWORDS = 2000;

    /**
     * @param list<OpportunityRule> $rules
     */
    public function __construct(
        private SignalCollector $collector,
        private array $rules,
        private OpportunityRepository $opportunities,
        private RankingRepository $rankings,
        private LoggerInterface $logger,
        private ?\SeoControlCenter\Domain\Clusters\ClusterRefresher $clusters = null,
    ) {}

    /**
     * @return array{opened: int, updated: int, resolved: int}|null null when the project is gone
     */
    public function analyze(int $projectId, int $workspaceId): ?array
    {
        $domain = $this->rankings->projectDomain($projectId, $workspaceId);
        if ($domain === null) {
            return null; // deleted, or not in this workspace
        }
        $signals = $this->collector->collect($projectId, $workspaceId);
        $detected = [];
        $confidence = new \SeoControlCenter\Domain\Opportunities\ConfidenceModel($signals);
        $calibrator = new \SeoControlCenter\Domain\Opportunities\OutcomeCalibrator($signals->outcomeFactors);
        $effort = new \SeoControlCenter\Domain\Opportunities\EffortModel($signals);
        $context = new \SeoControlCenter\Domain\Opportunities\ContextModel($signals);
        $value = new \SeoControlCenter\Domain\Opportunities\ValueModel($signals);
        $zeroClick = new \SeoControlCenter\Domain\Opportunities\ZeroClickModel($signals);
        $bid = new \SeoControlCenter\Domain\Opportunities\BidModel($signals);
        $topics = self::topics($signals);
        foreach ($this->rules as $rule) {
            foreach ($rule->evaluate($signals) as $opportunity) {
                if (!\SeoControlCenter\Domain\Opportunities\SignalRequirements::subjectComplete($opportunity->type->value, $opportunity->subject, $signals->coverage)) {
                    continue;
                }
                $sources = $rule->requires($opportunity);
                if (in_array($opportunity->type->value, ['low_ctr', 'content_gap'], true) || ($opportunity->type->value === 'quick_win' && (str_starts_with($opportunity->subject, 'query:') || str_starts_with($opportunity->subject, 'brand:')))) {
                    $sources = $signals->searchSources;
                    if ($sources === []) {
                        continue;
                    }
                }
                if ($signals->ctrModel?->hasSamples() === true && (in_array($opportunity->type->value, ['quick_win', 'low_ctr', 'content_gap', 'keyword_gap', 'competitor_gap', 'ranking_decline', 'format_mismatch', 'tool_idea'], true) || ($opportunity->type->value === 'ai_visibility' && str_starts_with($opportunity->subject, 'aio:')))) {
                    $sources[] = 'search_console';
                }
                if (in_array($opportunity->type->value, ['quick_win', 'content_gap'], true)) {
                    foreach ($opportunity->evidence as $item) {
                        if (in_array($item['label'], ['Query', 'Keyword'], true)) {
                            array_push($sources, ...$signals->difficultySourcesOf($item['value']));
                            break;
                        }
                    }
                }
                $sources = array_values(array_unique($sources));
                if ($opportunity->type->value === 'traffic_decline') {
                    foreach ($opportunity->evidence as $item) {
                        if ($item['label'] === 'Page' && isset($signals->trends?->seasonalRatios[$item['value']])) {
                            $sources[] = 'seasonality';
                        }
                    }
                }
                $opportunity = $calibrator->adjust($opportunity);
                if ($opportunity->estimatedClicks !== null && ($signals->outcomeFactors[$opportunity->type->value]['samples'] ?? 0) >= 5) {
                    $sources[] = 'outcomes';
                }
                $work = $effort->assess($opportunity);
                array_push($sources, ...$work['sources']);
                $contextual = $context->assess($opportunity);
                array_push($sources, ...$contextual['sources']);
                $decision = $bid->assess($opportunity);
                $valuation = $value->assess($opportunity, $decision['business'] ?? null);
                array_push($sources, ...$valuation['sources']);
                $risk = $zeroClick->assess($opportunity);
                array_push($sources, ...($risk['sources'] ?? []));
                $assessment = $confidence->assess($opportunity, $sources);
                $assessment['score'] = max(0, min(100, $assessment['score'] + $contextual['adjustment'] + ($risk['adjustment'] ?? 0)));
                if ($risk !== null && $risk['adjustment'] < 0) {
                    $assessment['reasons'][] = sprintf('zero-click risk %s %d', $risk['level'], $risk['adjustment']);
                }
                if ($opportunity->type->value === 'ai_visibility' && str_starts_with($opportunity->subject, 'prompt:') && $assessment['score'] > self::SAMPLED_CONFIDENCE) {
                    $assessment['score'] = self::SAMPLED_CONFIDENCE;
                    $assessment['reasons'][] = 'sampled AI answers: capped at ' . self::SAMPLED_CONFIDENCE;
                }
                $topic = self::topicEvidence($opportunity, $topics);
                $sources = $assessment['sources'];
                if (!\SeoControlCenter\Domain\Opportunities\SignalCoverage::complete($signals->coverage, $sources)) {
                    continue;
                }
                $detected[] = new \SeoControlCenter\Domain\Opportunities\Opportunity($opportunity->type, $opportunity->subject, $opportunity->title, $opportunity->explanation, [...$opportunity->evidence, ...$work['evidence'], ...$contextual['evidence'], ...$valuation['evidence'], ...($risk['evidence'] ?? []), ...($decision['evidence'] ?? []), ...$topic, ['label' => 'Confidence', 'value' => $assessment['score'] . ': ' . implode('; ', $assessment['reasons'])], ['label' => 'Required sources', 'value' => implode(', ', $sources)], ['label' => 'Priority calculation', 'value' => $valuation['impact'] . ' impact × ' . $assessment['score'] . ' confidence / 100 / ' . $work['effort'] . ' effort = ' . \SeoControlCenter\Domain\Opportunities\Opportunity::priorityOf($valuation['impact'], $assessment['score'], $work['effort']) . '; prerequisites are presented before dependent work']], $valuation['impact'], $assessment['score'], $work['effort'], $opportunity->estimatedClicks, $opportunity->urlsTouched, $opportunity->rawEstimatedClicks);
            }
        }
        $result = $this->opportunities->sync($projectId, $detected, [], $signals->coverage);
        $this->logger->info('Opportunities analyzed', ['project_id' => $projectId, ...$result]);
        if ($this->clusters !== null) {
            try {
                $this->clusters->refresh($projectId, $workspaceId, $domain, $topics, self::demand($signals), $signals);
            } catch (\Throwable $e) {
                // Saved clusters are a by-product: a failure must not undo the analysis.
                $this->logger->error('Saving keyword clusters failed', ['project_id' => $projectId, 'exception' => $e]);
            }
        }

        return $result;
    }

    /**
     * Topics of the project's keywords: tracked keywords, search queries and
     * research keywords, grouped by shared results or shared core words.
     *
     * @return array<string, array{label: string, size: int, members: list<string>}>
     */
    private static function topics(\SeoControlCenter\Domain\Opportunities\ProjectSignals $signals): array
    {
        $demand = self::demand($signals);
        $serps = array_map(static fn(\SeoControlCenter\Domain\Opportunities\SerpLandscape $serp): array => $serp->urls(), $signals->serps);
        $clusters = (new \SeoControlCenter\Domain\Opportunities\KeywordClusterer())->cluster($demand, $serps);
        $members = [];
        foreach ($clusters as $keyword => $cluster) {
            $members[$cluster['label']][] = (string) $keyword;
        }
        $topics = [];
        $tracked = $signals->tracked();
        foreach ($clusters as $keyword => $cluster) {
            if ($cluster['size'] >= 2 || isset($tracked[(string) $keyword])) {
                $topics[(string) $keyword] = [...$cluster, 'members' => $members[$cluster['label']]];
            }
        }

        return $topics;
    }

    /**
     * Demand per normalized keyword (volume, or monthly impressions), highest first, bounded.
     *
     * @return array<string, float>
     */
    private static function demand(\SeoControlCenter\Domain\Opportunities\ProjectSignals $signals): array
    {
        $demand = [];
        foreach ($signals->keywords as $row) {
            $key = \SeoControlCenter\Domain\Keywords\Keyword::normalize($row->keyword);
            $demand[$key] = max($demand[$key] ?? 0.0, (float) ($row->searchVolume ?? 0));
        }
        foreach ($signals->queries as $row) {
            $key = \SeoControlCenter\Domain\Keywords\Keyword::normalize($row->key);
            $demand[$key] = max($demand[$key] ?? 0.0, $row->metrics->impressions * 30 / 28);
        }
        foreach ($signals->researchKeywords() as $row) {
            $key = \SeoControlCenter\Domain\Keywords\Keyword::normalize($row->keyword);
            $demand[$key] = max($demand[$key] ?? 0.0, (float) ($row->searchVolume ?? 0));
        }
        arsort($demand);
        // Tracked keywords always take part, even with little volume next to much research.
        $kept = array_slice($demand, 0, self::TOPIC_KEYWORDS, true);
        foreach (array_keys($signals->tracked()) as $keyword) {
            if (isset($demand[$keyword]) && !isset($kept[$keyword])) {
                $kept[$keyword] = $demand[$keyword];
            }
        }
        arsort($kept);

        return $kept;
    }

    /**
     * @param array<string, array{label: string, size: int, members: list<string>}> $topics
     * @return list<array{label: string, value: string}>
     */
    private static function topicEvidence(\SeoControlCenter\Domain\Opportunities\Opportunity $opportunity, array $topics): array
    {
        foreach ($opportunity->evidence as $item) {
            if (in_array($item['label'], ['Query', 'Keyword'], true)) {
                $topic = $topics[\SeoControlCenter\Domain\Keywords\Keyword::normalize($item['value'])] ?? null;
                if ($topic === null) {
                    return [];
                }
                $others = array_values(array_filter($topic['members'], static fn(string $m): bool => $m !== \SeoControlCenter\Domain\Keywords\Keyword::normalize($item['value'])));

                return [['label' => 'Topic cluster', 'value' => sprintf('“%s” (%d keywords): %s%s. One page can usually target the whole topic; grouped by shared top results or shared core words.', $topic['label'], $topic['size'], implode(', ', array_slice($others, 0, 6)), count($others) > 6 ? ', …' : '')]];
            }
        }

        return [];
    }
}
