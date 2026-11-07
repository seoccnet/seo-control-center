<?php

declare(strict_types=1);

namespace SeoControlCenter\Domain\Opportunities;

use SeoControlCenter\Domain\Keywords\Keyword;

/**
 * BID: Business potential, Intent (format) fit and Difficulty (rankability)
 * of a query finding, each 0-3, with a Target / Consider / Reconsider
 * verdict. An explicit policy on stored data:
 *
 * - B from BusinessPotential (business profile, wording, user ratings);
 * - I compares the format of the project's page with the format that
 *   dominates the stored results page (product, category, review, …);
 * - D adds one point each for keyword difficulty within the project's
 *   limit, a weak result in the top 10 (a domain at or below the strength
 *   limit, or a forum thread), and an existing page-one ranking.
 *
 * Only B changes scores: it replaces the generic intent weight in the
 * value model. I and D are evidence for the decision. Unknown parts stay
 * unknown and never count as good.
 */
final class BidModel
{
    /** B → impact multiplier: a user's rating is trusted more than the rules. */
    public const array WEIGHTS = ['rules' => [0 => 0.4, 1 => 0.75, 2 => 1.0, 3 => 1.2], 'your rating' => [0 => 0.25, 1 => 0.6, 2 => 1.0, 3 => 1.3]];

    private ?BusinessPotential $potential = null;
    /** @var array<string, string> */
    private array $providerIntents = [];
    /** @var array<string, int> */
    private array $positions = [];

    public function __construct(private readonly ProjectSignals $signals)
    {
        if ($signals->business !== null) {
            $this->potential = new BusinessPotential($signals->business, $signals->keywordRatings, $signals->brandTerms());
        }
        foreach ($signals->researchKeywords() as $row) {
            if ($row->intent !== null) {
                $this->providerIntents[Keyword::normalize($row->keyword)] ??= $row->intent;
            }
        }
        foreach ($signals->keywords as $row) {
            if ($row->position !== null) {
                $this->positions[Keyword::normalize($row->keyword)] = $row->position;
            }
        }
        foreach ($signals->queries as $row) {
            $key = Keyword::normalize($row->key);
            if (!isset($this->positions[$key]) && $row->metrics->position > 0) {
                $this->positions[$key] = (int) round($row->metrics->position);
            }
        }
    }

    /**
     * @return array{business: ?array{score: int, source: string, weight: float, label: string}, verdict: ?string, evidence: list<array{label: string, value: string}>}|null null without a query
     */
    public function assess(Opportunity $opportunity): ?array
    {
        $query = $url = null;
        foreach ($opportunity->evidence as $item) {
            if ($query === null && in_array($item['label'], ['Query', 'Keyword'], true)) {
                $query = Keyword::normalize($item['value']);
            }
            if ($url === null && in_array($item['label'], ['Page', 'Ranking URL', 'URL'], true) && $item['value'] !== '—') {
                $url = explode(' (', $item['value'], 2)[0];
            }
        }
        if ($query === null || $query === '') {
            return null;
        }
        $evidence = [];
        $business = null;
        $b = null;
        if ($this->potential !== null) {
            $potential = $this->potential->of($query, $this->providerIntents[$query] ?? null);
            $b = $potential['score'];
            $weight = self::WEIGHTS[$potential['source']][$b];
            $business = ['score' => $b, 'source' => $potential['source'], 'weight' => $weight, 'label' => sprintf('%d/3 (%s) ×%.2f', $b, $potential['source'], $weight)];
            $evidence[] = ['label' => 'Business potential', 'value' => sprintf('%d/3 (%s): %s.', $b, $potential['source'], implode('; ', $potential['reasons']))];
        } else {
            $evidence[] = ['label' => 'Business potential', 'value' => 'Unknown: describe the business under Opportunities → Business profile to score how much this search is worth to you.'];
        }
        [$i, $fit] = $this->formatFit($query, $url);
        $evidence[] = ['label' => 'Format fit', 'value' => ($i !== null ? $i . '/3: ' : 'Unknown: ') . $fit];
        $serp = $this->signals->serps[$query] ?? null;
        $dominant = $serp?->dominantFormat();
        if ($dominant !== null) {
            $evidence[] = ['label' => 'Recommended format', 'value' => SerpFormat::label($dominant) . ': ' . SerpFormat::action($dominant)];
        }
        [$d, $rankability, $tooHard] = $this->rankability($query, $serp);
        $evidence[] = ['label' => 'Rankability', 'value' => $d . '/3: ' . $rankability];
        $verdict = null;
        if ($b !== null) {
            [$verdict, $why] = match (true) {
                $b === 0 => ['Reconsider', 'little business value for your goal'],
                $tooHard => ['Reconsider', 'harder than your difficulty limit and no weak result in the top 10 to displace'],
                $b >= 2 && $d >= 2 && ($i === null || $i >= 2) => ['Target', 'valuable, winnable and the format fits'],
                $b >= 2 && $i !== null && $i < 2 => ['Consider', 'valuable, but your page format does not match what ranks'],
                $b >= 2 => ['Consider', 'valuable, but harder to win'],
                default => ['Consider', 'winnable, but of limited business value'],
            };
            $evidence[] = ['label' => 'BID verdict', 'value' => sprintf('%s: %s. B %d/3 · I %s · D %d/3 (estimate from stored data; difficulty and strength scores predict rankings only roughly).', $verdict, $why, $b, $i !== null ? $i . '/3' : 'unknown', $d)];
        }

        return ['business' => $business, 'verdict' => $verdict, 'evidence' => $evidence];
    }

