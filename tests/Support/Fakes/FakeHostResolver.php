<?php

declare(strict_types=1);

namespace SeoControlCenter\Tests\Support\Fakes;

use SeoControlCenter\Core\Http\Client\HostResolver;

/**
 * DNS without the network: every host resolves to a public documentation
 * address unless a test maps it elsewhere.
 */
final class FakeHostResolver implements HostResolver
{
    /** @var array<string, list<string>> */
    public array $map = [];

    public function addresses(string $host): array
    {
        return $this->map[$host] ?? ['93.184.216.34'];
    }
}
