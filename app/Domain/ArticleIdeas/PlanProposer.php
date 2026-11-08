<?php

declare(strict_types=1);

namespace SeoControlCenter\Domain\ArticleIdeas;

use SeoControlCenter\Domain\Keywords\Keyword;

/**
 * Proposes the members of a topic plan (ADR-041, G2) around a pillar idea.
 * You keep or drop each; this only suggests.
 *
 * - The subject is the pillar's rarest words: each word is counted over
 *   everything the plan looks at (all ideas' searches and your Search Console
 *   queries); the pillar's least common word, and any other pillar word at
 *   most twice as common, define it. "mold on pepper" → mold, "brandywine cultivar"
 *   → brandywine, "paste vs slicer plant" → paste, slicer. Relative, so it
 *   needs no threshold and works on any site (0.29.1; a share threshold let
 *   "pepper" through on a seed shop).
 * - Supporting articles: other ideas with a search on the subject, in a
 *   language of the pillar's markets (an idea of another language is a
 *   different site section, not a supporting article). Ideas without
 *   markets (built before markets were stored) always qualify.
 * - Searches to consider: your Search Console queries on the subject that no
 *   idea covers (not kept until you keep them).
 * - Your pages: pages that get impressions for queries on the subject.
 */
final class PlanProposer
{
    public const int MAX_IDEAS = 10;
    public const int MAX_SEARCHES = 10;
    public const int MAX_PAGES = 10;
    /** Other pillar words count when at most this many times as common as the rarest. */
    private const int RARE_FACTOR = 2;

    /**
     * @param list<ArticleIdea> $ideas the project's ideas, the pillar included
     * @param list<array{query: string, url: string, impressions: int}> $queries your Search Console queries per page
     * @return list<array{type: string, key: string, label: string, volume: int, kept: bool, url: ?string}>
     */
    public static function propose(ArticleIdea $pillar, array $ideas, array $queries): array
    {
        $corpus = array_column($queries, 'query');
        foreach ($ideas as $idea) {
            array_push($corpus, ...array_column($idea->keywords, 'keyword'));
        }
        $subject = self::subject($pillar->mainKeyword, $corpus);
        $about = static fn(string $text): bool => self::about($text, $subject);

        $members = [];
        $covered = array_flip(array_column($pillar->keywords, 'keyword'));
        $languages = array_map(ArticleIdea::language(...), $pillar->marketKeys());
        $supporting = array_values(array_filter($ideas, static function (ArticleIdea $idea) use ($pillar, $about, $languages): bool {
            if ($idea->key === $pillar->key) {
                return false;
            }
            if ($languages !== [] && $idea->markets !== [] && array_intersect($languages, array_map(ArticleIdea::language(...), $idea->marketKeys())) === []) {
                return false;
            }
            foreach ($idea->keywords as $keyword) {
                if ($about($keyword['keyword'])) {
                    return true;
                }
            }

            return false;
        }));
        usort($supporting, static fn(ArticleIdea $a, ArticleIdea $b): int => [$b->score, $a->mainKeyword] <=> [$a->score, $b->mainKeyword]);
        foreach (array_slice($supporting, 0, self::MAX_IDEAS) as $idea) {
            $members[] = ['type' => TopicPlan::IDEA, 'key' => $idea->key, 'label' => $idea->mainKeyword, 'volume' => $idea->volume, 'kept' => true, 'url' => null];
            $covered += array_flip(array_column($idea->keywords, 'keyword'));
        }

        $searches = [];
        $pages = [];
        foreach ($queries as $row) {
            $query = Keyword::normalize($row['query']);
            if ($query === '' || !$about($query)) {
                continue;
            }
            $pages[$row['url']] = ($pages[$row['url']] ?? 0) + $row['impressions'];
            if (!isset($covered[$query])) {
                $searches[$query] = ($searches[$query] ?? 0) + $row['impressions'];
            }
        }
        if ($pillar->own !== null) {
            $pages[$pillar->own['url']] = max($pages[$pillar->own['url']] ?? 0, $pillar->own['impressions']);
        }
        arsort($searches);
        foreach (array_slice($searches, 0, self::MAX_SEARCHES, true) as $query => $impressions) {
            $members[] = ['type' => TopicPlan::SEARCH, 'key' => (string) $query, 'label' => (string) $query, 'volume' => $impressions, 'kept' => false, 'url' => null];
        }
        arsort($pages);
        foreach (array_slice($pages, 0, self::MAX_PAGES, true) as $url => $impressions) {
            $members[] = ['type' => TopicPlan::PAGE, 'key' => (string) $url, 'label' => (string) (parse_url((string) $url, PHP_URL_PATH) ?: $url), 'volume' => $impressions, 'kept' => true, 'url' => (string) $url];
        }

        return $members;
    }

    /**
     * The words that define the pillar's subject: its rarest words over $corpus.
     *
     * @param list<string> $corpus every search and query the plan looks at
     * @return list<string>
     */
    public static function subject(string $pillar, array $corpus): array
    {
        $words = array_values(array_unique(IdeaBuilder::words($pillar)));
        if ($words === []) {
            return [];
        }
        $wanted = array_flip($words);
        $frequency = array_fill_keys($words, 0);
        foreach (array_unique(array_map(Keyword::normalize(...), [$pillar, ...$corpus])) as $text) {
            foreach (array_unique(IdeaBuilder::words($text)) as $word) {
                if (isset($wanted[$word])) {
                    ++$frequency[$word];
                }
            }
        }
        $rarest = max(1, min($frequency));

        return array_values(array_filter($words, static fn(string $w): bool => $frequency[$w] <= $rarest * self::RARE_FACTOR));
    }

    /** @param list<string> $subject */
    public static function about(string $text, array $subject): bool
    {
        return $subject !== [] && array_intersect($subject, IdeaBuilder::words($text)) !== [];
    }
}
