<?php

declare(strict_types=1);

namespace SeoControlCenter\Domain\Comparison;

/**
 * A finished "Why they outrank you" analysis as investigation entries
 * (ADR-038, D3): the dated sources it read, the checks it made and the
 * limitations of its evidence, all from what was stored.
 */
final class AnalysisEvidence
{
    /**
     * @return list<array{kind: string, ref_type: ?string, ref_id: ?string, label: string, detail: ?string, observed_on: ?string}>
     */
    public static function items(Analysis $a): array
    {
        $day = $a->finishedAt !== null ? substr($a->finishedAt, 0, 10) : null;
        $item = static fn(string $kind, string $label, ?string $detail = null, ?string $refType = null, ?string $refId = null): array => ['kind' => $kind, 'ref_type' => $refType, 'ref_id' => $refId, 'label' => mb_substr($label, 0, 255), 'detail' => $detail !== null ? mb_substr($detail, 0, 2000) : null, 'observed_on' => $day];
        $items = [];
        $items[] = $item('source', sprintf('Why they outrank you: %s', $a->label), sprintf('Main search “%s”, %d searches in the topic.', $a->keyword, count($a->keywords)), 'analysis', $a->publicId);
        $items[] = $item('source', sprintf('Results page for “%s”', $a->keyword), $a->serpSource === Analysis::PAID ? sprintf('Looked up from DataForSEO%s; top %d results.', $a->cost !== null ? sprintf(' ($%.3f)', $a->cost) : '', count($a->results)) : sprintf('Stored from rank tracking; top %d results.', count($a->results)));
        foreach ($a->pages as $url => $page) {
            $own = $url === $a->ownUrl;
            $items[] = $item('source', ($own ? 'Your page: ' : 'Competing page: ') . $url, $page->readable() ? sprintf('Read: %s words, %d headings%s.', number_format($page->wordCount), count($page->outline), $page->schemaTypes !== [] ? ', schema ' . implode(', ', array_slice($page->schemaTypes, 0, 4)) : '') : sprintf('Not read (%s): %s', $page->state, (string) $page->reason), 'url', $url);
        }

        $format = is_array($a->verdict['format'] ?? null) ? $a->verdict['format'] : [];
        if ($format !== []) {
            $items[] = $item('check', sprintf('Results-page format: %s', (string) ($format['verdict'] ?? '')), (string) ($format['explanation'] ?? ''));
        }
        $coverage = is_array($a->verdict['coverage'] ?? null) ? $a->verdict['coverage'] : [];
        $missing = count(array_filter($coverage, static fn(mixed $c): bool => is_array($c) && ($c['own'] ?? true) === false));
        $items[] = $item('check', 'Subtopic coverage against same-type pages', sprintf('%d subtopics covered by at least two competing pages; %d missing on your page.', count($coverage), $missing));
        $items[] = $item('check', sprintf('Main bottleneck: %s', (string) $a->bottleneck()), implode(' ', array_filter(is_array($a->verdict['diagnosis']['reasons'] ?? null) ? $a->verdict['diagnosis']['reasons'] : [], is_string(...))));
        $links = is_array($a->verdict['links'] ?? null) ? $a->verdict['links'] : null;
        if ($links !== null) {
            $items[] = $item('check', 'Internal links from the site audit', count($a->links()) . ' recommended. ' . (is_string($links['note'] ?? null) ? $links['note'] : ''));
        }

        $readable = (int) ($a->verdict['readable'] ?? 0);
        $attempted = (int) ($a->verdict['attempted'] ?? 0);
        if ($attempted > $readable) {
            $items[] = $item('limitation', sprintf('%d of %d competing pages could not be read', $attempted - $readable, $attempted), 'Blocked by robots.txt, a bot challenge or an error; they count in the format but not in the content comparison.');
        }
        if (($a->verdict['diagnosis']['confidence'] ?? null) === 'low') {
            $items[] = $item('limitation', 'Low confidence in the verdict', 'Fewer than two readable competing pages, or no clear page type on the results page.');
        }
        if ($a->ownUrl === null) {
            $items[] = $item('limitation', 'No page of the site owns or ranks for this topic', 'The comparison has no own page to measure against.');
        }
        $ai = is_array($a->verdict['ai'] ?? null) ? $a->verdict['ai'] : null;
        if ($ai !== null && ($ai['status'] ?? null) !== 'done') {
            $items[] = $item('limitation', 'No AI explanation', is_string($ai['note'] ?? null) ? $ai['note'] : null);
        }
        $items[] = $item('limitation', 'Structure only', 'Only page structure was read (headings, schema, word count, media, dates); links, authority and the text itself were not compared.');

        return $items;
    }
}
