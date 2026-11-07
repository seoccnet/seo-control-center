<?php

declare(strict_types=1);

namespace SeoControlCenter\Tests\Unit\Domain;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SeoControlCenter\Domain\Accounts\Email;
use SeoControlCenter\Domain\Websites\DomainName;

final class DomainNameTest extends TestCase
{
    #[DataProvider('valid')]
    public function testNormalizesValidInput(string $input, string $expected): void
    {
        self::assertSame($expected, (new DomainName($input))->value);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function valid(): iterable
    {
        yield 'plain' => ['example.com', 'example.com'];
        yield 'uppercase and whitespace' => ['  Example.COM ', 'example.com'];
        yield 'url with path' => ['https://www.example.com/blog?x=1', 'www.example.com'];
        yield 'host with port' => ['example.com:8080', 'example.com'];
        yield 'trailing dot' => ['example.com.', 'example.com'];
        yield 'subdomain + ccTLD' => ['shop.example.co.uk', 'shop.example.co.uk'];
        yield 'punycode' => ['xn--mnchen-3ya.de', 'xn--mnchen-3ya.de'];
    }

    #[DataProvider('invalid')]
    public function testRejectsInvalidInput(string $input): void
    {
        self::assertNull(DomainName::tryFrom($input));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalid(): iterable
    {
        yield 'empty' => [''];
        yield 'single label' => ['localhost'];
        yield 'ipv4' => ['127.0.0.1'];
        yield 'ipv4 url' => ['http://169.254.169.254/latest'];
        yield 'leading hyphen' => ['-example.com'];
        yield 'underscore' => ['exa_mple.com'];
        yield 'numeric tld' => ['example.123'];
        yield 'script' => ['<script>.com'];
        yield 'embedded newline' => ["exa.mple\n.com"];
    }

    public function testIdnIsConvertedWhenIntlIsAvailable(): void
    {
        if (!function_exists('idn_to_ascii')) {
            self::markTestSkipped('ext-intl not installed');
        }
        self::assertSame('xn--mnchen-3ya.de', (new DomainName('münchen.de'))->value);
    }

    public function testEmailIsNormalized(): void
    {
        self::assertSame('jane@example.com', (new Email('  Jane@Example.COM '))->value);
        self::assertNull(Email::tryFrom('not-an-email'));
    }
}
