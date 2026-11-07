<?php

declare(strict_types=1);

namespace SeoControlCenter\Domain\Research;

use SeoControlCenter\Domain\Keywords\Keyword;
use SeoControlCenter\Domain\Opportunities\QueryPatterns;

/**
 * Seed keywords and modifiers suggested by an AI model, cleaned to the
 * shape keyword research needs: seeds of one or two words, modifiers of up
 * to three words, and no overlap between the two lists (a modifier inside
 * a seed is removed from the seed, a modifier equal to a seed is dropped).
 * Suggestions carry no search volumes; the data comes only from the
 * keyword database afterwards.
 */
final readonly class SeedIdeas
{
    public const int MAX_SEEDS = 20;
    public const int MAX_MODIFIERS = 30;

    /**
     * @param list<string> $seeds
     * @param list<string> $modifiers
     */
    public function __construct(
        public array $seeds,
        public array $modifiers,
        public string $notes = '',
    ) {}

    /** @param array<mixed> $raw decoded model output */
    public static function clean(array $raw): self
    {
        $modifiers = [];
        foreach (is_array($raw['modifiers'] ?? null) ? $raw['modifiers'] : [] as $value) {
            $modifier = self::phrase($value, 3, 30);
            if ($modifier !== null) {
                $modifiers[$modifier] = true;
            }
        }
        $modifierWords = [];
        foreach (array_keys($modifiers) as $modifier) {
            if (count(QueryPatterns::tokens($modifier)) === 1) {
                $modifierWords[QueryPatterns::tokens($modifier)[0]] = true;
            }
        }
        $seeds = [];
        foreach (is_array($raw['seeds'] ?? null) ? $raw['seeds'] : [] as $value) {
            $seed = self::phrase($value, 4, 60);
            if ($seed === null) {
                continue;
            }
            // A one-word modifier inside a seed belongs to the modifiers: "best trail shoes" → "trail shoes".
            // A seed that is itself one word stays a seed; the duplicate modifier is dropped below.
            $words = explode(' ', $seed);
            if (count($words) > 1) {
                $words = array_values(array_filter($words, static fn(string $w): bool => !isset($modifierWords[QueryPatterns::stem($w)])));
            }
            $seed = implode(' ', $words);
            // A generic format word ("best", "free") is never a topic on its own.
            if ($seed !== '' && count($words) <= 2 && mb_strlen($seed) <= 40 && !in_array($seed, QueryPatterns::MODIFIERS, true)) {
                $seeds[$seed] = true;
            }
        }
        foreach (array_keys($modifiers) as $modifier) {
            foreach (array_keys($seeds) as $seed) {
                if (QueryPatterns::mentions($seed, $modifier)) {
                    unset($modifiers[$modifier]); // the seed already says it
                    break;
                }
            }
        }
        $notes = is_string($raw['notes'] ?? null) ? mb_substr(trim((string) preg_replace('/\s+/u', ' ', strip_tags($raw['notes']))), 0, 500) : '';

        return new self(array_slice(array_keys($seeds), 0, self::MAX_SEEDS), array_slice(array_keys($modifiers), 0, self::MAX_MODIFIERS), $notes);
    }

    /** @return array{seeds: list<string>, modifiers: list<string>, notes: string} */
    public function toArray(): array
    {
        return ['seeds' => $this->seeds, 'modifiers' => $this->modifiers, 'notes' => $this->notes];
    }

    private static function phrase(mixed $value, int $maxWords, int $maxLength): ?string
    {
        if (!is_string($value)) {
            return null;
        }
        $phrase = Keyword::normalize((string) preg_replace('/[^\p{L}\p{N}\s\'&+-]/u', ' ', $value));
        if ($phrase === '' || mb_strlen($phrase) > $maxLength || count(explode(' ', $phrase)) > $maxWords || preg_match('/\p{L}/u', $phrase) !== 1) {
            return null;
        }

        return $phrase;
    }
}
