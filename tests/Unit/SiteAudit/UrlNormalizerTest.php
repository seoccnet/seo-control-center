<?php

declare(strict_types=1);

namespace SeoControlCenter\Tests\Unit\SiteAudit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SeoControlCenter\Modules\SiteAudit\Crawler\UrlNormalizer;

final class UrlNormalizerTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string, ?string}>
     */
    public static function links(): iterable
    {
        $base = 'https://shop.acme.test/blog/post/?x=1';
        yield 'absolute' => ['https://Shop.Acme.TEST/About#team', $base, 'https://shop.acme.test/About'];
        yield 'root relative' => ['/contact', $base, 'https://shop.acme.test/contact'];
        yield 'relative' => ['other', $base, 'https://shop.acme.test/blog/post/other'];
        yield 'parent' => ['../../shop?a=b', $base, 'https://shop.acme.test/shop?a=b'];
        yield 'dot segments' => ['./a/./b/../c', $base, 'https://shop.acme.test/blog/post/a/c'];
        yield 'too many parents' => ['../../../../x', $base, 'https://shop.acme.test/x'];
        yield 'query only' => ['?page=2', $base, 'https://shop.acme.test/blog/post/?page=2'];
        yield 'protocol relative' => ['//cdn.acme.test/img', $base, 'https://cdn.acme.test/img'];
        yield 'default port' => ['https://shop.acme.test:443/', $base, 'https://shop.acme.test/'];
        yield 'other port' => ['https://shop.acme.test:8443/', $base, 'https://shop.acme.test:8443/'];
        yield 'no path' => ['http://shop.acme.test', $base, 'http://shop.acme.test/'];
        yield 'spaces' => ['/a b', $base, 'https://shop.acme.test/a%20b'];
        yield 'fragment only' => ['#top', $base, null];
        yield 'mailto' => ['mailto:info@acme.test', $base, null];
        yield 'javascript' => ['javascript:void(0)', $base, null];
        yield 'tel' => ['tel:+123', $base, null];
        yield 'credentials' => ['https://user:pw@shop.acme.test/', $base, null];
        yield 'empty' => ['  ', $base, null];
    }

    #[DataProvider('links')]
    public function testResolvesLinks(string $href, string $base, ?string $expected): void
    {
        self::assertSame($expected, UrlNormalizer::resolve($href, $base));
    }

    public function testRejectsOverlongAddresses(): void
    {
        self::assertNull(UrlNormalizer::normalize('https://shop.acme.test/' . str_repeat('a', 2100)));
    }

    public function testPathAndQuery(): void
    {
        self::assertSame('/a/b?c=d', UrlNormalizer::pathAndQuery('https://shop.acme.test/a/b?c=d'));
        self::assertSame('/', UrlNormalizer::pathAndQuery('https://shop.acme.test'));
    }
}