    /** @return array{0: ?int, 1: string} */
    private function formatFit(string $query, ?string $url): array
    {
        $serp = $this->signals->serps[$query] ?? null;
        if ($serp === null) {
            return [null, 'no stored results page for this search; track the keyword to compare formats'];
        }
        $own = $serp->resultOf($this->signals->domain);
        $ownUrl = $own['url'] ?? $url;
        $dominant = $serp->dominantFormat();
        $counts = $serp->describeFormats();
        if ($dominant === null) {
            return [2, 'mixed results page (' . $counts . '); several formats can rank'];
        }
        if ($ownUrl === null) {
            return [2, sprintf('you have no ranking page yet; the results page favours %s (%s)', SerpFormat::plural($dominant), $counts)];
        }
        $format = SerpFormat::classify($ownUrl, $own['title'] ?? null);
        if ($format === SerpFormat::OTHER) {
            return [2, sprintf('your page %s could not be classified; the results page favours %s (%s)', $ownUrl, SerpFormat::plural($dominant), $counts)];
        }
        if (SerpFormat::compatible($format, $dominant)) {
            return [3, sprintf('your page is %s, like most results (%s)', SerpFormat::withArticle($format), $counts)];
        }

        return [1, sprintf('Google favours %s here (%s); your page %s is %s', SerpFormat::plural($dominant), $counts, $ownUrl, SerpFormat::withArticle($format))];
    }

    /** @return array{0: int, 1: string, 2: bool} score, explanation, harder than the limit with nothing weak to displace */
    private function rankability(string $query, ?SerpLandscape $serp): array
    {
        $limit = $this->signals->business?->difficultyLimit() ?? BusinessContext::DEFAULT_MAX_DIFFICULTY;
        $strengthLimit = $this->signals->business?->strengthLimit() ?? BusinessContext::DEFAULT_MAX_STRENGTH;
        $score = 0;
        $parts = [];
        $difficulty = $this->signals->difficultyOf($query);
        if ($difficulty === null) {
            $parts[] = 'difficulty unknown (+0)';
        } elseif ($difficulty <= $limit) {
            ++$score;
            $parts[] = sprintf('difficulty %d within your limit %d (+1)', $difficulty, $limit);
        } else {
            $parts[] = sprintf('difficulty %d above your limit %d (+0)', $difficulty, $limit);
        }
        $weak = false;
        if ($serp !== null) {
            $weakest = $this->signals->domainStrengths !== [] ? $serp->weakestCompetitor($this->signals->domainStrengths, $this->signals->domain) : null;
            $forums = $serp->forumResults();
            if ($weakest !== null && $weakest['strength'] <= $strengthLimit) {
                $weak = true;
                $parts[] = sprintf('weakest result %s (#%d) has strength %d, within your limit %d (+1)', $weakest['domain'], $weakest['position'], $weakest['strength'], $strengthLimit);
            } elseif ($forums > 0) {
                $weak = true;
                $parts[] = sprintf('%d forum or Q&A result%s in the top results (+1)', $forums, $forums === 1 ? '' : 's');
            } elseif ($weakest !== null) {
                $parts[] = sprintf('weakest result %s has strength %d, above your limit %d (+0)', $weakest['domain'], $weakest['strength'], $strengthLimit);
            } else {
                $parts[] = 'competitor strength not checked (+0)';
            }
            if ($weak) {
                ++$score;
            }
        } else {
            $parts[] = 'results page not stored, weak results unknown (+0)';
        }
        $position = $this->positions[$query] ?? null;
        if ($position !== null && $position <= 10) {
            ++$score;
            $parts[] = sprintf('you already rank at position %d (+1)', $position);
        } else {
            $parts[] = $position !== null ? sprintf('you rank at position %d (+0)', $position) : 'you do not rank yet (+0)';
        }
        $tooHard = $difficulty !== null && $difficulty > $limit && !$weak && ($position === null || $position > 10);

        return [$score, implode('; ', $parts), $tooHard];
    }
}
