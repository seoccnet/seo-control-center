<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\Research;

use SeoControlCenter\Core\Support\Clock;
use SeoControlCenter\Domain\Comparison\Analysis;
use SeoControlCenter\Domain\Comparison\AnalysisRepository;
use SeoControlCenter\Domain\Keywords\KeywordLocale;
use SeoControlCenter\Domain\Opportunities\SerpLandscapeRepository;
use SeoControlCenter\Domain\Projects\Project;
use SeoControlCenter\Domain\Rankings\RankingRepository;

/**
 * Research on file (ADR-038, D3): every paid lookup a project has stored,
 * with its inputs, market, date and whether it is still fresh, so nobody
 * buys the same data twice. It reads what is stored and starts nothing.
 */
final readonly class ResearchOnFile
{
    /** The paid lookups shown, in this order; seed ideas come from the AI assistant and are left out. */
    private const array KINDS = ['ranked', 'gap', 'ideas', 'suggestions', 'competitors', 'overview', 'serp_strength', 'llm', 'llm_answers', 'bl_profiles', 'bl_domains', 'bl_new', 'bl_lost', 'bl_anchors', 'bl_gap', 'bl_links', 'bl_lost_links'];

    public function __construct(
        private ResearchRepository $results,
        private SerpLandscapeRepository $serps,
        private RankingRepository $rankings,
        private Clock $clock,
        private ?AnalysisRepository $analyses = null,
    ) {}

    /**
     * @return array{lookups: list<array{kind: string, label: string, subject: ?string, market: ?string, inputs: ?string, status: string, requested_at: string, fetched_at: ?string, age_days: ?int, fresh: bool, rows: int}>, analyses: list<array{analysis_id: string, topic: string, keyword: string, results_page: string, finished_at: ?string, age_days: ?int, fresh: bool, ai: string}>, results_pages: array{count: int, newest: ?string, oldest: ?string}, fresh_days: int}
     */
    public function list(Project $project): array
    {
        $now = $this->clock->now();
        $age = static fn(?string $date): ?int => $date === null ? null : max(0, (int) (new \DateTimeImmutable(substr($date, 0, 10), new \DateTimeZone('UTC')))->diff(new \DateTimeImmutable($now->format('Y-m-d'), new \DateTimeZone('UTC')))->days);
        $lookups = [];
        foreach (self::KINDS as $kind) {
            foreach ($this->results->forKind($project->id, $kind) as $r) {
                $days = $age($r->fetchedAt);
                $lookups[] = [
                    'kind' => $kind,
                    'label' => self::label($kind),
                    'subject' => is_string($r->params['competitor'] ?? null) ? $r->params['competitor'] : null,
                    'market' => isset($r->params['location']) ? self::market($r->params) : null,
                    'inputs' => self::inputs($r->params),
                    'status' => $r->status,
                    'requested_at' => $r->requestedAt,
                    'fetched_at' => $r->fetchedAt,
                    'age_days' => $days,
                    'fresh' => $r->status === 'done' && $days !== null && $days <= RepeatedLookup::DAYS,
                    'rows' => count($r->items),
                ];
            }
        }
        usort($lookups, static fn(array $a, array $b): int => [$b['fetched_at'] ?? $b['requested_at'], $a['kind']] <=> [$a['fetched_at'] ?? $a['requested_at'], $b['kind']]);

        $analyses = [];
        foreach ($this->analyses?->latestDone($project->id, $now->modify('-365 days')->format('Y-m-d H:i:s')) ?? [] as $a) {
            $days = $age($a->finishedAt);
            $ai = is_array($a->verdict['ai'] ?? null) ? (string) ($a->verdict['ai']['status'] ?? '') : '';
            $analyses[] = [
                'analysis_id' => $a->publicId,
                'topic' => $a->label,
                'keyword' => $a->keyword,
                'results_page' => $a->serpSource === Analysis::PAID ? sprintf('looked up%s', $a->cost !== null ? sprintf(' ($%.3f)', $a->cost) : '') : 'from rank tracking',
                'finished_at' => $a->finishedAt,
                'age_days' => $days,
                'fresh' => $days !== null && $days <= \SeoControlCenter\Modules\Comparison\ComparisonService::FRESH_DAYS,
                'ai' => $ai === 'done' ? 'written' : ($ai === '' ? 'none' : $ai),
            ];
        }
        usort($analyses, static fn(array $a, array $b): int => [$b['finished_at'], $a['topic']] <=> [$a['finished_at'], $b['topic']]);

        $dates = array_map(static fn($s): string => $s->checkedOn, $this->serps->latest($project->id, $this->rankings->settings($project->id)->device));

        return [
            'lookups' => $lookups,
            'analyses' => $analyses,
            'results_pages' => ['count' => count($dates), 'newest' => $dates === [] ? null : max($dates), 'oldest' => $dates === [] ? null : min($dates)],
            'fresh_days' => RepeatedLookup::DAYS,
        ];
    }

    public static function label(string $kind): string
    {
        return match ($kind) {
            'ranked' => 'Your ranking keywords', 'gap' => 'Keyword gap', 'ideas' => 'Expanded seeds', 'suggestions' => 'Keyword ideas',
            'competitors' => 'Competitor discovery', 'overview' => 'Competitor overview', 'serp_strength' => 'Competitor strength',
            'llm' => 'AI mentions', 'llm_answers' => 'Sampled AI answers',
            'bl_profiles' => 'Backlink profiles', 'bl_domains' => 'Referring domains', 'bl_new' => 'New backlinks', 'bl_lost' => 'Lost backlinks',
            'bl_anchors' => 'Anchor texts', 'bl_gap' => 'Link gap', 'bl_links' => 'Backlinks', 'bl_lost_links' => 'Lost links',
            default => $kind,
        };
    }

    /** @param array<string, mixed> $params */
    private static function market(array $params): string
    {
        try {
            return KeywordLocale::fromInput($params['location'] ?? null, $params['language'] ?? null)->label();
        } catch (\SeoControlCenter\Domain\Shared\ValidationException) {
            return 'unknown market';
        }
    }

    /**
     * A short summary of what the lookup was asked for.
     *
     * @param array<string, mixed> $params
     */
    private static function inputs(array $params): ?string
    {
        $parts = [];
        foreach (['seeds' => 'seed', 'modifiers' => 'modifier', 'prompts' => 'prompt', 'domains' => 'domain', 'competitors' => 'competitor'] as $key => $noun) {
            if (is_array($params[$key] ?? null) && $params[$key] !== []) {
                $list = array_values(array_filter($params[$key], is_string(...)));
                $parts[] = count($list) . ' ' . $noun . (count($list) === 1 ? '' : 's') . ($key === 'seeds' ? ': ' . mb_strimwidth(implode(', ', $list), 0, 80, '…') : '');
            }
        }

        return $parts === [] ? null : implode(' · ', $parts);
    }
}
