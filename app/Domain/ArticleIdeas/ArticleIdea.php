<?php

declare(strict_types=1);

namespace SeoControlCenter\Domain\ArticleIdeas;

/**
 * One article to write or update (ADR-041, G1): a topic that competitors
 * cover with articles, the searches it can win, your coverage and honest
 * estimates of traffic, difficulty, backlinks and time to rank.
 *
 * $markets holds the evidence per market ("location:language") the idea was
 * found in. An idea found in several markets (the same main search in the
 * same language) adds their volumes and traffic up; the split per market is
 * always kept next to the total. Ideas built before markets were stored
 * have none: they belong to their run's market (see inMarket()).
 */
final readonly class ArticleIdea
{
    public const string NEW = 'new';
    public const string UPDATE = 'update';

    /**
     * @param list<array{keyword: string, volume: int, difficulty: ?int, volumes?: array<string, int>}> $keywords main search first; volumes = the split per market when found in several
     * @param list<array{domain: string, found: bool, url: string, position: ?int, traffic: float, keywords: int, market?: string}> $competitors their articles, most traffic first
     * @param array{url: string, position: ?float, impressions: int}|null $own your best page for these searches
     * @param array{traffic: array{low: int, expected: int, high: int}, difficulty: array{level: string, reasons: list<string>}, backlinks: array{low: int, high: int, basis: string}|null, months: array{low: int, high: int, basis: string}} $prediction
     * @param list<string> $reasons why this idea, in plain words
     * @param list<array{market: string, volume: int, searches: int, traffic: array{low: int, expected: int, high: int}, difficulty: string, competitors: int}> $markets evidence per market, most volume first
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
        public array $markets = [],
    ) {}

    /**
     * The idea's market keys, most volume first.
     *
     * @return list<string>
     */
    public function marketKeys(): array
    {
        return array_column($this->markets, 'market');
    }

    /** The idea's markets as one label ("United States · English, Canada · English"), or '' without markets. */
    public function marketLabel(): string
    {
        return implode(', ', array_map(self::label(...), $this->marketKeys()));
    }

    public static function label(string $market): string
    {
        return \SeoControlCenter\Domain\Keywords\KeywordLocale::fromKey($market)?->label() ?? $market;
    }

    /** The language of a market key ("2840:en" => "en"). */
    public static function language(string $market): string
    {
        return explode(':', $market, 2)[1] ?? '';
    }

    /**
     * This idea with $market as its one market when it has none (an idea
     * built before markets were stored), else unchanged.
     */
    public function inMarket(string $market): self
    {
        if ($this->markets !== []) {
            return $this;
        }

        return new self($this->key, $this->kind, $this->mainKeyword, $this->volume, $this->keywords, $this->competitors, $this->own, $this->prediction, $this->score, $this->reasons, [self::evidence($market, $this)]);
    }

    /**
     * The evidence of one market's idea.
     *
     * @return array{market: string, volume: int, searches: int, traffic: array{low: int, expected: int, high: int}, difficulty: string, competitors: int}
     */
    public static function evidence(string $market, self $idea): array
    {
        return [
            'market' => $market,
            'volume' => $idea->volume,
            'searches' => count($idea->keywords),
            'traffic' => $idea->prediction['traffic'],
            'difficulty' => $idea->prediction['difficulty']['level'],
            'competitors' => count($idea->competitors),
        ];
    }

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
            'market' => $this->marketLabel(),
            'markets' => array_map(static fn(array $m): array => [...$m, 'label' => self::label($m['market'])], $this->markets),
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
        $markets = [];
        foreach (is_array($data['markets'] ?? null) ? $data['markets'] : [] as $m) {
            if (is_array($m) && is_string($m['market'] ?? null)) {
                /** @var array{low: int, expected: int, high: int} $traffic */
                $traffic = is_array($m['traffic'] ?? null) ? $m['traffic'] : ['low' => 0, 'expected' => 0, 'high' => 0];
                $markets[] = ['market' => $m['market'], 'volume' => (int) ($m['volume'] ?? 0), 'searches' => (int) ($m['searches'] ?? 0), 'traffic' => $traffic, 'difficulty' => (string) ($m['difficulty'] ?? ''), 'competitors' => (int) ($m['competitors'] ?? 0)];
            }
        }

        return new self((string) ($data['idea_key'] ?? ''), (string) ($data['kind'] ?? self::NEW), (string) ($data['main_keyword'] ?? ''), (int) ($data['volume'] ?? 0), $keywords, $competitors, $own, $prediction, (float) ($data['score'] ?? 0), $reasons, $markets);
    }
}
