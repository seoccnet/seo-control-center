<?php

declare(strict_types=1);

namespace SeoControlCenter\Domain\Comparison;

use SeoControlCenter\Domain\Opportunities\SerpFormat;

/**
 * Why the competing pages rank above the project's page (ADR-038), from
 * fixed rules over the fetched structure; the format is decided before the
 * content. The main bottleneck is the first that applies of technical
 * (own page unreadable), format (wrong page type for a dominant format),
 * content (missing subtopics or much less depth than same-type pages) and
 * freshness (competitors updated recently, the page not in a year). Links
 * and authority are not measured here, so "unclear" says so. Actions never
 * turn a page into another type, cite only fetched competitor URLs and
 * are at most seven.
 */
final class Diagnosis
{
    public const int MAX_ACTIONS = 7;
    public const int MIN_GAPS = 3;
    public const float DEPTH_RATIO = 1.8;

    /**
     * @param array{verdict: string, types: list<string>, shares: array<string, float>, explanation: string} $format
     * @param array{type: string, confidence: string, signals: list<string>}|null $ownType
     * @param list<array{url: string, position: int, type: string, page: PageStructure}> $competitors fetched or attempted results
     * @param list<array{topic: string, competitors: list<string>, own: bool}> $coverage
     * @param list<string> $keywords the topic's searches, main search first
     * @return array{bottleneck: string, reasons: list<string>, confidence: string, actions: list<array{type: string, title: string, what: string, evidence: list<string>, effort: string, weight: float}>}
     */
    public static function diagnose(array $format, ?string $ownUrl, ?PageStructure $own, ?array $ownType, array $competitors, array $coverage, array $keywords, string $today): array
    {
        $readable = array_values(array_filter($competitors, static fn(array $c): bool => $c['page']->readable()));
        $sameType = $ownType === null ? [] : array_values(array_filter($readable, static fn(array $c): bool => $c['type'] === $ownType['type']));
        $confidence = count($readable) >= 3 && $format['verdict'] !== ResultsFormat::FRAGMENTED ? 'high' : (count($readable) >= 2 ? 'medium' : 'low');
        $reasons = [];
        $actions = [];
        $main = $keywords[0] ?? '';
        $bottleneck = null;

        if ($ownUrl === null) {
            $bottleneck = 'format';
            $type = $format['types'][0] ?? SerpFormat::ARTICLE;
            $reasons[] = 'No page of the site ranks or gets impressions for this topic.';
            $actions[] = self::action('create_page', sprintf('Create %s for “%s”', ResultsFormat::a($type), $main), sprintf('Google ranks %s for this topic. Create one that targets: %s.', ResultsFormat::label($type) . 's', implode(', ', array_slice($keywords, 0, 6))), self::urls($readable, $type), 'high', 1.0);
        } elseif ($own === null || !$own->readable()) {
            $bottleneck = 'technical';
            $reasons[] = sprintf('Your page %s could not be read: %s', $ownUrl, $own === null ? 'not fetched' : ($own->reason ?? 'not readable'));
            $actions[] = self::action('fix_technical', 'Make the page readable for crawlers', 'Check that the page answers with HTTP 200 and HTML, is not blocked by robots.txt and shows its content without a bot challenge.', [], 'medium', 0.5);
        }

        $ownTypeName = $ownType['type'] ?? null;
        if ($ownTypeName !== null && $format['verdict'] === ResultsFormat::DOMINANT && $ownTypeName !== $format['types'][0]) {
            $bottleneck ??= 'format';
            $want = $format['types'][0];
            $reasons[] = sprintf('%s Your page is a %s.', $format['explanation'], ResultsFormat::label($ownTypeName));
            $actions[] = self::action('create_page', sprintf('Give “%s” %s', $main, ResultsFormat::a($want)), sprintf('Do not rewrite your %s into another type. Create or improve %s for the buying searches (%s) and keep your page for the searches where its type ranks; link the two.', ResultsFormat::label($ownTypeName), ResultsFormat::a($want), implode(', ', array_slice($keywords, 0, 4))), self::urls($readable, $want), 'high', 1.0);
        } elseif ($ownTypeName !== null && $format['verdict'] === ResultsFormat::MIXED) {
            $other = $format['types'][0] === $ownTypeName ? ($format['types'][1] ?? null) : $format['types'][0];
            if ($other !== null && !in_array($ownTypeName, $format['types'], true)) {
                $bottleneck ??= 'format';
                $reasons[] = sprintf('%s Your page is a %s.', $format['explanation'], ResultsFormat::label($ownTypeName));
            }
            if ($other !== null && $other !== $ownTypeName) {
                $actions[] = self::action('page_pair', sprintf('Pair your page with %s', ResultsFormat::a($other)), sprintf('%s Keep one page per format: your %s for its searches and %s for the others, linked to each other, so they do not compete.', $format['explanation'], ResultsFormat::label($ownTypeName), ResultsFormat::a($other)), self::urls($readable, $other), 'high', 0.7);
            }
        }

        if ($own !== null && $own->readable()) {
            $gaps = array_values(array_filter($coverage, static fn(array $c): bool => !$c['own']));
            if (count($gaps) >= self::MIN_GAPS) {
                $bottleneck ??= 'content';
                $reasons[] = sprintf('%d subtopics that at least two same-type competitors cover are missing on your page.', count($gaps));
                $actions[] = self::action('add_section', 'Cover the missing subtopics', 'Add sections for: ' . implode('; ', array_map(static fn(array $g): string => '“' . $g['topic'] . '” (' . count($g['competitors']) . ' competitors)', array_slice($gaps, 0, 6))) . '.', array_values(array_unique(array_merge(...array_map(static fn(array $g): array => $g['competitors'], array_slice($gaps, 0, 6))))), 'medium', 0.8);
            }
            $words = array_map(static fn(array $c): int => $c['page']->wordCount, $sameType);
            if (count($words) >= 2 && self::median($words) > self::DEPTH_RATIO * max(1, $own->wordCount)) {
                $bottleneck ??= 'content';
                $reasons[] = sprintf('Same-type competitors have a median of %d words; your page has %d.', self::median($words), $own->wordCount);
                $actions[] = self::action('expand_section', 'Answer the topic in more depth', sprintf('Expand the existing sections with the details searchers need; the same-type pages ranking above you run about %d words, yours %d. Depth, not padding.', self::median($words), $own->wordCount), array_column($sameType, 'url'), 'medium', 0.5);
            }
            $schemaCount = [];
            foreach ($sameType as $c) {
                foreach ($c['page']->schemaTypes as $t) {
                    $schemaCount[$t][] = $c['url'];
                }
            }
            $missing = array_filter($schemaCount, static fn(array $urls, string $t): bool => count($urls) >= 2 && in_array($t, ['FAQPage', 'Product', 'Offer', 'AggregateRating', 'Review', 'HowTo', 'BreadcrumbList', 'ItemList'], true) && !in_array($t, $own->schemaTypes, true), ARRAY_FILTER_USE_BOTH);
            if ($missing !== []) {
                $actions[] = self::action('add_schema', 'Add the structured data the competitors use', 'Add ' . implode(', ', array_keys($missing)) . ' markup where it matches what the page really shows.', array_values(array_unique(array_merge(...array_values($missing)))), 'low', 0.3);
            }
            $media = array_map(static fn(array $c): int => $c['page']->images + $c['page']->videos, $sameType);
            if (count($media) >= 2 && self::median($media) >= 3 && $own->images + $own->videos === 0) {
                $actions[] = self::action('add_media', 'Add images or video', sprintf('Same-type competitors show a median of %d images or videos; your page shows none.', self::median($media)), array_column($sameType, 'url'), 'medium', 0.3);
            }
            $fresh = array_values(array_filter($sameType, static fn(array $c): bool => ($c['page']->modified ?? $c['page']->published) !== null && ($c['page']->modified ?? $c['page']->published) >= (new \DateTimeImmutable($today))->modify('-180 days')->format('Y-m-d')));
            $ownDate = $own->modified ?? $own->published;
            if (count($fresh) >= 2 && ($ownDate === null || $ownDate < (new \DateTimeImmutable($today))->modify('-365 days')->format('Y-m-d'))) {
                $bottleneck ??= 'freshness';
                $reasons[] = sprintf('%d same-type competitors were updated in the last six months; your page %s.', count($fresh), $ownDate === null ? 'shows no date' : 'was last dated ' . $ownDate);
                $actions[] = self::action('refresh', 'Update the page and show the date', 'Review facts, prices and recommendations, update what changed and show the update date.', array_column($fresh, 'url'), 'low', 0.4);
            }
            $mainWords = \SeoControlCenter\Domain\Comparison\Coverage::words($main);
            $titled = array_filter($readable, static fn(array $c): bool => $mainWords !== [] && array_diff($mainWords, Coverage::words((string) $c['page']->title)) === []);
            if (count($titled) >= 3 && $mainWords !== [] && array_diff($mainWords, Coverage::words((string) $own->title)) !== []) {
                $actions[] = self::action('title_meta', 'Name the search in the title', sprintf('%d of the competing pages name “%s” in their title; yours does not. Rewrite the title and description around it without stuffing.', count($titled), $main), array_column(array_values($titled), 'url'), 'low', 0.3);
            }
        }
        if ($bottleneck === null) {
            $reasons[] = 'Format, coverage, depth and freshness match the competing pages. The difference is likely links or domain authority, which this analysis does not measure; check Backlinks.';
        }
        usort($actions, static fn(array $a, array $b): int => $b['weight'] <=> $a['weight']);

        return ['bottleneck' => $bottleneck ?? 'unclear', 'reasons' => $reasons, 'confidence' => $confidence, 'actions' => array_slice($actions, 0, self::MAX_ACTIONS)];
    }

    /**
     * @param list<string> $evidence
     * @return array{type: string, title: string, what: string, evidence: list<string>, effort: string, weight: float}
     */
    private static function action(string $type, string $title, string $what, array $evidence, string $effort, float $weight): array
    {
        return ['type' => $type, 'title' => $title, 'what' => $what, 'evidence' => array_slice($evidence, 0, 5), 'effort' => $effort, 'weight' => $weight];
    }

    /**
     * @param list<array{url: string, position: int, type: string, page: PageStructure}> $results
     * @return list<string>
     */
    private static function urls(array $results, string $type): array
    {
        return array_values(array_column(array_filter($results, static fn(array $c): bool => $c['type'] === $type), 'url'));
    }

    /** @param list<int> $values */
    private static function median(array $values): int
    {
        sort($values);
        $n = count($values);

        return $n === 0 ? 0 : (int) round($n % 2 === 1 ? $values[intdiv($n, 2)] : ($values[$n / 2 - 1] + $values[$n / 2]) / 2);
    }
}
