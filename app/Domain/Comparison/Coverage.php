<?php

declare(strict_types=1);

namespace SeoControlCenter\Domain\Comparison;

/**
 * Subtopics the competing pages cover (ADR-038): their H2/H3 headings are
 * grouped by shared content words (Jaccard ≥ 0.5) and a subtopic counts
 * when at least two competing pages cover it. The project's page covers a
 * subtopic when one of its headings matches it the same way. Only pages of
 * the same type should be passed in; comparing an article's outline with a
 * product page says nothing.
 */
final class Coverage
{
    public const float MATCH = 0.5;
    public const int MIN_PAGES = 2;
    public const int MAX_TOPICS = 20;
    private const array STOP = ['the', 'and', 'for', 'with', 'your', 'you', 'our', 'are', 'how', 'what', 'why', 'when', 'where', 'which', 'who', 'can', 'does', 'this', 'that', 'from', 'about', 'into', 'more', 'most', 'all', 'any', 'its', 'their', 'there', 'than', 'then', 'will', 'should', 'have', 'has', 'not', 'best', 'top', 'guide', 'faq', 'faqs', 'questions', 'frequently', 'asked', 'introduction', 'conclusion', 'summary', 'overview', 'related', 'read', 'also', 'share', 'comments', 'leave', 'reply'];

    /**
     * @param list<PageStructure> $competitors readable pages of the same type
     * @param int $minPages competing pages that must cover a subtopic
     * @return list<array{topic: string, competitors: list<string>, own: bool}> most widely covered first
     */
    public static function compare(?PageStructure $own, array $competitors, int $minPages = self::MIN_PAGES): array
    {
        /** @var list<string> $topics */
        $topics = [];
        /** @var list<list<string>> $topicWords */
        $topicWords = [];
        /** @var list<list<string>> $topicPages */
        $topicPages = [];
        foreach ($competitors as $page) {
            foreach ($page->outline as $heading) {
                $words = self::words($heading['text']);
                if ($words === []) {
                    continue;
                }
                $match = null;
                foreach ($topicWords as $i => $known) {
                    if (self::jaccard($words, $known) >= self::MATCH) {
                        $match = $i;
                        break;
                    }
                }
                if ($match === null) {
                    $topics[] = $heading['text'];
                    $topicWords[] = $words;
                    $topicPages[] = [$page->url];
                } elseif (!in_array($page->url, $topicPages[$match], true)) {
                    $topicPages[$match][] = $page->url;
                }
            }
        }
        $ownHeadings = $own !== null && $own->readable() ? array_map(static fn(array $h): array => self::words($h['text']), $own->outline) : [];
        $result = [];
        foreach ($topics as $i => $topic) {
            if (count($topicPages[$i]) < max(1, $minPages)) {
                continue;
            }
            $covered = false;
            foreach ($ownHeadings as $words) {
                if ($words !== [] && self::jaccard($words, $topicWords[$i]) >= self::MATCH) {
                    $covered = true;
                    break;
                }
            }
            $result[] = ['topic' => $topic, 'competitors' => $topicPages[$i], 'own' => $covered];
        }
        usort($result, static fn(array $a, array $b): int => [count($b['competitors']), $a['own'], $a['topic']] <=> [count($a['competitors']), $b['own'], $b['topic']]);

        return array_slice($result, 0, self::MAX_TOPICS);
    }

    /** @return list<string> */
    public static function words(string $text): array
    {
        $words = [];
        foreach (preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($text), -1, PREG_SPLIT_NO_EMPTY) ?: [] as $word) {
            if (mb_strlen($word) < 3 || in_array($word, self::STOP, true) || ctype_digit($word)) {
                continue;
            }
            $words[preg_replace('/(?<=[a-z]{3})s$/u', '', $word) ?? $word] = true;
        }

        return array_map('strval', array_keys($words));
    }

    /**
     * @param list<string> $a
     * @param list<string> $b
     */
    private static function jaccard(array $a, array $b): float
    {
        $union = count(array_unique([...$a, ...$b]));

        return $union === 0 ? 0.0 : count(array_intersect($a, $b)) / $union;
    }
}
