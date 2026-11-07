<?php

declare(strict_types=1);

namespace SeoControlCenter\Domain\Opportunities\Rules;

use SeoControlCenter\Domain\Local\CategoryGap;
use SeoControlCenter\Domain\Local\LocalResult;
use SeoControlCenter\Domain\Local\ReviewInsights;
use SeoControlCenter\Domain\Opportunities\Opportunity;
use SeoControlCenter\Domain\Opportunities\OpportunityRule;
use SeoControlCenter\Domain\Opportunities\OpportunityType;
use SeoControlCenter\Domain\Opportunities\ProjectSignals;

/**
 * Local SEO from the latest Google Maps check and Business Profile:
 * - local keywords where the business is not in the local pack (top 3),
 *   with how the top 3 compare on rating and reviews;
 * - an unclaimed profile, fewer reviews or a lower rating than the
 *   businesses in the local pack, and an incomplete profile;
 * - (0.46.0) a website whose name, address or phone differs from the
 *   profile, critical or most reviews left unanswered, and a primary
 *   category that differs from the one most of the local pack uses.
 */
final class LocalSeoRule implements OpportunityRule
{
    public const int MIN_PHOTOS = 10;

    public function requires(?Opportunity $opportunity = null): array
    {
        if ($opportunity !== null) {
            return \SeoControlCenter\Domain\Opportunities\SignalRequirements::for($opportunity->type->value, $opportunity->subject);
        }
        return ['local_rankings', 'local_profile'];
    }

    public function evaluate(ProjectSignals $signals): array
    {
        if ($signals->localDate === null) {
            return [];
        }
        $leaders = [];
        $found = [];
        foreach ($signals->localRankings as $r) {
            $top = array_values(array_filter($r['top'], fn(LocalResult $t): bool => $t->position <= 3 && !$this->isOwn($t, $signals)));
            array_push($leaders, ...$top);
            if ($r['position'] !== null && $r['position'] <= 3) {
                continue;
            }
            $avgVotes = self::average(array_map(static fn(LocalResult $t): ?float => $t->votes !== null ? (float) $t->votes : null, $top));
            $avgRating = self::average(array_map(static fn(LocalResult $t): ?float => $t->rating, $top));
            $found[] = new Opportunity(
                OpportunityType::LocalSeo,
                'local:' . $r['keyword'],
                $r['position'] === null
                    ? sprintf('Get into Google Maps results for “%s” (not in the top 20)', $r['keyword'])
                    : sprintf('Get into the local pack for “%s” (now #%d)', $r['keyword'], $r['position']),
                'The top 3 Maps results also appear in Google\'s normal results (the local pack). Google ranks them by relevance, distance and prominence: use the search term in your business category and description where it fits, keep the profile complete and active, and collect reviews that mention what you offer.'
                    . ($avgVotes !== null && $signals->businessProfile?->votes !== null && $signals->businessProfile->votes < $avgVotes / 2 ? sprintf(' The businesses in the top 3 have about %s reviews on average; you have %s.', Format::int($avgVotes), Format::int($signals->businessProfile->votes)) : ''),
                [
                    ['label' => 'Your position', 'value' => $r['position'] !== null ? '#' . $r['position'] : 'not in the top 20'],
                    ['label' => 'Top 3', 'value' => $top !== [] ? implode(', ', array_map(static fn(LocalResult $t): string => $t->title . ($t->rating !== null ? ' ★' . $t->rating : '') . ($t->votes !== null ? ' (' . Format::int($t->votes) . ')' : ''), $top)) : '—'],
                    ['label' => 'Top 3 average', 'value' => $avgRating !== null ? sprintf('★%.1f, %s reviews', $avgRating, Format::int((float) $avgVotes)) : 'unknown'],
                    ['label' => 'Maps check', 'value' => $signals->localDate],
                ],
                $r['position'] === null ? 45 : (int) min(65, 40 + max(0, 21 - $r['position'])),
                0,
                Opportunity::EFFORT_MEDIUM,
            );
        }

        return [...$found, ...$this->profile($signals, $leaders), ...$this->consistency($signals, $leaders)];
    }

