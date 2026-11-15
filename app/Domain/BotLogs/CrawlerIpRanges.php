<?php

declare(strict_types=1);

namespace SeoControlCenter\Domain\BotLogs;

/**
 * The IP ranges a crawler publishes (1.2.1), such as Google's
 * googlebot.json, as CIDR prefixes (IPv4 and IPv6). A request whose
 * address falls inside them really comes from that crawler; one outside
 * only claims to.
 */
final readonly class CrawlerIpRanges
{
    public const int MAX_PREFIXES = 5000;

    /** @var list<array{string, int}> packed network address and prefix length */
    private array $prefixes;

    /** @param list<string> $cidrs e.g. "66.249.64.0/27", "2001:4860:4801:10::/64"; invalid entries are ignored */
    public function __construct(array $cidrs)
    {
        $prefixes = [];
        foreach (array_slice($cidrs, 0, self::MAX_PREFIXES) as $cidr) {
            $parsed = self::parse($cidr);
            if ($parsed !== null) {
                $prefixes[] = $parsed;
            }
        }
        $this->prefixes = $prefixes;
    }

    /**
     * The prefixes of a published ranges file: {"prefixes": [{"ipv4Prefix": "…"}, {"ipv6Prefix": "…"}]}.
     *
     * @return list<string>
     */
    public static function fromJson(string $json): array
    {
        $data = json_decode($json, true);
        $out = [];
        foreach (is_array($data) && is_array($data['prefixes'] ?? null) ? $data['prefixes'] : [] as $entry) {
            foreach (['ipv4Prefix', 'ipv6Prefix'] as $key) {
                if (is_array($entry) && is_string($entry[$key] ?? null)) {
                    $out[] = $entry[$key];
                }
            }
        }

        return $out;
    }

    public function count(): int
    {
        return count($this->prefixes);
    }

    public function contains(string $ip): bool
    {
        $address = @inet_pton($ip);
        if ($address === false) {
            return false;
        }
        foreach ($this->prefixes as [$network, $bits]) {
            if (strlen($network) === strlen($address) && self::masked($address, $bits) === $network) {
                return true;
            }
        }

        return false;
    }

    /** @return array{string, int}|null */
    private static function parse(string $cidr): ?array
    {
        $parts = explode('/', trim($cidr));
        if (count($parts) !== 2 || !ctype_digit($parts[1])) {
            return null;
        }
        $address = @inet_pton($parts[0]);
        $bits = (int) $parts[1];
        if ($address === false || $bits > strlen($address) * 8) {
            return null;
        }

        return [self::masked($address, $bits), $bits];
    }

    /** The first $bits bits of a packed address, the rest zero. */
    private static function masked(string $address, int $bits): string
    {
        $out = '';
        $length = strlen($address);
        for ($i = 0; $i < $length; ++$i) {
            $keep = max(0, min(8, $bits - 8 * $i));
            $out .= chr(ord($address[$i]) & ((0xFF << (8 - $keep)) & 0xFF));
        }

        return $out;
    }
}
