<?php

declare(strict_types=1);

namespace SeoControlCenter\Core\Crypto;

use SensitiveParameter;

/**
 * Authenticated encryption for stored secrets (SECURITY.md -> Secret encryption, ADR-014).
 *
 * Envelope: [1 byte format][2 bytes key id][24 bytes nonce][ciphertext + 16-byte tag]
 * Algorithm: XChaCha20-Poly1305 (IETF). The context string is bound as
 * additional authenticated data, so a value copied to another row or
 * column fails to decrypt.
 */
final readonly class Encrypter
{
    private const int FORMAT = 1;
    private const int HEADER = 1 + 2 + SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES;

    public function __construct(private KeyRing $keys) {}

    public function encrypt(#[SensitiveParameter] string $plaintext, string $context): string
    {
        $keyId = $this->keys->activeId();
        $nonce = random_bytes(SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES);
        $header = chr(self::FORMAT) . pack('n', $keyId) . $nonce;

        $ciphertext = sodium_crypto_aead_xchacha20poly1305_ietf_encrypt(
            $plaintext,
            $header . $context,
            $nonce,
            $this->keys->key($keyId),
        );

        return $header . $ciphertext;
    }

    public function decrypt(string $envelope, string $context): string
    {
        if (strlen($envelope) < self::HEADER + SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_ABYTES || ord($envelope[0]) !== self::FORMAT) {
            throw new CryptoException('Unsupported or corrupted encrypted value.');
        }
        $keyId = self::keyIdOf($envelope);
        $header = substr($envelope, 0, self::HEADER);
        $nonce = substr($envelope, 3, SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES);

        $plaintext = sodium_crypto_aead_xchacha20poly1305_ietf_decrypt(
            substr($envelope, self::HEADER),
            $header . $context,
            $nonce,
            $this->keys->key($keyId),
        );
        if ($plaintext === false) {
            throw new CryptoException('Decryption failed: wrong key, wrong context or tampered data.');
        }

        return $plaintext;
    }

    public static function keyIdOf(string $envelope): int
    {
        if (strlen($envelope) < 3) {
            throw new CryptoException('Corrupted encrypted value.');
        }
        $unpacked = unpack('n', substr($envelope, 1, 2));

        return is_array($unpacked) ? (int) $unpacked[1] : 0;
    }

    public function needsReencryption(string $envelope): bool
    {
        return self::keyIdOf($envelope) !== $this->keys->activeId();
    }

    public function isConfigured(): bool
    {
        return $this->keys->isConfigured();
    }
}
