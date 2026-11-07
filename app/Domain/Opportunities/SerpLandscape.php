<?php

declare(strict_types=1);

namespace SeoControlCenter\Domain\Opportunities;

/**
 * The latest stored results page of a tracked keyword: the top organic
 * results with their titles, the SERP feature types and whether Google
 * showed an AI Overview. Kept per keyword and device, overwritten by each
 * rank check (no history).
 */
final readonly class SerpLandscape
{
    /**
     * @param list<array{position: int, domain: string, url: string, title: ?string}> $results ordered by position
     * @param list<string> $features distinct non-organic element types
     */
    public function __construct(
        public string $keyword,
        public string $checkedOn,
        public array $results,
        public array $features,
        public bool $aiOverview,
    ) {}

    /**
     * Format of each result, in result order.
     *
     * @return list<string>
     */
    public function formats(): array
    {
        return array_map(static fn(array $r): string => SerpFormat::classify($r['url'], $r['title']), $this->results);
    }

    /** @return array<string, int> format => results, most frequent first, unclassified left out */
    public function formatCounts(): array
    {
        $counts = [];
        foreach ($this->formats() as $format) {
            if ($format !== SerpFormat::OTHER) {
                $counts[$format] = ($counts[$format] ?? 0) + 1;
            }
        }
        arsort($counts);

        return $counts;
    }

    /**
     * The format most results share, or null when no format holds at
     * least half of the classified results and three results ("mixed").
     */
    public function dominantFormat(): ?string
    {
        $counts = $this->formatCounts();
        $classified = array_sum($counts);
        $top = array_key_first($counts);
        if ($top === null || $counts[$top] < SerpFormat::DOMINANT_MIN || $counts[$top] / $classified < SerpFormat::DOMINANT_SHARE) {
            return null;
        }

        return $top;
    }

    public function describeFormats(): string
    {
        $counts = $this->formatCounts();
        if ($counts === []) {
            return 'no result could be classified';
        }

        return implode(', ', array_map(static fn(string $f, int $n): string => $n . ' × ' . SerpFormat::label($f), array_keys($counts), $counts)) . sprintf(' (of %d results)', count($this->results));
    }

    /**
     * The first result of a domain (or its subdomains), or null.
     *
     * @return array{position: int, domain: string, url: string, title: ?string}|null
     */
    public function resultOf(?string $domain): ?array
    {
        if ($domain === null || $domain === '') {
            return null;
        }
        foreach ($this->results as $result) {
            $host = (string) preg_replace('/^www\./', '', strtolower($result['domain']));
            if ($host === $domain || str_ends_with($host, '.' . $domain)) {
                return $result;
            }
        }

        return null;
    }

    /** Results from forums and Q&A sites: user posts ranking is a classic sign of unmet demand. */
    public function forumResults(): int
    {
        return count(array_filter($this->formats(), static fn(string $f): bool => $f === SerpFormat::FORUM));
    }

    /**
     * The weakest other domain in the results by stored domain strength
     * (0-100), ignoring the project's own domain. Null when no strength is
     * known for any of them.
     *
     * @param array<string, int> $strengths bare domain => strength
     * @return array{domain: string, strength: int, position: int, known: int}|null
     */
    public function weakestCompetitor(array $strengths, ?string $ownDomain): ?array
    {
        $weakest = null;
        $known = 0;
        foreach ($this->results as $result) {
            $host = (string) preg_replace('/^www\./', '', strtolower($result['domain']));
            if ($ownDomain !== null && ($host === $ownDomain || str_ends_with($host, '.' . $ownDomain))) {
                continue;
            }
            $strength = $strengths[$host] ?? null;
            if ($strength === null) {
                continue;
            }
            ++$known;
            if ($weakest === null || $strength < $weakest['strength']) {
                $weakest = ['domain' => $host, 'strength' => $strength, 'position' => $result['position'], 'known' => 0];
            }
        }

        return $weakest === null ? null : [...$weakest, 'known' => $known];
    }

    /** @return list<string> distinct bare domains of the results */
    public function domains(): array
    {
        return array_values(array_unique(array_map(static fn(array $r): string => (string) preg_replace('/^www\./', '', strtolower($r['domain'])), $this->results)));
    }

    /** @return list<string> URLs of the results, for overlap comparisons */
    public function urls(): array
    {
        return array_map(static fn(array $r): string => rtrim((string) preg_replace('#^https?://(www\.)?#', '', strtolower($r['url'])), '/'), $this->results);
    }
}
