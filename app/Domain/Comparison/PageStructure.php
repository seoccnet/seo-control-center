<?php

declare(strict_types=1);

namespace SeoControlCenter\Domain\Comparison;

/**
 * The structure of one fetched page (ADR-038): headings, schema, counts and
 * dates, never its text. Built by OutlineExtractor; stored as an array.
 */
final readonly class PageStructure
{
    public const string OK = 'ok';
    public const string BLOCKED = 'blocked';
    public const string FAILED = 'failed';
    public const string SKIPPED = 'skipped';

    /**
     * @param list<array{level: int, text: string}> $outline H2 and H3 headings in order, at most 60
     * @param list<string> $questions headings that ask a question, at most 20
     * @param list<string> $schemaTypes
     * @param array<string, bool> $elements price, cart, variants, grid, byline, toc, form, map
     */
    public function __construct(
        public string $url,
        public string $state,
        public ?string $reason = null,
        public ?string $title = null,
        public ?string $description = null,
        public ?string $h1 = null,
        public array $outline = [],
        public array $questions = [],
        public array $schemaTypes = [],
        public int $wordCount = 0,
        public int $images = 0,
        public int $videos = 0,
        public int $tables = 0,
        public int $lists = 0,
        public int $internalLinks = 0,
        public int $outboundLinks = 0,
        public ?string $published = null,
        public ?string $modified = null,
        public array $elements = [],
        public ?string $hash = null,
    ) {}

    public static function unread(string $url, string $state, string $reason): self
    {
        return new self($url, $state, mb_substr($reason, 0, 200));
    }

    public function readable(): bool
    {
        return $this->state === self::OK;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return get_object_vars($this);
    }

    /** @param array<string, mixed> $row */
    public static function fromArray(array $row): self
    {
        $strings = static fn(mixed $v): array => is_array($v) ? array_values(array_filter($v, is_string(...))) : [];
        $outline = [];
        foreach (is_array($row['outline'] ?? null) ? $row['outline'] : [] as $h) {
            if (is_array($h) && is_int($h['level'] ?? null) && is_string($h['text'] ?? null)) {
                $outline[] = ['level' => $h['level'], 'text' => $h['text']];
            }
        }
        $elements = [];
        foreach (is_array($row['elements'] ?? null) ? $row['elements'] : [] as $key => $value) {
            if (is_string($key) && is_bool($value)) {
                $elements[$key] = $value;
            }
        }
        $int = static fn(string $k): int => is_int($row[$k] ?? null) ? $row[$k] : 0;
        $str = static fn(string $k): ?string => is_string($row[$k] ?? null) ? $row[$k] : null;

        return new self((string) ($row['url'] ?? ''), (string) ($row['state'] ?? self::FAILED), $str('reason'), $str('title'), $str('description'), $str('h1'), $outline, $strings($row['questions'] ?? null), $strings($row['schemaTypes'] ?? null), $int('wordCount'), $int('images'), $int('videos'), $int('tables'), $int('lists'), $int('internalLinks'), $int('outboundLinks'), $str('published'), $str('modified'), $elements, $str('hash'));
    }
}
