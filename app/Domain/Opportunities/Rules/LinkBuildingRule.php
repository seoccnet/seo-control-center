<?php

declare(strict_types=1);

namespace SeoControlCenter\Domain\Opportunities\Rules;

use SeoControlCenter\Domain\Backlinks\BacklinkProfile;
use SeoControlCenter\Domain\Backlinks\ReferringDomain;
use SeoControlCenter\Domain\Opportunities\Opportunity;
use SeoControlCenter\Domain\Opportunities\OpportunityRule;
use SeoControlCenter\Domain\Opportunities\OpportunityType;
use SeoControlCenter\Domain\Opportunities\ProjectSignals;

/**
 * Backlink opportunities from the stored backlink lookups:
 * - the authority gap: far fewer referring domains than the competitors;
 * - link prospects: strong domains that link to several competitors but not
 *   to the project (they link to the topic, so they are the likeliest to say yes);
 * - lost links from strong domains in the last 30 days, to win back;
 * - a high spam score of the project's own links.
 * Links have no click estimate, so impact comes from the domain rank (0-100).
 */
final class LinkBuildingRule implements OpportunityRule
{
    public const int PROSPECT_LIMIT = 15;
    public const int MIN_PROSPECT_RANK = 15;
    public const int MAX_PROSPECT_SPAM = 30;
    public const int MIN_LOST_RANK = 20;
    public const int SPAM_WARNING = 30;

    public function requires(?Opportunity $opportunity = null): array
    {
        if ($opportunity !== null) {
            return \SeoControlCenter\Domain\Opportunities\SignalRequirements::for($opportunity->type->value, $opportunity->subject);
        }
        return ['backlink_profiles', 'backlink_gap', 'backlink_lost'];
    }

    public function evaluate(ProjectSignals $signals): array
    {
        return [...$this->authority($signals), ...$this->prospects($signals), ...$this->lost($signals)];
    }

    /**
     * @return list<Opportunity>
     */
    private function authority(ProjectSignals $signals): array
    {
        $own = null;
        $others = [];
        foreach ($signals->backlinkProfiles as $p) {
            if ($p->domain === $signals->domain) {
                $own = $p;
            } elseif ($p->referringDomains !== null) {
                $others[] = $p;
            }
        }
        if ($own === null || $own->referringDomains === null) {
            return [];
        }
        $found = [];
        if ($others !== []) {
            $counts = array_map(static fn(BacklinkProfile $p): int => (int) $p->referringDomains, $others);
            sort($counts);
            $median = $counts[intdiv(count($counts), 2)];
            if ($median >= 20 && $own->referringDomains < $median / 2) {
                usort($others, static fn(BacklinkProfile $a, BacklinkProfile $b): int => $b->referringDomains <=> $a->referringDomains);
                $prospects = count(self::prospectList($signals));
                $found[] = new Opportunity(
                    OpportunityType::LinkBuilding,
                    'links:authority',
                    sprintf('Build referring domains: you have %s, your competitors a median of %s', Format::int($own->referringDomains), Format::int($median)),
                    'Links from other sites are one of the strongest ranking signals, and your competitors have far more of them. Earn links steadily: content others cite (data, guides, tools), digital PR, partners and suppliers, and the sites that already link to your competitors.'
                        . ($prospects > 0 ? sprintf(' Start with the %d link prospects listed as separate opportunities.', $prospects) : ' Run *Find link gap* under Backlinks to get a list of prospects.'),
                    [
                        ['label' => 'Your profile', 'value' => sprintf('rank %s, %s referring domains', $own->rank ?? '—', Format::int($own->referringDomains))],
                        ['label' => 'Competitors', 'value' => implode(', ', array_map(static fn(BacklinkProfile $p): string => $p->domain . ' ' . Format::int((int) $p->referringDomains), array_slice($others, 0, 5)))],
                        ['label' => 'Gap to the median', 'value' => Format::int($median - $own->referringDomains) . ' referring domains'],
                        ['label' => 'Backlink data', 'value' => (string) $signals->backlinksDate],
                    ],
                    (int) min(90, 50 + 20 * log10($median / max(1, $own->referringDomains))),
                    0,
                    Opportunity::EFFORT_HIGH,
                );
            }
        }
        if ($own->spamScore !== null && $own->spamScore >= self::SPAM_WARNING) {
            $found[] = new Opportunity(
                OpportunityType::LinkBuilding,
                'links:spam',
                sprintf('Review your backlinks: spam score %d', $own->spamScore),
                'Many of the links pointing to the site look low quality. Check the referring domains with the highest spam score; if they come from link schemes you did not ask for, Google usually ignores them, but paid or swapped links should be removed.',
                [
                    ['label' => 'Spam score', 'value' => $own->spamScore . ' / 100'],
                    ['label' => 'Referring domains', 'value' => Format::int($own->referringDomains)],
                ],
                40,
                0,
                Opportunity::EFFORT_MEDIUM,
            );
        }

        return $found;
    }

