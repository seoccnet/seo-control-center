<?php

declare(strict_types=1);

namespace SeoControlCenter\Domain\Opportunities;

use SeoControlCenter\Domain\Keywords\Keyword;

/**
 * Business potential of a query (0-3, the B of BID): how much a ranking
 * would contribute to the project's goal. A fixed, explainable policy from
 * the business profile, the query's intent and wording, and whether it
 * mentions what the business offers. A user rating for the keyword always
 * wins over the rules.
 *
 * 3 = directly drives the goal, 2 = supports it, 1 = weak or indirect,
 * 0 = little or no business value.
 */
final readonly class BusinessPotential
{
    /** Intent → score per goal: transactional, commercial, local, tool, informational in depth, quick answer, navigational, unknown. */
    private const array MATRIX = [
        'sales' => ['transactional' => 3, 'commercial' => 3, 'local' => 2, 'tool' => 1, 'informational_deep' => 1, 'informational_simple' => 1, 'navigational' => 1, 'unknown' => 1],
        'leads' => ['transactional' => 3, 'commercial' => 3, 'local' => 3, 'tool' => 1, 'informational_deep' => 1, 'informational_simple' => 1, 'navigational' => 1, 'unknown' => 1],
        'signups' => ['transactional' => 3, 'commercial' => 3, 'local' => 1, 'tool' => 2, 'informational_deep' => 2, 'informational_simple' => 1, 'navigational' => 1, 'unknown' => 1],
        'affiliate' => ['transactional' => 2, 'commercial' => 3, 'local' => 1, 'tool' => 1, 'informational_deep' => 2, 'informational_simple' => 1, 'navigational' => 1, 'unknown' => 1],
        'traffic' => ['transactional' => 1, 'commercial' => 2, 'local' => 1, 'tool' => 3, 'informational_deep' => 3, 'informational_simple' => 2, 'navigational' => 0, 'unknown' => 2],
        'awareness' => ['transactional' => 1, 'commercial' => 2, 'local' => 1, 'tool' => 3, 'informational_deep' => 2, 'informational_simple' => 1, 'navigational' => 0, 'unknown' => 2],
    ];

    /**
     * @param array<string, int> $ratings normalized keyword => user rating 0-3
     * @param list<string> $brandTerms the project's own brand words (normalized)
     */
    public function __construct(
        private BusinessContext $context,
        private array $ratings = [],
        private array $brandTerms = [],
    ) {}

    /**
     * @return array{score: int, source: string, intent: string, reasons: list<string>}
     */
    public function of(string $query, ?string $providerIntent = null): array
    {
        $query = Keyword::normalize($query);
        $intent = QueryPatterns::intent($query, $providerIntent);
        if (isset($this->ratings[$query])) {
            return ['score' => max(0, min(3, $this->ratings[$query])), 'source' => 'your rating', 'intent' => $intent, 'reasons' => ['set by your team for this keyword']];
        }
        foreach ($this->context->exclusions as $term) {
            if (QueryPatterns::mentions($query, $term)) {
                return ['score' => 0, 'source' => 'rules', 'intent' => $intent, 'reasons' => ['contains “' . $term . '”, a term your profile excludes']];
            }
        }
        $offering = null;
        foreach ($this->context->offerings as $term) {
            if (QueryPatterns::mentions($query, $term)) {
                $offering = $term;
                break;
            }
        }
        if (QueryPatterns::isCareer($query) && !array_any($this->context->offerings, QueryPatterns::isCareer(...))) {
            return ['score' => 0, 'source' => 'rules', 'intent' => $intent, 'reasons' => ['a job or salary search: searchers want work, not your offer']];
        }
        foreach ($this->brandTerms as $term) {
            if ($term !== '' && QueryPatterns::contains($query, $term)) {
                return ['score' => 2, 'source' => 'rules', 'intent' => 'navigational', 'reasons' => ['mentions your own brand “' . $term . '”: protect it, but searchers already know you']];
            }
        }
        $bare = false;
        if ($intent === 'unknown' && $offering !== null) {
            // A bare product or service name ("running shoes") is someone looking for that offer.
            $intent = 'commercial';
            $bare = true;
        }
        $goal = isset(self::MATRIX[$this->context->goal]) ? $this->context->goal : 'traffic';
        $score = self::MATRIX[$goal][$intent];
        $reasons = [sprintf('%s intent%s scores %d for the goal “%s”', ucfirst(QueryPatterns::intentLabel($intent)), $bare ? ' (a bare product or service search)' : '', $score, mb_strtolower(BusinessContext::GOALS[$goal]))];
        if ($this->context->model === 'local' && in_array($intent, ['local', 'transactional'], true) && $score < 3) {
            $score = 3;
            $reasons[] = 'a local business wins customers from local and booking searches: 3';
        }
        if ($offering !== null && $intent === 'informational_simple') {
            $reasons[] = 'mentions “' . $offering . '”, which you offer, but a quick answer rarely leads to the goal (+0)';
        } elseif ($offering !== null) {
            if ($score < 3) {
                ++$score;
                $reasons[] = '+1 mentions “' . $offering . '”, which you offer';
            } else {
                $reasons[] = 'mentions “' . $offering . '”, which you offer';
            }
        } elseif ($this->context->offerings !== []) {
            if ($score > 0) {
                --$score;
                $reasons[] = '−1 mentions none of your offerings, so the visitor may not need what you sell';
            }
        } else {
            $reasons[] = 'relevance unchecked: add your offerings to the business profile';
        }

        return ['score' => $score, 'source' => 'rules', 'intent' => $intent, 'reasons' => $reasons];
    }
}
