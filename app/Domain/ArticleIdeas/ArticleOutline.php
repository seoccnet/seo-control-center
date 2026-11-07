<?php

declare(strict_types=1);

namespace SeoControlCenter\Domain\ArticleIdeas;

use SeoControlCenter\Domain\Comparison\Coverage;
use SeoControlCenter\Domain\Comparison\PageStructure;

/**
 * The competitor outlines of one article idea (ADR-041, G1 part 2): the
 * structure of its top competitor articles and, for an update, your page.
 * Headings only, never the text.
 */
final readonly class ArticleOutline
{
    public const string QUEUED = 'queued';
    public const string DONE = 'done';
    public const string FAILED = 'failed';
    /** A finished read is repeated at most once a week per idea. */
    public const int FRESH_DAYS = 7;

    /** @param list<PageStructure> $pages competitor articles, best first */
    public function __construct(
        public string $ideaKey,
        public string $status,
        public array $pages,
        public ?PageStructure $own,
        public ?string $error,
        public string $requestedAt,
        public ?string $fetchedAt,
    ) {}

    /** @return list<PageStructure> */
    public function readable(): array
    {
        return array_values(array_filter($this->pages, static fn(PageStructure $p): bool => $p->readable()));
    }

    /**
     * One outline per competitor site, best article first: headings that
     * repeat on two articles of the same site are its template (an embedded
     * policy, a "more info" block) and are dropped; the rest are merged.
     *
     * @return list<PageStructure>
     */
    public function sites(): array
    {
        $bySite = [];
        foreach ($this->readable() as $page) {
            $bySite[self::site($page->url)][] = $page;
        }
        $sites = [];
        foreach ($bySite as $pages) {
            $seen = [];
            foreach ($pages as $page) {
                foreach (array_unique(array_map(static fn(array $h): string => self::norm($h['text']), $page->outline)) as $text) {
                    $seen[$text] = ($seen[$text] ?? 0) + 1;
                }
            }
            $outline = [];
            $questions = [];
            $kept = [];
            foreach ($pages as $page) {
                foreach ($page->outline as $heading) {
                    $text = self::norm($heading['text']);
                    if ((count($pages) > 1 && $seen[$text] > 1) || isset($kept[$text])) {
                        continue;
                    }
                    $kept[$text] = true;
                    $outline[] = $heading;
                }
                foreach ($page->questions as $question) {
                    if (isset($kept[self::norm($question)]) && !in_array($question, $questions, true)) {
                        $questions[] = $question;
                    }
                }
            }
            $sites[] = new PageStructure($pages[0]->url, PageStructure::OK, outline: $outline, questions: $questions, wordCount: $pages[0]->wordCount);
        }

        return $sites;
    }

    /**
     * Subtopics the competitor sites cover, most widely covered first:
     * headings grouped by shared words, kept when two or more sites have
     * them; with a single readable site, its own headings.
     *
     * @return list<array{topic: string, competitors: list<string>, own: bool}>
     */
    public function subtopics(): array
    {
        $sites = $this->sites();

        return $sites === [] ? [] : Coverage::compare($this->own, $sites, count($sites) === 1 ? 1 : Coverage::MIN_PAGES);
    }

    /**
     * Questions the competitor articles answer in their headings, without
     * repeats. With $about (the idea's main search), only questions that
     * name one of its words or that two sites ask: an article that ranks for
     * the idea may be about something else, and its other questions are not.
     *
     * @return list<string>
     */
    public function questions(?string $about = null, int $limit = 10): array
    {
        $terms = $about === null ? [] : IdeaBuilder::words($about);
        $seen = [];
        $sites = [];
        foreach ($this->sites() as $page) {
            foreach (array_unique($page->questions) as $question) {
                $key = mb_strtolower(trim($question));
                $seen[$key] ??= trim($question);
                $sites[$key] = ($sites[$key] ?? 0) + 1;
            }
        }
        $kept = array_filter($seen, static fn(string $question, string $key): bool => $terms === [] || $sites[$key] >= 2 || array_intersect(IdeaBuilder::words($question), $terms) !== [], ARRAY_FILTER_USE_BOTH);

        return array_slice(array_values($kept), 0, $limit);
    }

    /** @return array<string, mixed> */
    public function present(?string $about = null): array
    {
        return [
            'status' => $this->status,
            'requested_at' => $this->requestedAt,
            'fetched_at' => $this->fetchedAt,
            'error' => $this->error,
            'subtopics' => $this->subtopics(),
            'questions' => $this->questions($about),
            'pages' => array_map(static fn(PageStructure $p): array => [
                'url' => $p->url,
                'state' => $p->state,
                'reason' => $p->reason,
                'words' => $p->wordCount,
                'headings' => array_map(static fn(array $h): array => ['level' => $h['level'], 'text' => $h['text']], array_slice($p->outline, 0, 40)),
            ], $this->pages),
            'your_page' => $this->own === null ? null : ['url' => $this->own->url, 'state' => $this->own->state, 'words' => $this->own->wordCount, 'headings' => count($this->own->outline)],
        ];
    }

    private static function site(string $url): string
    {
        return (string) preg_replace('/^www\./', '', strtolower((string) parse_url($url, PHP_URL_HOST)));
    }

    private static function norm(string $text): string
    {
        return mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', $text)));
    }

    /** Stored page structure: only what the outline needs. */
    public static function slim(PageStructure $page): PageStructure
    {
        return new PageStructure($page->url, $page->state, $page->reason, $page->title, null, $page->h1, $page->outline, $page->questions, wordCount: $page->wordCount);
    }
}
