<?php

declare(strict_types=1);

namespace SeoControlCenter\Domain\Opportunities\Rules;

use SeoControlCenter\Domain\Opportunities\Opportunity;
use SeoControlCenter\Domain\Opportunities\OpportunityRule;
use SeoControlCenter\Domain\Opportunities\OpportunityType;
use SeoControlCenter\Domain\Opportunities\ProjectSignals;
use SeoControlCenter\Domain\Rankings\Visibility;
use SeoControlCenter\Domain\Research\AiMention;

/**
 * Visibility in AI answers:
 * - tracked keywords whose Google AI Overview does not cite the site
 *   although it ranks on page one (Google cites from the top results, so
 *   these are the closest to a citation);
 * - AI assistants (ChatGPT, Google AI) mentioning a competitor far more often.
 */
final class AiVisibilityRule implements OpportunityRule
{
    public const int OVERVIEW_LIMIT = 20;
    public const int MIN_COMPETITOR_MENTIONS = 10;
    private const array PLATFORMS = ['chat_gpt' => 'ChatGPT', 'google' => 'Google AI Overviews and AI Mode'];

    public function requires(?Opportunity $opportunity = null): array
    {
        if ($opportunity !== null) {
            return \SeoControlCenter\Domain\Opportunities\SignalRequirements::for($opportunity->type->value, $opportunity->subject);
        }
        return ['ai_overviews', 'ai_mentions'];
    }

    public function evaluate(ProjectSignals $signals): array
    {
        return [...$this->overviews($signals), ...$this->mentions($signals)];
    }

    /**
     * @return list<Opportunity>
     */
    private function overviews(ProjectSignals $signals): array
    {
        $found = [];
        foreach ($signals->aiOverviews as $o) {
            if ($o['cited'] || $o['position'] === null || $o['position'] > 10) {
                continue;
            }
            $volume = $signals->volumeOf($o['keyword']);
            // AI Overviews take clicks from the results below them; a citation wins part of them back.
            $potential = ($volume ?? 0) * $signals->expectedCtr($o['position']) * 0.5;
            $found[] = [$potential, new Opportunity(
                OpportunityType::AiVisibility,
                'aio:' . $o['keyword'],
                sprintf('Get cited in the AI Overview for “%s”', $o['keyword']),
                sprintf(
                    'Google shows an AI Overview for this search and cites %s, but not you, although you rank at position %d. Give a short, direct answer to the question near the top of the ranking page, with clear headings, facts and figures, so Google can quote it.',
                    $o['domains'] !== [] ? implode(', ', array_slice($o['domains'], 0, 3)) : 'other sites',
                    $o['position'],
                ),
                [
                    ['label' => 'Keyword', 'value' => $o['keyword']],
                    ['label' => 'CTR model', 'value' => $signals->ctrBasis($o['position'])],
                    ['label' => 'Your position', 'value' => (string) $o['position']],
                    ['label' => 'Cited instead', 'value' => $o['domains'] !== [] ? implode(', ', array_slice($o['domains'], 0, 6)) : '—'],
                    ['label' => 'Search volume', 'value' => $volume !== null ? Format::int($volume) . ' / month' : 'unknown'],
                    ['label' => 'Rank check', 'value' => (string) $signals->aiOverviewDate],
                ],
                max(20, Opportunity::impactFromClicks($potential)),
                0,
                Opportunity::EFFORT_MEDIUM,
            )];
        }
        usort($found, static fn(array $a, array $b): int => $b[0] <=> $a[0]);

        return array_column(array_slice($found, 0, self::OVERVIEW_LIMIT), 1);
    }

    /**
     * @return list<Opportunity>
     */
    private function mentions(ProjectSignals $signals): array
    {
        $found = [];
        foreach (self::PLATFORMS as $platform => $label) {
            $own = 0;
            $leader = null;
            foreach ($signals->aiMentions as $m) {
                if ($m->platform !== $platform) {
                    continue;
                }
                if ($m->domain === $signals->domain) {
                    $own = $m->mentions;
                } elseif ($leader === null || $m->mentions > $leader->mentions) {
                    $leader = $m;
                }
            }
            if (!$leader instanceof AiMention || $leader->mentions < self::MIN_COMPETITOR_MENTIONS || $own * 3 > $leader->mentions) {
                continue;
            }
            $found[] = new Opportunity(
                OpportunityType::AiVisibility,
                'llm:' . $platform,
                sprintf('%s mentions %s %s times, you %s', $label, $leader->domain, Format::int($leader->mentions), Format::int($own)),
                'AI assistants recommend the brands that the web talks about. Get mentioned where they learn from: comparison and "best of" articles, reviews, forums such as Reddit, industry directories and the press. On your own site, state clearly what you offer, for whom and why, with facts AI can quote. See AI visibility → AI assistants for the questions that mention you.',
                [
                    ['label' => 'Your mentions', 'value' => Format::int($own)],
                    ['label' => 'Most mentioned competitor', 'value' => $leader->domain . ': ' . Format::int($leader->mentions)],
                    ['label' => 'AI search volume', 'value' => $leader->aiSearchVolume !== null ? Format::int($leader->aiSearchVolume) : 'unknown'],
                ],
                (int) min(75, 35 + 10 * log10(1 + $leader->mentions - $own)),
                0,
                Opportunity::EFFORT_HIGH,
            );
        }

        return $found;
    }
}
