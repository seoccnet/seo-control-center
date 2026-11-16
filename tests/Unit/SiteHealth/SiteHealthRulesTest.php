<?php

declare(strict_types=1);

namespace SeoControlCenter\Tests\Unit\SiteHealth;

use PHPUnit\Framework\TestCase;
use SeoControlCenter\Core\Support\FrozenClock;
use SeoControlCenter\Domain\SiteHealth\HealthRules;
use SeoControlCenter\Domain\SiteHealth\RegistrableDomain;
use SeoControlCenter\Modules\SiteHealth\RdapDomainLookup;
use SeoControlCenter\Tests\Support\Fakes\FakeHttpClient;

final class SiteHealthRulesTest extends TestCase
{
    public function testRegistrableDomains(): void
    {
        self::assertSame('acme.test', RegistrableDomain::of('shop.acme.test'));
        self::assertSame('example.co.uk', RegistrableDomain::of('www.shop.example.co.uk'));
        self::assertSame('example.com', RegistrableDomain::of('Example.COM.'));
        self::assertNull(RegistrableDomain::of('localhost'));
        self::assertNull(RegistrableDomain::of('203.0.113.4'));
    }

    public function testUpAndExpiryThresholds(): void
    {
        self::assertTrue(HealthRules::isUp(301));
        self::assertTrue(HealthRules::isUp(403), 'A server that refuses still answers.');
        self::assertFalse(HealthRules::isUp(502));
        self::assertFalse(HealthRules::isUp(null));
        self::assertSame([], HealthRules::expiry(HealthRules::DOMAIN, '2027-01-01 00:00:00', null, '2026-11-16 00:00:00', 'the domain'));
        $warning = HealthRules::expiry(HealthRules::DOMAIN, '2026-11-20 00:00:00', null, '2026-11-16 00:00:00', 'the domain x.test');
        self::assertSame('domain:2026-11-20:7', $warning[0]['key']);
        self::assertSame('The domain x.test expires in 4 days', $warning[0]['title']);
        self::assertSame('The TLS certificate has expired', HealthRules::expiry(HealthRules::SSL, '2026-11-10 00:00:00', null, '2026-11-16 00:00:00', 'the TLS certificate')[0]['title']);
    }

    public function testRobotsAndSitemapChanges(): void
    {
        self::assertSame([], HealthRules::robots(null, null, null, 200, 'a', 'x'), 'The first check is a baseline.');
        self::assertSame([], HealthRules::robots(200, 'a', 'x', 200, 'a', 'x'));
        $gone = HealthRules::robots(200, 'a', 'Disallow: /cart', 404, 'b', '');
        self::assertStringStartsWith('robots.txt now answers HTTP 404 (was 200).', $gone[0]['detail']);
        self::assertSame([], HealthRules::sitemap(null, 0, '2026-11-16'));
        self::assertSame([], HealthRules::sitemap(8, 0, '2026-11-16'), 'Tiny sitemaps are not watched.');
        self::assertSame([], HealthRules::sitemap(1000, 950, '2026-11-16'), 'Less than 10% is noise.');
        self::assertSame('The sitemap lists no pages any more', HealthRules::sitemap(50, 0, '2026-11-16')[0]['title']);
    }

    public function testRdapLookup(): void
    {
        $dir = sys_get_temp_dir() . '/scc-rdap-' . bin2hex(random_bytes(6));
        $http = new FakeHttpClient();
        $http->json('GET', 'https://data.iana.org/rdap/dns.json', ['services' => [[['com', 'net'], ['https://rdap.verisign.test/com/v1/']]]]);
        $http->json('GET', 'https://rdap.verisign.test/com/v1/domain/example.com', [
            'events' => [['eventAction' => 'registration', 'eventDate' => '2001-01-01T00:00:00Z'], ['eventAction' => 'expiration', 'eventDate' => '2027-03-04T05:06:07Z']],
            'entities' => [['roles' => ['registrar'], 'vcardArray' => ['vcard', [['version', [], 'text', '4.0'], ['fn', [], 'text', 'Example Registrar, Inc.']]]]],
        ]);
        $lookup = new RdapDomainLookup($http, new FrozenClock(new \DateTimeImmutable('2026-11-16 10:00:00')), $dir);

        self::assertSame(['expires' => '2027-03-04 05:06:07', 'registrar' => 'Example Registrar, Inc.', 'error' => null], $lookup->expiry('example.com'));
        self::assertStringContainsString('.de registry offers no RDAP lookup', (string) $lookup->expiry('example.de')['error']);
        self::assertCount(1, $http->requestsTo('https://data.iana.org/'), 'The bootstrap file is cached.');

        foreach (glob($dir . '/*') ?: [] as $file) {
            unlink($file);
        }
        rmdir($dir);
    }
}
