<?php

declare(strict_types=1);

namespace SeoControlCenter\Domain\Opportunities;

use SeoControlCenter\Domain\Keywords\Keyword;
use SeoControlCenter\Domain\Shared\ValidationException;

/**
 * What the project's business sells and wants from search, set by the
 * user: the input of the business-potential score (BID). Also carries the
 * project's own targeting thresholds for difficulty and competitor
 * strength.
 */
final readonly class BusinessContext
{
    public const array MODELS = [
        'ecommerce' => 'Online shop (sells products)',
        'leads' => 'Services (wins leads or enquiries)',
        'local' => 'Local business (visits, calls, bookings)',
        'saas' => 'Software or app (sign-ups, trials)',
        'affiliate' => 'Affiliate site (earns commissions)',
        'publisher' => 'Publisher (ads or sponsorships)',
    ];
    public const array GOALS = [
        'sales' => 'Sales',
        'leads' => 'Leads or enquiries',
        'signups' => 'Sign-ups or trials',
        'affiliate' => 'Affiliate clicks',
        'traffic' => 'Traffic (ad or sponsor revenue)',
        'awareness' => 'Brand awareness and links',
    ];
    public const int DEFAULT_MAX_DIFFICULTY = 30;
    public const int DEFAULT_MAX_STRENGTH = 20;
    /** Offerings. */
    public const int MAX_TERMS = 30;
    public const int MAX_EXCLUSIONS = 100;
    public const int MAX_MARKETS = 20;

    /**
     * @param list<string> $offerings products, services or topics the business sells or covers
     * @param list<string> $exclusions terms of searches that never fit (e.g. jobs, free, diy)
     * @param list<string> $markets keyword locale keys ("2840:en") the business sells in; empty = not set
     */
    public function __construct(
        public string $model,
        public string $goal,
        public string $description = '',
        public string $audience = '',
        public array $offerings = [],
        public array $exclusions = [],
        public ?int $maxDifficulty = null,
        public ?int $maxStrength = null,
        public ?string $updatedAt = null,
        public array $markets = [],
    ) {}

    /**
     * @param array<string, mixed> $input form fields
     * @throws ValidationException
     */
    public static function fromInput(array $input): self
    {
        $errors = [];
        $model = is_string($input['model'] ?? null) ? $input['model'] : '';
        $goal = is_string($input['goal'] ?? null) ? $input['goal'] : '';
        if (!isset(self::MODELS[$model])) {
            $errors['model'] = 'Choose what kind of business this is.';
        }
        if (!isset(self::GOALS[$goal])) {
            $errors['goal'] = 'Choose the main goal of search traffic.';
        }
        $description = trim((string) preg_replace('/\s+/u', ' ', is_string($input['description'] ?? null) ? $input['description'] : ''));
        $audience = trim((string) preg_replace('/\s+/u', ' ', is_string($input['audience'] ?? null) ? $input['audience'] : ''));
        if (mb_strlen($description) > 1000) {
            $errors['description'] = 'Keep the description under 1,000 characters.';
        }
        if (mb_strlen($audience) > 300) {
            $errors['audience'] = 'Keep the audience under 300 characters.';
        }
        $offerings = self::terms($input['offerings'] ?? '');
        $exclusions = self::terms($input['exclusions'] ?? '');
        if (count($offerings) > self::MAX_TERMS) {
            $errors['offerings'] = sprintf('Enter at most %d terms.', self::MAX_TERMS);
        }
        if (count($exclusions) > self::MAX_EXCLUSIONS) {
            $errors['exclusions'] = sprintf('Enter at most %d terms.', self::MAX_EXCLUSIONS);
        }
        foreach ([...$offerings, ...$exclusions] as $term) {
            if (mb_strlen($term) > 60) {
                $errors[in_array($term, $offerings, true) ? 'offerings' : 'exclusions'] = 'Each term can be at most 60 characters.';
            }
        }
        $limits = [];
        foreach (['max_difficulty' => 'maxDifficulty', 'max_strength' => 'maxStrength'] as $field => $name) {
            $raw = $input[$field] ?? null;
            $raw = is_string($raw) ? trim($raw) : $raw;
            if ($raw === null || $raw === '') {
                $limits[$name] = null;
            } elseif (is_string($raw) && ctype_digit($raw) && (int) $raw <= 100 || is_int($raw) && $raw >= 0 && $raw <= 100) {
                $limits[$name] = (int) $raw;
            } else {
                $errors[$field] = 'Enter a whole number from 0 to 100, or leave it empty for the default.';
            }
        }
        $markets = $input['markets'] ?? [];
        if (is_array($input['market_location'] ?? null) && is_array($input['market_language'] ?? null)) {
            // The form sends one country and one language per row; empty rows are skipped.
            $markets = [];
            foreach ($input['market_location'] as $i => $location) {
                $language = $input['market_language'][$i] ?? '';
                if (is_string($location) && is_string($language) && $location !== '' && $language !== '') {
                    $markets[] = $location . ':' . $language;
                }
            }
        }
        $markets = self::markets($markets);
        if (count($markets) > self::MAX_MARKETS) {
            $errors['markets'] = sprintf('Choose at most %d markets.', self::MAX_MARKETS);
        }
        if ($errors !== []) {
            throw new ValidationException($errors);
        }

        return new self($model, $goal, $description, $audience, $offerings, $exclusions, $limits['maxDifficulty'] ?? null, $limits['maxStrength'] ?? null, null, $markets);
    }

    /**
     * Valid keyword locale keys ("2840:en"), unique, in the given order.
     *
     * @return list<string>
     */
    public static function markets(mixed $value): array
    {
        $keys = [];
        foreach (is_array($value) ? $value : [] as $key) {
            if (!is_string($key) || preg_match('/^(\d{1,6}):([a-z]{2})$/D', $key, $m) !== 1) {
                continue;
            }
            try {
                $keys[(new \SeoControlCenter\Domain\Keywords\KeywordLocale((int) $m[1], $m[2]))->key()] = true;
            } catch (\InvalidArgumentException) {
                continue;
            }
        }

        return array_keys($keys);
    }

    /** True when no markets are set or the locale is one of them. */
    public function servesMarket(\SeoControlCenter\Domain\Keywords\KeywordLocale $locale): bool
    {
        return $this->markets === [] || in_array($locale->key(), $this->markets, true);
    }

    /** @return list<string> */
    public static function terms(mixed $value): array
    {
        $parts = is_array($value) ? $value : preg_split('/[\r\n,;]+/u', is_string($value) ? $value : '');
        $terms = [];
        foreach ($parts ?: [] as $part) {
            if (is_string($part) && ($term = Keyword::normalize($part)) !== '') {
                $terms[$term] = true;
            }
        }

        return array_keys($terms);
    }

    public function difficultyLimit(): int
    {
        return $this->maxDifficulty ?? self::DEFAULT_MAX_DIFFICULTY;
    }

    public function strengthLimit(): int
    {
        return $this->maxStrength ?? self::DEFAULT_MAX_STRENGTH;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return ['model' => $this->model, 'goal' => $this->goal, 'description' => $this->description, 'audience' => $this->audience, 'offerings' => $this->offerings, 'exclusions' => $this->exclusions, 'max_difficulty' => $this->maxDifficulty, 'max_strength' => $this->maxStrength, 'markets' => $this->markets, 'updated_at' => $this->updatedAt];
    }
}