    /**
     * @param list<LocalResult> $leaders businesses in the local packs
     * @return list<Opportunity>
     */
    private function consistency(ProjectSignals $signals, array $leaders): array
    {
        $p = $signals->businessProfile;
        if ($p === null) {
            return [];
        }
        $found = [];
        $nap = $signals->napCheck;
        if ($nap !== null && $nap->isKnown() && $nap->problems() !== []) {
            $labels = ['markup' => 'LocalBusiness markup', 'name' => 'name', 'phone' => 'phone', 'address' => 'address', 'website' => 'website link'];
            $fields = implode(', ', array_map(static fn(string $f): string => $labels[$f] ?? $f, $nap->problems()));
            $site = $nap->site;
            $found[] = new Opportunity(
                OpportunityType::LocalSeo,
                'gbp:nap',
                'Make your website match your Business Profile: ' . $fields,
                'Google compares the business details on your website with the profile; details that match everywhere make the business easier to trust and to rank. Show the exact name, address and phone number from the profile on your site, as text and in LocalBusiness structured data.',
                [
                    ['label' => 'Differs or missing', 'value' => $fields],
                    ['label' => 'Profile', 'value' => implode(' · ', array_filter([$p->title, $p->address, $p->phone]))],
                    ['label' => 'Website', 'value' => $site !== null ? (implode(' · ', array_filter([$site['name'], $site['address'], $site['phones'] !== [] ? implode(', ', $site['phones']) : null])) ?: 'no business details found') : '—'],
                    ['label' => 'Page', 'value' => $nap->pageUrl ?? '—'],
                    ['label' => 'Site audit', 'value' => $signals->siteAuditDate ?? '—'],
                ],
                50,
                0,
                Opportunity::EFFORT_LOW,
            );
        }
        $reviews = $signals->localReviews !== null ? ReviewInsights::from($signals->localReviews['items'], $p, $leaders, $signals->localReviews['fetched']) : null;
        if ($reviews !== null && $reviews->needsReplies()) {
            $found[] = new Opportunity(
                OpportunityType::LocalSeo,
                'gbp:replies',
                $reviews->unansweredCritical > 0
                    ? sprintf('Answer %s critical Google %s', Format::int($reviews->unansweredCritical), $reviews->unansweredCritical === 1 ? 'review' : 'reviews')
                    : sprintf('Answer your Google reviews: %d%% have a reply', (int) round($reviews->replyShare() * 100)),
                'Searchers read the replies as much as the reviews: an answered complaint shows a business that cares, and Google recommends replying to reviews. Reply to the critical ones first, then thank the others.',
                [
                    ['label' => 'Owner replies', 'value' => sprintf('%s of %s', Format::int($reviews->replied), Format::int($reviews->count))],
                    ['label' => 'Critical without a reply', 'value' => Format::int($reviews->unansweredCritical)],
                    ['label' => 'Answer first', 'value' => $reviews->unanswered !== [] ? implode(', ', array_map(static fn(array $r): string => ($r['author'] !== '' ? $r['author'] : 'Reviewer') . ' (' . (int) $r['rating'] . '★' . ($r['published'] !== null ? ', ' . $r['published'] : '') . ')', array_slice($reviews->unanswered, 0, 3))) : '—'],
                    ['label' => 'Reviews imported', 'value' => (string) $reviews->fetchedOn],
                ],
                $reviews->unansweredCritical > 0 ? 55 : 40,
                0,
                Opportunity::EFFORT_LOW,
            );
        }
        if ($signals->localDate !== null) {
            $gap = CategoryGap::from($p, array_map(static fn(array $r): array => $r['top'], $signals->localRankings), fn(LocalResult $r): bool => $this->isOwn($r, $signals));
            $leading = $gap->leading();
            if ($gap->hasGap() && $leading !== null) {
                $found[] = new Opportunity(
                    OpportunityType::LocalSeo,
                    'gbp:category',
                    sprintf('Consider “%s” as your Business Profile category', $leading),
                    sprintf('The primary category is one of Google\'s strongest local relevance signals. %d of the %d local pack places for your keywords are %s; your profile\'s primary category is %s. If %s describes your main business, make it the primary category or add it as a secondary one.', $gap->categories[$leading], $gap->total, $leading, (string) $gap->own, $leading),
                    [
                        ['label' => 'Leading category', 'value' => $leading],
                        ['label' => 'Your category', 'value' => (string) $gap->own],
                        ['label' => 'Local pack categories', 'value' => implode(', ', array_map(static fn(string $c, int $n): string => $c . ' (' . $n . ')', array_keys(array_slice($gap->categories, 0, 4, true)), array_slice($gap->categories, 0, 4, true)))],
                        ['label' => 'Maps check', 'value' => $signals->localDate],
                    ],
                    55,
                    0,
                    Opportunity::EFFORT_LOW,
                );
            }
        }

        return $found;
    }

