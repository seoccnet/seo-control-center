<?php

declare(strict_types=1);

namespace SeoControlCenter\Tests\Unit\Crypto;

use PHPUnit\Framework\TestCase;
use SeoControlCenter\Core\Config\Environment;
use SeoControlCenter\Core\Crypto\CryptoException;
use SeoControlCenter\Core\Crypto\Encrypter;
use SeoControlCenter\Core\Crypto\KeyRing;
use SeoControlCenter\Core\Crypto\KeyRingLoader;

/**
 * TESTING.md -> Encryption: encrypted at rest, rotatable by key version.
 */
final class EncrypterTest extends TestCase
{
    public function testRoundTripAndCiphertextIsNotPlaintext(): void
    {
        $encrypter = new Encrypter(self::ring());
        $envelope = $encrypter->encrypt('refresh-token-123', 'connection_tokens.refresh:abc');

        self::assertStringNotContainsString('refresh-token-123', $envelope);
        self::assertSame('refresh-token-123', $encrypter->decrypt($envelope, 'connection_tokens.refresh:abc'));
        self::assertNotSame($envelope, $encrypter->encrypt('refresh-token-123', 'connection_tokens.refresh:abc'), 'Random nonce per value.');
    }

    public function testValueMovedToAnotherContextFailsToDecrypt(): void
    {
        $encrypter = new Encrypter(self::ring());
        $envelope = $encrypter->encrypt('secret', 'mfa.totp:1');

        $this->expectException(CryptoException::class);
        $encrypter->decrypt($envelope, 'mfa.totp:2');
    }

    public function testTamperingIsDetected(): void
    {
        $encrypter = new Encrypter(self::ring());
        $envelope = $encrypter->encrypt('secret', 'ctx');
        $envelope[strlen($envelope) - 1] = chr(ord($envelope[strlen($envelope) - 1]) ^ 1);

        $this->expectException(CryptoException::class);
        $encrypter->decrypt($envelope, 'ctx');
    }

    public function testHeaderTamperingIsDetected(): void
    {
        $ring = self::ring()->withNewActiveKey(random_bytes(32));
        $encrypter = new Encrypter($ring);
        $envelope = $encrypter->encrypt('secret', 'ctx');
        // Rewrite the key id to the other (valid) key: must not decrypt.
        $envelope = $envelope[0] . pack('n', 1) . substr($envelope, 3);

        $this->expectException(CryptoException::class);
        $encrypter->decrypt($envelope, 'ctx');
    }

    public function testRotationKeepsOldValuesReadable(): void
    {
        $old = self::ring();
        $envelope = (new Encrypter($old))->encrypt('secret', 'ctx');

        $rotated = $old->withNewActiveKey(random_bytes(32));
        $encrypter = new Encrypter($rotated);

        self::assertSame(1, Encrypter::keyIdOf($envelope));
        self::assertTrue($encrypter->needsReencryption($envelope));
        self::assertSame('secret', $encrypter->decrypt($envelope, 'ctx'));
        self::assertSame(2, Encrypter::keyIdOf($encrypter->encrypt('secret', 'ctx')));
    }

    public function testMissingKeyRingFailsClearly(): void
    {
        $this->expectException(CryptoException::class);
        $this->expectExceptionMessage('crypto:key:generate');
        (new Encrypter(KeyRing::empty()))->encrypt('x', 'ctx');
    }

    public function testActiveKeyCannotBeRemoved(): void
    {
        $this->expectException(CryptoException::class);
        self::ring()->withoutKey(1);
    }

    public function testLoaderReadsEnvironmentKeys(): void
    {
        $k1 = base64_encode(random_bytes(32));
        $k2 = base64_encode(random_bytes(32));
        $ring = (new KeyRingLoader(new Environment(['APP_KEYS' => "1:{$k1},2:base64:{$k2}", 'APP_ACTIVE_KEY_ID' => '1']), '/nonexistent'))->load();

        self::assertSame(1, $ring->activeId());
        self::assertSame([1, 2], $ring->ids());
    }

    public function testLoaderRejectsShortKeys(): void
    {
        $this->expectException(CryptoException::class);
        (new KeyRingLoader(new Environment(['APP_KEYS' => '1:' . base64_encode('short')]), '/nonexistent'))->load();
    }

    public function testKeyRingFileRoundTrip(): void
    {
        $file = sys_get_temp_dir() . '/scc-keyring-' . bin2hex(random_bytes(4)) . '.php';
        $ring = self::ring()->withNewActiveKey(random_bytes(32));
        file_put_contents($file, $ring->toPhpFile());
        try {
            $loaded = (new KeyRingLoader(new Environment([]), $file))->load();
            self::assertSame(2, $loaded->activeId());
            self::assertSame($ring->key(1), $loaded->key(1));
            self::assertStringNotContainsString(base64_encode($ring->key(1)), print_r($loaded, true), 'Keys are hidden from dumps.');
        } finally {
            unlink($file);
        }
    }

    public function testMissingFileMeansNotConfigured(): void
    {
        self::assertFalse((new KeyRingLoader(new Environment([]), '/nonexistent/keyring.php'))->load()->isConfigured());
    }

    private static function ring(): KeyRing
    {
        return new KeyRing([1 => random_bytes(32)], 1);
    }
}
