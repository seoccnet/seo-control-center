<?php

declare(strict_types=1);

namespace SeoControlCenter\Core\Security;

use SensitiveParameter;

/**
 * Time-based one-time passwords (RFC 6238; HMAC-SHA1, 6 digits, 30 s steps),
 * compatible with common authenticator apps. Implements the published
 * standard with PHP's hash_hmac; no custom cryptography.
 */
final class Totp
{
    public const int PERIOD = 30;
    public const int DIGITS = 6;
    public const int SECRET_BYTES = 20;

    private const string BASE32 = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    private function __construct() {}

    public static function generateSecret(): string
    {
        return random_bytes(self::SECRET_BYTES);
    }

    public static function code(#[SensitiveParameter] string $secret, int $step): string
    {
        $hmac = hash_hmac('sha1', pack('J', $step), $secret, true);
        $offset = ord($hmac[19]) & 0x0F;
        $binary = ((ord($hmac[$offset]) & 0x7F) << 24)
            | (ord($hmac[$offset + 1]) << 16)
            | (ord($hmac[$offset + 2]) << 8)
            | ord($hmac[$offset + 3]);

        return str_pad((string) ($binary % (10 ** self::DIGITS)), self::DIGITS, '0', STR_PAD_LEFT);
    }

    public static function stepAt(int $unixTime): int
    {
        return intdiv($unixTime, self::PERIOD);
    }

    /**
     * Verifies a code within ±$window steps. Returns the matched step, or null.
     * Steps at or before $lastUsedStep are rejected (replay protection).
     */
    public static function verify(#[SensitiveParameter] string $secret, string $code, int $unixTime, ?int $lastUsedStep = null, int $window = 1): ?int
    {
        $code = preg_replace('/\s+/', '', $code) ?? '';
        if (preg_match('/^\d{6}$/D', $code) !== 1) {
            return null;
        }
        $current = self::stepAt($unixTime);
        $matched = null;
        for ($step = $current - $window; $step <= $current + $window; ++$step) {
            // Compare every candidate (no early exit) to keep timing uniform.
            if (hash_equals(self::code($secret, $step), $code) && ($lastUsedStep === null || $step > $lastUsedStep)) {
                $matched = $step;
            }
        }

        return $matched;
    }

    public static function base32Encode(string $bytes): string
    {
        $bits = '';
        foreach (str_split($bytes) as $char) {
            $bits .= str_pad(decbin(ord($char)), 8, '0', STR_PAD_LEFT);
        }
        $out = '';
        foreach (str_split($bits, 5) as $chunk) {
            $out .= self::BASE32[(int) bindec(str_pad($chunk, 5, '0'))];
        }

        return $out;
    }

    public static function base32Decode(string $encoded): string
    {
        $encoded = strtoupper(preg_replace('/[\s=-]/', '', $encoded) ?? '');
        $bits = '';
        foreach (str_split($encoded) as $char) {
            $pos = strpos(self::BASE32, $char);
            if ($pos === false) {
                throw new \InvalidArgumentException('Invalid base32 input.');
            }
            $bits .= str_pad(decbin($pos), 5, '0', STR_PAD_LEFT);
        }
        $out = '';
        foreach (str_split($bits, 8) as $byte) {
            if (strlen($byte) === 8) {
                $out .= chr(((int) bindec($byte)) & 0xFF);
            }
        }

        return $out;
    }

    /**
     * otpauth:// URI for authenticator apps.
     */
    public static function provisioningUri(#[SensitiveParameter] string $secret, string $account, string $issuer): string
    {
        return sprintf(
            'otpauth://totp/%s:%s?secret=%s&issuer=%s&algorithm=SHA1&digits=%d&period=%d',
            rawurlencode($issuer),
            rawurlencode($account),
            self::base32Encode($secret),
            rawurlencode($issuer),
            self::DIGITS,
            self::PERIOD,
        );
    }
}
