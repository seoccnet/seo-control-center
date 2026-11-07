<?php

declare(strict_types=1);

namespace SeoControlCenter\Domain\Local;

/**
 * What the imported Google reviews say about review handling (0.46.0):
 * how many get an owner reply, critical reviews left unanswered, how many
 * new reviews arrive per month, and how the review count compares with
 * the businesses in the local pack. The import is a sample of up to 100
 * of the newest reviews, so the figures describe recent reviews.
 */
final readonly class ReviewInsights
{
    /** Reviews needed before reply share and pace mean anything. */
    public const int MIN_REVIEWS = 5;
    /** Ratings at or below this count as critical. */
    public const float CRITICAL = 2.0;
    private const int PACE_DAYS = 180;

    /**
     * @param list<array{author: string, rating: ?float, published: ?string}> $unanswered critical reviews without a reply, newest first (at most 5)
     */
    public function __construct(
        public int $count,
        public ?float $average,
        public int $replied,
        public int $critical,
        public array $unanswered,
        public int $unansweredCritical,
        public ?float $perMonth,
        public ?int $ownVotes,
        public ?float $leaderVotes,
        public ?string $fetchedOn,
    ) {}

    /**
     * @param list<array<string, mixed>> $items imported reviews (id, author, text, rating, published, reply)
     * @param list<LocalResult> $leaders the other businesses in the local packs
     * @param ?string $fetchedOn the date of the import (Y-m-d); the pace counts the 180 days before it
     */
    public static function from(array $items, ?BusinessProfile $profile, array $leaders, ?string $fetchedOn): ?self
    {
        if ($items === []) {
            return null;
        }
        $ratings = [];
        $replied = 0;
        $critical = 0;
        $unanswered = [];
        $recent = 0;
        $since = $fetchedOn !== null ? (new \DateTimeImmutable($fetchedOn))->modify('-' . self::PACE_DAYS . ' days')->format('Y-m-d') : null;
        foreach ($items as $r) {
            $rating = is_float($r['rating'] ?? null) || is_int($r['rating'] ?? null) ? (float) $r['rating'] : null;
            $published = is_string($r['published'] ?? null) && preg_match('/^\d{4}-\d{2}-\d{2}/', $r['published']) === 1 ? substr($r['published'], 0, 10) : null;
            $hasReply = is_string($r['reply'] ?? null) && trim($r['reply']) !== '';
            if ($rating !== null) {
                $ratings[] = $rating;
            }
            $replied += $hasReply ? 1 : 0;
            if ($rating !== null && $rating <= self::CRITICAL) {
                $critical++;
                if (!$hasReply) {
                    $unanswered[] = ['author' => is_string($r['author'] ?? null) ? $r['author'] : '', 'rating' => $rating, 'published' => $published];
                }
            }
            if ($since !== null && $published !== null && $published >= $since && $published <= (string) $fetchedOn) {
                $recent++;
            }
        }
        usort($unanswered, static fn(array $a, array $b): int => (string) $b['published'] <=> (string) $a['published']);
        $unique = [];
        foreach ($leaders as $l) {
            if ($l->votes !== null) {
                $unique[$l->cid ?? mb_strtolower($l->title)] = $l->votes;
            }
        }

        return new self(
            count($items),
            $ratings !== [] ? round(array_sum($ratings) / count($ratings), 2) : null,
            $replied,
            $critical,
            array_slice($unanswered, 0, 5),
            count($unanswered),
            $since !== null ? round($recent / (self::PACE_DAYS / 30), 1) : null,
            $profile?->votes,
            $unique !== [] ? array_sum($unique) / count($unique) : null,
            $fetchedOn,
        );
    }

    public function replyShare(): float
    {
        return $this->count > 0 ? $this->replied / $this->count : 0.0;
    }

    /** Whether review handling needs work: critical reviews without an answer, or most reviews unanswered. */
    public function needsReplies(): bool
    {
        return $this->unansweredCritical > 0 || ($this->count >= self::MIN_REVIEWS && $this->replyShare() < 0.5);
    }

    /** Whether the business has far fewer reviews than the local pack (less than half). */
    public function behindLeaders(): bool
    {
        return $this->ownVotes !== null && $this->leaderVotes !== null && $this->ownVotes < $this->leaderVotes / 2;
    }
}
