<?php

declare(strict_types=1);

namespace SeoControlCenter\Tests\Unit\SiteMigration;

use PHPUnit\Framework\TestCase;
use SeoControlCenter\Domain\SiteMigration\RedirectCheck;
use SeoControlCenter\Domain\SiteMigration\RedirectMatcher;

final class RedirectMatcherTest extends TestCase
{
    public function testMatchesInOrderOfConfidence(): void
    {
        $matcher = new RedirectMatcher([
            'https://new.test/',
            'https://new.test/about',
            'https://new.test/shop/garden/rain-barrel-200l',
            'https://new.test/shop/garden/',
            'https://new.test/blog/watering-tomatoes-in-summer',
            'https://new.test/a/duplicate',
            'https://new.test/b/duplicate',
        ]);

        self::assertSame(['url' => 'https://new.test/', 'method' => 'path', 'confidence' => 100], $matcher->match('https://old.test/'));
        self::assertSame(['url' => 'https://new.test/about', 'method' => 'path', 'confidence' => 95], $matcher->match('https://old.test/About.html'));
        self::assertSame(['url' => 'https://new.test/shop/garden/rain-barrel-200l', 'method' => 'slug', 'confidence' => 85], $matcher->match('https://old.test/products/rain-barrel-200l?ref=1'));
        $similar = $matcher->match('https://old.test/news/tomatoes-watering-summer');
        self::assertSame('https://new.test/blog/watering-tomatoes-in-summer', $similar['url'] ?? null);
        self::assertSame('similar', $similar['method'] ?? null);
        self::assertSame(['url' => 'https://new.test/shop/garden/', 'method' => 'parent', 'confidence' => 30], $matcher->match('https://old.test/shop/garden/discontinued-thing'));
        // Two new pages share the slug and nothing else fits: no guess, and never the home page.
        self::assertNull($matcher->match('https://old.test/x/duplicate'));
        self::assertNull($matcher->match('https://old.test/contact-form'));
        self::assertNull($matcher->match('mailto:info@old.test'));
    }

    public function testGradesRedirects(): void
    {
        $ok = ['status' => 200, 'location' => null, 'error' => null];
        $new = 'https://new.test/a';

        self::assertSame(RedirectCheck::OK, RedirectCheck::grade(['status' => 301, 'location' => $new, 'error' => null], $ok, $new)['state']);
        self::assertSame(RedirectCheck::OK, RedirectCheck::grade(['status' => 308, 'location' => 'HTTPS://NEW.TEST/a', 'error' => null], $ok, $new)['state']);
        self::assertSame(RedirectCheck::TEMPORARY, RedirectCheck::grade(['status' => 302, 'location' => $new, 'error' => null], $ok, $new)['state']);
        self::assertSame(RedirectCheck::WRONG_TARGET, RedirectCheck::grade(['status' => 301, 'location' => 'https://new.test/', 'error' => null], $ok, $new)['state']);
        self::assertSame(RedirectCheck::CHAIN, RedirectCheck::grade(['status' => 301, 'location' => 'https://new.test/a/', 'error' => null], ['status' => 301, 'location' => $new, 'error' => null], $new)['state']);
        self::assertSame(RedirectCheck::CHAIN, RedirectCheck::grade(['status' => 301, 'location' => $new, 'error' => null], ['status' => 301, 'location' => 'https://new.test/a/', 'error' => null], $new)['state']);
        self::assertSame(RedirectCheck::TARGET_BROKEN, RedirectCheck::grade(['status' => 301, 'location' => $new, 'error' => null], ['status' => 404, 'location' => null, 'error' => null], $new)['state']);
        self::assertSame(RedirectCheck::NOT_REDIRECTED, RedirectCheck::grade($ok, null, $new)['state']);
        self::assertSame(RedirectCheck::MISSING, RedirectCheck::grade(['status' => 404, 'location' => null, 'error' => null], null, null)['state']);
        self::assertSame(RedirectCheck::FAILED, RedirectCheck::grade(['status' => null, 'location' => null, 'error' => 'Timeout'], null, $new)['state']);
        self::assertSame(RedirectCheck::SKIPPED, RedirectCheck::grade(['status' => null, 'location' => null, 'error' => 'http://', 'skipped' => true], null, $new)['state']);
        // Without a chosen target, any working permanent redirect counts.
        self::assertSame(RedirectCheck::OK, RedirectCheck::grade(['status' => 301, 'location' => $new, 'error' => null], $ok, null)['state']);
    }
}
