<?php

declare(strict_types=1);

namespace SeoControlCenter\Core\Http\Client;

final readonly class SystemHostResolver implements HostResolver
{
    public function addresses(string $host): array
    {
        $addresses = gethostbynamel($host) ?: [];
        $records = @dns_get_record($host, DNS_AAAA) ?: [];
        foreach ($records as $record) {
            if (is_string($record['ipv6'] ?? null)) {
                $addresses[] = $record['ipv6'];
            }
        }

        return array_values(array_unique($addresses));
    }

    /**
     * Whether every address is a public one (no private, loopback, link-local or reserved range).
     *
     * @param list<string> $addresses
     */
    public static function allPublic(array $addresses): bool
    {
        if ($addresses === []) {
            return false;
        }
        foreach ($addresses as $address) {
            $packed = @inet_pton($address);
            if ($packed === false || filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_GLOBAL_RANGE) === false) {
                return false;
            }
            // Require unicast. Exclude multicast and IPv6 translation prefixes,
            // which could encode a private IPv4 destination behind a public DNS answer.
            if ((strlen($packed) === 4 && ord($packed[0]) >= 224)
                || (strlen($packed) === 16 && (ord($packed[0]) & 0xe0) !== 0x20)) {
                return false;
            }
        }

        return true;
    }
}
