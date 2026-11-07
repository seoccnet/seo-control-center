<?php

declare(strict_types=1);

namespace SeoControlCenter\Domain\Opportunities;

use SeoControlCenter\Domain\Keywords\Keyword;

/**
 * Conservative English wording patterns of a search query: what kind of
 * answer the searcher expects. Explainable heuristics, not a classifier;
 * queries in other languages mostly fall through to "unknown".
 */
final class QueryPatterns
{
    /** Tool nouns that on their own mark a search for an interactive utility (stemmed). */
    public const array TOOL_NOUNS = ['calculator', 'calc', 'checker', 'generator', 'converter', 'estimator', 'analyzer', 'analyser', 'validator', 'simulator'];

    /**
     * Nouns that are also products or people ("coffee maker", "fitness
     * tracker", "wedding planner", "garden tools"): a utility only with a
     * digital cue in the query or a countable thing before "counter".
     */
    public const array WEAK_TOOL_NOUNS = ['counter', 'maker', 'finder', 'planner', 'tracker', 'builder', 'tester', 'quiz', 'tool'];
    private const array DIGITAL_CUES = ['online', 'free', 'app', 'ai', 'web', 'website', 'digital', 'pdf', 'excel', 'template'];
    private const array COUNTABLE = ['word', 'character', 'letter', 'calorie', 'macro', 'syllable', 'click', 'page', 'line', 'sentence', 'paragraph', 'view', 'follower', 'like', 'subscriber', 'day', 'step', 'carb'];

    /** Words that change the format or intent of a seed (used to split AI suggestions from seeds and to filter research). */
    public const array MODIFIERS = ['best', 'top', 'vs', 'versus', 'review', 'reviews', 'alternative', 'alternatives', 'cheap', 'price', 'pricing', 'cost', 'near me', 'how to', 'what is', 'guide', 'template', 'templates', 'examples', 'ideas', 'free', 'online', 'for beginners', 'under', 'buy', 'calculator', 'checker', 'generator', 'counter', 'converter', 'tool'];

    private const string DEFINITION = '/^(what|who)( is| are| was| were|\'s)\b|\b(meaning|definition|define|synonyms?|antonyms?|abbreviation|acronym|stands for|in a sentence)\b/u';
    private const string QUICK_FACT = '/\b(how (many|much|old|tall|long|far|big)|when (is|was|did|does|do)|what time|what year|what day|how to pronounce|pronunciation|population of|capital of|weather|time in|convert|conversion|exchange rate|translate)\b|\b\d+(\.\d+)? ?(usd|eur|gbp|kg|lbs?|cm|mm|inches|inch|feet|ft|miles?|km|celsius|fahrenheit|oz|ml|grams?) (to|in) /u';
    private const string SIMPLE_HOWTO = '/^(how (do|to|can|does|should) |can (i|you) |is it |does |do |why (is|does|do) )/u';
    private const string DEEP = '/\b(best|top \d+|\d+ best|vs\.?|versus|compare|comparison|review|reviews|alternatives?|templates?|examples?|ideas|guide|tutorial|step by step|checklist|case stud(y|ies)|pros and cons|worth it|strategy|strategies|plan|course|for beginners|in depth)\b/u';
    private const string COMMERCIAL = '/\b(best|top \d+|\d+ best|vs\.?|versus|compare|comparison|review|reviews|alternatives?|pricing|price|prices|cost|costs|cheap|cheapest|affordable|discount|deals?|worth it|software|app|apps|service|services|company|companies|agency|agencies|provider|providers|platform|vendor)\b/u';
    private const string TRANSACTIONAL = '/\b(buy|order|purchase|for sale|shop|coupon|promo code|subscribe|sign up|signup|free trial|download|book|booking|hire|quote|quotes|near me|delivery)\b/u';
    private const string LOCAL = '/\b(near me|nearby|open now|closest|in my area)\b/u';
    private const string NAVIGATIONAL = '/\b(login|log in|sign in|customer portal|account|contact number|phone number|customer service)\b/u';
    // "job" alone only at the end ("barista job"), so "job board software" stays a product search.
    private const string CAREER = '/\b(jobs|job openings?|job near me|salary|salaries|careers?|internships?|hiring|vacancy|vacancies|resume|interview questions)\b|\bjob$/u';

    private function __construct() {}

    /** True when the query asks for a definition or a single fact a results page can answer. */
    public static function isDefinition(string $query): bool
    {
        return preg_match(self::DEFINITION, Keyword::normalize($query)) === 1;
    }

    public static function isQuickFact(string $query): bool
    {
        return preg_match(self::QUICK_FACT, Keyword::normalize($query)) === 1;
    }

    /** A plain how-to or yes/no question without wording that asks for depth. */
    public static function isSimpleQuestion(string $query): bool
    {
        $query = Keyword::normalize($query);

        return preg_match(self::SIMPLE_HOWTO, $query) === 1 && !self::needsDepth($query);
    }