    /**
     * @return list<Opportunity>
     */
    private function prospects(ProjectSignals $signals): array
    {
        $found = [];
        foreach (self::prospectList($signals) as $d) {
            $targets = $d->targets ?? 1;
            $found[] = new Opportunity(
                OpportunityType::LinkBuilding,
                'link:' . $d->domain,
                $signals->competitorCount > 0
                    ? sprintf('Get a link from %s (links to %d of %d competitors)', $d->domain, $targets, $signals->competitorCount)
                    : sprintf('Get a link from %s', $d->domain),
                'This site links to your competitors but not to you, so it already covers your topic. Find the pages that link to them (a list, a review, a resource page) and offer something worth linking to: your product, data, a guide or an expert quote.',
                [
                    ['label' => 'Domain rank', 'value' => ($d->rank ?? '—') . ' / 100'],
                    ['label' => 'Links to competitors', 'value' => (string) $targets],
                    ['label' => 'Backlinks to them', 'value' => Format::int((int) $d->backlinks)],
                    ['label' => 'Spam score', 'value' => $d->spamScore !== null ? $d->spamScore . ' / 100' : 'unknown'],
                    ['label' => 'Backlink data', 'value' => (string) $signals->backlinksDate],
                ],
                (int) min(85, 15 + round(($d->rank ?? 0) * 0.6) + 8 * ($targets - 1)),
                0,
                Opportunity::EFFORT_MEDIUM,
            );
        }

        return $found;
    }

    /**
     * @return list<Opportunity>
     */
    private function lost(ProjectSignals $signals): array
    {
        $found = [];
        foreach ($signals->lostLinks as $d) {
            if (($d->rank ?? 0) < self::MIN_LOST_RANK) {
                continue;
            }
            $found[] = new Opportunity(
                OpportunityType::LinkBuilding,
                'lost:' . $d->domain,
                sprintf('Win back the link from %s (lost %s)', $d->domain, $d->lostDate ?? 'recently'),
                'A strong site stopped linking to you. Often the page moved, the link points to a URL that no longer works, or the article was updated. Check the page; if your URL changed, redirect it or ask for the link to be updated.',
                [
                    ['label' => 'Domain rank', 'value' => ($d->rank ?? '—') . ' / 100'],
                    ['label' => 'Lost on', 'value' => $d->lostDate ?? 'unknown'],
                    ['label' => 'Linking since', 'value' => $d->firstSeen ?? 'unknown'],
                ],
                (int) min(80, 15 + round(($d->rank ?? 0) * 0.6)),
                0,
                Opportunity::EFFORT_LOW,
            );
        }

        return $found;
    }

    /**
     * Strong, clean link gap domains that link to at least two competitors
     * (or the only one), most competitors and highest rank first.
     *
     * @return list<ReferringDomain>
     */
    private static function prospectList(ProjectSignals $signals): array
    {
        $needed = min(2, max(1, $signals->competitorCount));
        $prospects = array_values(array_filter($signals->linkGap, static fn(ReferringDomain $d): bool => ($d->rank ?? 0) >= self::MIN_PROSPECT_RANK
            && ($d->spamScore === null || $d->spamScore <= self::MAX_PROSPECT_SPAM)
            && ($d->targets ?? 1) >= $needed));
        usort($prospects, static fn(ReferringDomain $a, ReferringDomain $b): int => [$b->targets ?? 0, $b->rank ?? 0] <=> [$a->targets ?? 0, $a->rank ?? 0]);

        return array_slice($prospects, 0, self::PROSPECT_LIMIT);
    }
}
