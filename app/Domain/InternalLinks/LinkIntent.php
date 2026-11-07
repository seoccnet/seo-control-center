<?php

declare(strict_types=1);

namespace SeoControlCenter\Domain\InternalLinks;

/**
 * Checks that a paragraph and a link target are about the same kind of
 * thing before a link is suggested (ADR-042, 0.35.0). Matching words alone
 * are not enough: "free seeds" in a paragraph about earning reward points
 * must not link to a prize-draw page, and the other way round.
 */
final class LinkIntent
{
    /** Distinct page purposes that share everyday words; group => [label, signal words (stems, lower case)]. */
    private const array GROUPS = [
        'contest' => ['a prize draw or giveaway', ['giveaway', 'giveaways', 'win', 'winner', 'winners', 'contest', 'competition', 'raffle', 'draw', 'prize', 'prizes', 'sweepstake', 'sweepstakes', 'enter']],
        'reward' => ['rewards or loyalty points', ['reward', 'rewards', 'point', 'points', 'loyalty', 'earn', 'redeem', 'cashback', 'membership', 'tier', 'tiers']],
        'affiliate' => ['an affiliate or partner programme', ['affiliate', 'affiliates', 'commission', 'commissions', 'referral', 'referrals', 'partner', 'partners']],
    ];

    /**
     * Why the paragraph does not fit the target, or null when it may.
     * A conflict needs a clear purpose on both sides: the target names one
     * group, and the paragraph names another one but not the target's.
     */
    public static function conflict(string $target, string $paragraph): ?string
    {
        $targetGroups = self::groups($target);
        if ($targetGroups === []) {
            return null;
        }
        $paragraphGroups = self::groups($paragraph);
        if ($paragraphGroups === [] || array_intersect($targetGroups, $paragraphGroups) !== []) {
            return null;
        }

        return sprintf('the paragraph is about %s, the page about %s', self::GROUPS[$paragraphGroups[0]][0], self::GROUPS[$targetGroups[0]][0]);
    }

    /** @return list<string> */
    private static function groups(string $text): array
    {
        $words = array_flip(preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($text)) ?: []);
        $found = [];
        foreach (self::GROUPS as $group => [, $signals]) {
            foreach ($signals as $signal) {
                if (isset($words[$signal])) {
                    $found[] = $group;
                    break;
                }
            }
        }

        return $found;
    }
}