    /** Wording that asks for comparison, depth, examples or experience: a results-page answer rarely satisfies it. */
    public static function needsDepth(string $query): bool
    {
        return preg_match(self::DEEP, Keyword::normalize($query)) === 1;
    }

    public static function isCommercial(string $query): bool
    {
        return preg_match(self::COMMERCIAL, Keyword::normalize($query)) === 1;
    }

    public static function isTransactional(string $query): bool
    {
        return preg_match(self::TRANSACTIONAL, Keyword::normalize($query)) === 1;
    }

    public static function isLocal(string $query): bool
    {
        return preg_match(self::LOCAL, Keyword::normalize($query)) === 1;
    }

    public static function isNavigational(string $query): bool
    {
        return preg_match(self::NAVIGATIONAL, Keyword::normalize($query)) === 1;
    }

    /** Job and salary searches: they almost never turn into customers. */
    public static function isCareer(string $query): bool
    {
        return preg_match(self::CAREER, Keyword::normalize($query)) === 1;
    }

    /** The tool noun of a utility search ("mortgage calculator" → "calculator"), or null. */
    public static function toolNoun(string $query): ?string
    {
        $tokens = self::tokens($query);
        $digital = array_intersect($tokens, self::DIGITAL_CUES) !== [];
        foreach ($tokens as $i => $token) {
            if (in_array($token, self::TOOL_NOUNS, true)) {
                return $token === 'calc' ? 'calculator' : ($token === 'analyser' ? 'analyzer' : $token);
            }
            if (in_array($token, self::WEAK_TOOL_NOUNS, true) && ($digital || $token === 'counter' && $i > 0 && in_array($tokens[$i - 1], self::COUNTABLE, true))) {
                return $token;
            }
        }

        return null;
    }

    /**
     * The searcher's intent from the wording: transactional, commercial,
     * local, navigational, tool, informational (deep or simple) or unknown.
     * A provider intent (e.g. DataForSEO's main intent) wins where the
     * wording is ambiguous.
     */
    public static function intent(string $query, ?string $providerIntent = null): string
    {
        $query = Keyword::normalize($query);
        return match (true) {
            self::isLocal($query) => 'local',
            self::toolNoun($query) !== null => 'tool',
            self::isNavigational($query) => 'navigational',
            self::isTransactional($query) => 'transactional',
            self::isCommercial($query) => 'commercial',
            self::isDefinition($query) || self::isQuickFact($query) => 'informational_simple',
            self::needsDepth($query) => 'informational_deep',
            self::isSimpleQuestion($query) => 'informational_simple',
            in_array($providerIntent, ['transactional', 'commercial', 'navigational'], true) => (string) $providerIntent,
            $providerIntent === 'informational' || preg_match('/^(how|what|why|when|where|which|who|can|is|does|do)\b/u', $query) === 1 => 'informational_deep',
            default => 'unknown',
        };
    }

    public static function intentLabel(string $intent): string
    {
        return match ($intent) {
            'informational_simple' => 'informational (quick answer)',
            'informational_deep' => 'informational (in depth)',
            'tool' => 'tool / utility',
            default => $intent,
        };
    }

    /**
     * Words of a query in lowercase, with simple plurals reduced (shoes →
     * shoe, boxes → box) so variants of a phrase compare equal.
     *
     * @return list<string>
     */
    public static function tokens(string $query): array
    {
        $words = preg_split('/[^\p{L}\p{N}]+/u', Keyword::normalize($query), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        return array_values(array_map(self::stem(...), $words));
    }

    public static function stem(string $word): string
    {
        $length = mb_strlen($word);
        return match (true) {
            in_array($word, ['series', 'species', 'news', 'its', 'this', 'has', 'was', 'does'], true) => $word,
            $length > 4 && str_ends_with($word, 'ies') => mb_substr($word, 0, -3) . 'y',
            $length > 4 && preg_match('/(ss|x|ch|sh)es$/u', $word) === 1 => mb_substr($word, 0, -2),
            $length > 3 && str_ends_with($word, 's') && preg_match('/(ss|us|is|ews|ics|ous)$/u', $word) !== 1 => mb_substr($word, 0, -1),
            default => $word,
        };
    }

    /** True when every word of the phrase occurs in the query, in any order (plural-insensitive). */
    public static function mentions(string $query, string $phrase): bool
    {
        $needle = self::tokens($phrase);

        return $needle !== [] && array_diff($needle, self::tokens($query)) === [];
    }

    /** True when the phrase occurs in the query as whole words (plural-insensitive). */
    public static function contains(string $query, string $phrase): bool
    {
        $needle = self::tokens($phrase);
        $haystack = self::tokens($query);
        $n = count($needle);
        if ($n === 0 || $n > count($haystack)) {
            return false;
        }
        for ($i = 0; $i + $n <= count($haystack); ++$i) {
            if (array_slice($haystack, $i, $n) === $needle) {
                return true;
            }
        }

        return false;
    }
}
