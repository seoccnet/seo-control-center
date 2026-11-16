<?php

declare(strict_types=1);

namespace SeoControlCenter\Domain\SiteHealth;

/**
 * The registered domain of a host (1.3.0), such as example.co.uk for
 * shop.example.co.uk: the part a registrar renews. Uses the common
 * two-label public suffixes; without the full public suffix list, a rare
 * suffix can be read one label short, and the lookup then reports that the
 * domain is not found rather than a wrong date.
 */
final class RegistrableDomain
{
    private const array TWO_LABEL_SUFFIXES = [
        'co.uk', 'org.uk', 'me.uk', 'ltd.uk', 'plc.uk', 'net.uk', 'ac.uk', 'gov.uk',
        'com.au', 'net.au', 'org.au', 'co.nz', 'org.nz', 'net.nz', 'co.za', 'org.za',
        'com.br', 'net.br', 'org.br', 'co.jp', 'ne.jp', 'or.jp', 'co.kr', 'or.kr', 'com.cn', 'net.cn', 'org.cn',
        'com.tw', 'com.hk', 'com.sg', 'com.my', 'co.in', 'net.in', 'org.in', 'co.id', 'co.th', 'com.tr', 'com.mx', 'com.ar',
        'com.co', 'com.pe', 'co.il', 'com.ua', 'co.at', 'or.at', 'com.pl', 'net.pl', 'org.pl', 'co.hu', 'com.es', 'com.pt', 'com.gr', 'co.ba', 'com.hr',
    ];

    public static function of(string $host): ?string
    {
        $host = strtolower(trim($host, ". \t"));
        if ($host === '' || filter_var($host, FILTER_VALIDATE_IP) !== false || !str_contains($host, '.')) {
            return null;
        }
        $labels = explode('.', $host);
        $count = count($labels);
        $lastTwo = $labels[$count - 2] . '.' . $labels[$count - 1];
        $take = in_array($lastTwo, self::TWO_LABEL_SUFFIXES, true) ? 3 : 2;
        if ($count < $take) {
            return null;
        }

        return implode('.', array_slice($labels, -$take));
    }
}
