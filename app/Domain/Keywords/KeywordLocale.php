<?php

declare(strict_types=1);

namespace SeoControlCenter\Domain\Keywords;

use SeoControlCenter\Domain\Shared\ValidationException;

/**
 * Where a keyword is measured: a Google Ads country (criterion ID) and a
 * Google Search language. The catalog is the full list of countries and
 * languages in Markets (0.44.3); which DataForSEO services accept a market
 * is decided there too.
 */
final readonly class KeywordLocale
{
    /** Google Ads country criterion ID => name, sorted by name. */
    public const array LOCATIONS = Markets::COUNTRY_NAMES;

    /** Google Search language code => name, sorted by name. */
    public const array LANGUAGES = Markets::LANGUAGES;

    public function __construct(
        public int $locationCode,
        public string $languageCode,
    ) {
        if (!isset(self::LOCATIONS[$locationCode]) || !isset(self::LANGUAGES[$languageCode])) {
            throw new \InvalidArgumentException('Unsupported keyword location or language.');
        }
    }

    public static function default(): self
    {
        return new self(2840, 'en');
    }

    /**
     * @throws ValidationException
     */
    public static function fromInput(mixed $location, mixed $language): self
    {
        $code = is_string($location) && ctype_digit($location) ? (int) $location : (is_int($location) ? $location : 0);
        $errors = [];
        if (!isset(self::LOCATIONS[$code])) {
            $errors['location'] = 'Choose a country.';
        }
        if (!is_string($language) || !isset(self::LANGUAGES[$language])) {
            $errors['language'] = 'Choose a language.';
        }
        if ($errors !== []) {
            throw new ValidationException($errors);
        }

        return new self($code, (string) $language);
    }

    /** The locale of a key() value, or null when it is not a supported market. */
    public static function fromKey(string $key): ?self
    {
        if (preg_match('/^(\d{1,6}):([a-z]{2,3}(?:-[A-Za-z0-9]{2,4})?)$/D', $key, $m) !== 1) {
            return null;
        }
        try {
            return new self((int) $m[1], $m[2]);
        } catch (\InvalidArgumentException) {
            return null;
        }
    }

    public function key(): string
    {
        return $this->locationCode . ':' . $this->languageCode;
    }

    public function label(): string
    {
        return self::LOCATIONS[$this->locationCode] . ' · ' . self::LANGUAGES[$this->languageCode];
    }

    public function equals(self $other): bool
    {
        return $this->locationCode === $other->locationCode && $this->languageCode === $other->languageCode;
    }
}
