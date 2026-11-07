<?php

declare(strict_types=1);

namespace SeoControlCenter\Tests\Unit\Http;

use PHPUnit\Framework\TestCase;
use SeoControlCenter\Core\Http\Client\CurlHttpClient;
use SeoControlCenter\Core\Http\Client\HttpClientException;
use SeoControlCenter\Core\Http\Client\SystemHostResolver;
use SeoControlCenter\Tests\Support\Fakes\FakeHostResolver;

final class OutboundAddressTest extends TestCase
{
    public function testMixedPrivateAndMappedPrivateAddressesAreBlockedBeforeTransport(): void
    {
        foreach ([[], ['127.0.0.1'], ['93.184.216.34', '10.0.0.1'], ['::1'], ['::ffff:127.0.0.1'], ['::ffff:10.0.0.1'], ['fe80::1'], ['100.100.100.200'], ['198.18.0.1'], ['224.0.0.1'], ['64:ff9b::7f00:1'], ['2002:a00:1::1'], ['not-an-ip']] as $addresses) {
            self::assertFalse(SystemHostResolver::allPublic($addresses));
            $resolver = new FakeHostResolver();
            $resolver->map['destination.test'] = $addresses;
            try {
                (new CurlHttpClient(resolver: $resolver))->send('POST', 'https://destination.test/hook', [], '{}', ['destination.test']);
                self::fail('Unsafe DNS should never reach cURL.');
            } catch (HttpClientException $e) {
                self::assertStringContainsString('public addresses', $e->getMessage());
            }
        }
        self::assertTrue(SystemHostResolver::allPublic(['93.184.216.34', '2606:4700:4700::1111']));
    }
}
