<?php

declare(strict_types=1);

namespace SeoControlCenter\Domain\Websites;

use InvalidArgumentException;
use Stringable;

/**
 * A registrable host name such as "example.com" or "shop.example.co.uk".
 *
 * Accepts user input like "https://www.Example.com/path" and normalizes it to
 * a lowercase ASCII host (IDNs are converted to punycode when ext-intl is
 * available). IP addresses, localhost and single-label names are rejected.
 */
final readonly class DomainName implements Stringable
{
    public string $value;

    public function __construct(string $input)
    {
        $host = trim($input);
        if ($host === '') {
            throw new InvalidArgumentException('Enter a domain name.');
        }
        if (preg_match('#^[a-z][a-z0-9+.-]*://#i', $host) === 1) {
            $parsed = parse_url($host, PHP_URL_HOST);
            $host = is_string($parsed) ? $parsed : '';
        } else {
            $host = preg_split('#[/?\#:]#', $host)[0] ?? '';
        }
        $host = rtrim(mb_strtolower($host), '.');

        if ($host !== '' && preg_match('/[^\x20-\x7e]/', $host) === 1) {
            if (!function_exists('idn_to_ascii')) {
                throw new InvalidArgumentException('International domain names need the PHP intl extension.');
            }
            $ascii = idn_to_ascii($host, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46);
            $host = is_string($ascii) ? $ascii : '';
        }

        if (!self::isValidHost($host)) {
            throw new InvalidArgumentException('Enter a valid domain name, for example example.com.');
        }
        $this->value = $host;
    }

    public static function tryFrom(string $input): ?self
    {
        try {
            return new self($input);
        } catch (InvalidArgumentException) {
            return null;
        }
    }

    public function __toString(): string
    {
        return $this->value;
    }

    private static function isValidHost(string $host): bool
    {
        if ($host === '' || strlen($host) > 253 || filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return false;
        }
        $labels = explode('.', $host);
        if (count($labels) < 2) {
            return false;
        }
        foreach ($labels as $label) {
            if (preg_match('/^(?!-)[a-z0-9-]{1,63}(?<!-)$/D', $label) !== 1) {
                return false;
            }
        }

        return preg_match('/^[a-z]{2,63}$|^xn--[a-z0-9-]{1,59}$/D', end($labels)) === 1;
    }
}
