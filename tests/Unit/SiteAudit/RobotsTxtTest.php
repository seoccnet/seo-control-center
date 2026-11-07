<?php

declare(strict_types=1);

namespace SeoControlCenter\Tests\Unit\SiteAudit;

use PHPUnit\Framework\TestCase;
use SeoControlCenter\Modules\SiteAudit\Crawler\RobotsTxt;

final class RobotsTxtTest extends TestCase
{
    public function testUsesTheGenericGroupWithLongestMatch(): void
    {
        $robots = RobotsTxt::parse(<<<'TXT'
            # comment
            User-agent: Googlebot
            Disallow: /

            User-agent: *
            Disallow: /admin/
            Allow: /admin/public/
            Disallow: /*.pdf$
            Disallow: /search?

            Sitemap: https://shop.acme.test/sitemap.xml
            TXT, 'SEO-Control-Center');

        self::assertTrue($robots->allows('/'));
        self::assertFalse($robots->allows('/admin/users'));
        self::assertTrue($robots->allows('/admin/public/page'));
        self::assertFalse($robots->allows('/files/guide.pdf'));
        self::assertTrue($robots->allows('/files/guide.pdf?download=1'));
        self::assertFalse($robots->allows('/search?q=x'));
        self::assertTrue($robots->allows('/searching'));
        self::assertTrue($robots->allows('/robots.txt'));
        self::assertSame(['https://shop.acme.test/sitemap.xml'], $robots->sitemaps);
    }

    public function testAGroupForTheCrawlerWins(): void
    {
        $robots = RobotsTxt::parse("User-agent: *\nDisallow: /\n\nUser-agent: seo-control-center\nUser-agent: other\nDisallow: /private\n", 'SEO-Control-Center');

        self::assertTrue($robots->allows('/blog'));
        self::assertFalse($robots->allows('/private/x'));
    }

    public function testPercentEncodedUnreservedAndUnicodePathsMatchWithoutDecodingSeparators(): void
    {
        $robots = RobotsTxt::parse("User-agent: *\nDisallow: /café\nDisallow: /private\nDisallow: /a%2Fb\n", 'SEO-Control-Center');
        self::assertFalse($robots->allows('/caf%C3%A9'));
        self::assertFalse($robots->allows('/caf%c3%a9'));
        self::assertFalse($robots->allows('/%70rivate'));
        self::assertFalse($robots->allows('/a%2fb'));
        self::assertTrue($robots->allows('/a/b'));
    }

    public function testTieGoesToAllowAndEmptyDisallowAllowsAll(): void
    {
        self::assertTrue(RobotsTxt::parse("User-agent: *\nDisallow: /page\nAllow: /page\n", 'x')->allows('/page'));
        self::assertTrue(RobotsTxt::parse("User-agent: *\nDisallow:\n", 'x')->allows('/anything'));
        self::assertTrue(RobotsTxt::parse('', 'x')->allows('/anything'));
        self::assertTrue(RobotsTxt::parse("User-agent: *\nDisallow: /\nUser-agent: SEO-Control-Center\nDisallow:\n", 'SEO-Control-Center')->allows('/anything'));
    }
}