    /**
     * @param list<LocalResult> $leaders businesses in the local packs
     * @return list<Opportunity>
     */
    private function profile(ProjectSignals $signals, array $leaders): array
    {
        $p = $signals->businessProfile;
        if ($p === null) {
            return [];
        }
        $found = [];
        if ($p->claimed === false) {
            $found[] = new Opportunity(
                OpportunityType::LocalSeo,
                'gbp:claim',
                'Claim your Google Business Profile',
                'Your profile is not claimed, so you cannot answer reviews, add photos, posts and opening hours, or correct details. Claim it at business.google.com; it is free and the single most effective local SEO step.',
                [['label' => 'Profile', 'value' => $p->title . ($p->address !== null ? ', ' . $p->address : '')]],
                75,
                0,
                Opportunity::EFFORT_LOW,
            );
        }
        $unique = [];
        foreach ($leaders as $l) {
            $unique[$l->cid ?? $l->title] = $l;
        }
        $avgVotes = self::average(array_map(static fn(LocalResult $t): ?float => $t->votes !== null ? (float) $t->votes : null, $unique));
        $avgRating = self::average(array_map(static fn(LocalResult $t): ?float => $t->rating, $unique));
        $fewer = $avgVotes !== null && $p->votes !== null && $p->votes < $avgVotes / 2;
        $lower = $avgRating !== null && $p->rating !== null && $p->rating < $avgRating - 0.25;
        if ($fewer || $lower) {
            $found[] = new Opportunity(
                OpportunityType::LocalSeo,
                'gbp:reviews',
                $fewer ? sprintf('Collect more Google reviews: you have %s, the local pack about %s', Format::int($p->votes ?? 0), Format::int($avgVotes ?? 0)) : sprintf('Improve your rating: ★%.1f against ★%.1f in the local pack', $p->rating, $avgRating),
                'The number and rating of reviews are a strong local ranking factor and decide which business people click. Ask every happy customer for a review (a short link or QR code at checkout and in follow-up e-mails) and answer every review, especially the critical ones.',
                [
                    ['label' => 'Your profile', 'value' => ($p->rating !== null ? sprintf('★%.1f', $p->rating) : 'no rating') . ', ' . Format::int($p->votes ?? 0) . ' reviews'],
                    ['label' => 'Local pack average', 'value' => ($avgRating !== null ? sprintf('★%.1f', $avgRating) : '—') . ', ' . Format::int($avgVotes ?? 0) . ' reviews'],
                    ['label' => 'Businesses compared', 'value' => (string) count($unique)],
                ],
                60,
                0,
                Opportunity::EFFORT_MEDIUM,
            );
        }
        $missing = array_keys(array_filter([
            'photos (' . ($p->photos ?? 0) . ')' => ($p->photos ?? 0) < self::MIN_PHOTOS,
            'phone number' => $p->phone === null || $p->phone === '',
            'website' => $p->url === null || $p->url === '',
            'category' => $p->category === null || $p->category === '',
        ]));
        if ($missing !== []) {
            $found[] = new Opportunity(
                OpportunityType::LocalSeo,
                'gbp:complete',
                'Complete your Google Business Profile',
                'Complete profiles rank better and get more calls and visits. Add at least 10 current photos (outside, inside, products, team), your phone number, your website and the most specific category.',
                [['label' => 'Missing or weak', 'value' => implode(', ', $missing)]],
                40,
                0,
                Opportunity::EFFORT_LOW,
            );
        }

        return $found;
    }

    private function isOwn(LocalResult $r, ProjectSignals $signals): bool
    {
        $domain = $signals->domain;

        return ($signals->businessProfile?->cid !== null && $r->cid === $signals->businessProfile->cid)
            || ($domain !== null && $r->domain !== null && ($r->domain === $domain || str_ends_with($r->domain, '.' . $domain)))
            || ($signals->businessName !== null && mb_strtolower(trim($r->title)) === mb_strtolower(trim($signals->businessName)));
    }

    /**
     * @param array<array-key, ?float> $values
     */
    private static function average(array $values): ?float
    {
        $values = array_filter($values, static fn(?float $v): bool => $v !== null);

        return $values === [] ? null : array_sum($values) / count($values);
    }
}
