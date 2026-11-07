<?php

declare(strict_types=1);

namespace SeoControlCenter\Domain\ArticleIdeas;

/**
 * One article to write or update (ADR-041, G1): a topic that competitors
 * cover with articles, the searches it can win, your coverage and honest
 * estimates of traffic, difficulty, backlinks and time to rank.
 */
final readonly class ArticleIdea
{
    public const string NEW = 'new';
    public const string UPDATE = 'update';

    /**
     * @param list<array{keyword: string, volume: int, difficulty: ?int}> $keywords main search first
     * @param list<array{domain: string, found: bool, url: string, position: ?int, traffic: float, keywords: int}> $competitors their articles, most traffic first
     * @param array{url: string, position: ?float, impressions: int}|null $own your best page for these searches
     * @param array{traffic: array{low: int, expected: int, high: int}, difficulty: array{level: string, reasons: list<string>}, backlinks: array{low: int, high: int, basis: string}|null, months: array{low: int, high: int, basis: string}} $prediction
     * @param list<string> $reasons why this idea, in plain words
     */
    public function __construct(
        public string $key,
        public string $kind,
        public string $mainKeyword,
        public int $volume,
        public array $keywords,
        public array $competitors,
        public ?array $own,
        public array $prediction,
        public float $score,
        public array $reasons,
    ) {}

    /** @return array<string, mixed> */
    public function present(): array
    {
        return [
            'idea_key' => $this->key,
            'kind' => $this->kind,
            'main_keyword' => $this->mainKeyword,
            'volume' => $this->volume,
            'keywords' => $this->keywords,
            'competitors' => $this->competitors,
            'your_page' => $this->own,
            'prediction' => $this->prediction,
            'score' => $this->score,
            'reasons' => $this->reasons,
        ];
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        /** @var list<array{keyword: string, volume: int, difficulty: ?int}> $keywords */
        $keywords = is_array($data['keywords'] ?? null) ? $data['keywords'] : [];
        /** @var list<array{domain: string, found: bool, url: string, position: ?int, traffic: float, keywords: int}> $competitors */
        $competitors = is_array($data['competitors'] ?? null) ? $data['competitors'] : [];
        /** @var array{url: string, position: ?float, impressions: int}|null $own */
        $own = is_array($data['your_page'] ?? null) ? $data['your_page'] : null;
        /** @var array{traffic: array{low: int, expected: int, high: int}, difficulty: array{level: string, reasons: list<string>}, backlinks: array{low: int, high: int, basis: string}|null, months: array{low: int, high: int, basis: string}} $prediction */
        $prediction = is_array($data['prediction'] ?? null) ? $data['prediction'] : [];
        /** @var list<string> $reasons */
        $reasons = is_array($data['reasons'] ?? null) ? $data['reasons'] : [];

        return new self((string) ($data['idea_key'] ?? ''), (string) ($data['kind'] ?? self::NEW), (string) ($data['main_keyword'] ?? ''), (int) ($data['volume'] ?? 0), $keywords, $competitors, $own, $prediction, (float) ($data['score'] ?? 0), $reasons);
    }
}
