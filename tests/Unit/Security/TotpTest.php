<?php

declare(strict_types=1);

namespace SeoControlCenter\Tests\Unit\Security;

use PHPUnit\Framework\TestCase;
use SeoControlCenter\Core\Security\Totp;

final class TotpTest extends TestCase
{
    /**
     * RFC 6238 Appendix B (SHA-1 seed "12345678901234567890"), last 6 digits.
     */
    public function testRfc6238Vectors(): void
    {
        $secret = '12345678901234567890';
        $vectors = [59 => '287082', 1111111109 => '081804', 1111111111 => '050471', 1234567890 => '005924', 2000000000 => '279037'];

        foreach ($vectors as $time => $expected) {
            self::assertSame($expected, Totp::code($secret, Totp::stepAt($time)), (string) $time);
        }
    }

    public function testAcceptsNeighbourStepsOnly(): void
    {
        $secret = Totp::generateSecret();
        $now = 1_790_000_000;
        $step = Totp::stepAt($now);

        self::assertSame($step, Totp::verify($secret, Totp::code($secret, $step), $now));
        self::assertSame($step - 1, Totp::verify($secret, Totp::code($secret, $step - 1), $now));
        self::assertSame($step + 1, Totp::verify($secret, Totp::code($secret, $step + 1), $now));
        self::assertNull(Totp::verify($secret, Totp::code($secret, $step - 2), $now));
        self::assertNull(Totp::verify($secret, 'abcdef', $now));
        self::assertNull(Totp::verify($secret, '12345', $now));
    }

    public function testRejectsReplayOfUsedStep(): void
    {
        $secret = Totp::generateSecret();
        $now = 1_790_000_000;
        $step = Totp::stepAt($now);
        $code = Totp::code($secret, $step);

        self::assertNull(Totp::verify($secret, $code, $now, lastUsedStep: $step));
        self::assertSame($step, Totp::verify($secret, $code, $now, lastUsedStep: $step - 1));
    }

    public function testBase32(): void
    {
        self::assertSame('MZXW6YTBOI', Totp::base32Encode('foobar'));
        self::assertSame('foobar', Totp::base32Decode('mzxw 6ytb oi'));
        $secret = Totp::generateSecret();
        self::assertSame($secret, Totp::base32Decode(Totp::base32Encode($secret)));
    }

    public function testProvisioningUri(): void
    {
        $uri = Totp::provisioningUri('foobar', 'jane@example.com', 'SEO Control Center');

        self::assertStringStartsWith('otpauth://totp/SEO%20Control%20Center:jane%40example.com?secret=MZXW6YTBOI', $uri);
        self::assertStringContainsString('digits=6', $uri);
    }
}
