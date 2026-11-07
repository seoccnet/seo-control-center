<?php

declare(strict_types=1);

namespace SeoControlCenter\Core\Crypto;

use SensitiveParameter;

/**
 * Versioned encryption keys (ADR-014). Never stored in the database.
 */
final readonly class KeyRing
{
    public const int KEY_BYTES = SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_KEYBYTES;

    /**
     * @param array<int, string> $keys key id => raw 32-byte key
     */
    public function __construct(
        #[SensitiveParameter]
        private array $keys,
        private ?int $activeId,
        public string $source = 'none',
    ) {
        foreach ($keys as $id => $key) {
            if ($id < 1 || $id > 65535 || strlen($key) !== self::KEY_BYTES) {
                throw new CryptoException(sprintf('Key %d in the key ring is invalid.', $id));
            }
        }
        if ($activeId !== null && !isset($keys[$activeId])) {
            throw new CryptoException('The active key ID does not exist in the key ring.');
        }
    }

    public static function empty(): self
    {
        return new self([], null);
    }

    public function isConfigured(): bool
    {
        return $this->activeId !== null;
    }

    public function activeId(): int
    {
        if ($this->activeId === null) {
            throw new CryptoException('Encryption keys are not configured. Run "php bin/console crypto:key:generate".');
        }

        return $this->activeId;
    }

    public function key(int $id): string
    {
        if (!isset($this->keys[$id])) {
            throw new CryptoException(sprintf('Encryption key %d is not in the key ring.', $id));
        }

        return $this->keys[$id];
    }

    /**
     * @return list<int>
     */
    public function ids(): array
    {
        return array_keys($this->keys);
    }

    /**
     * Returns a copy with a new key added and made active.
     */
    public function withNewActiveKey(#[SensitiveParameter] string $key): self
    {
        $id = $this->keys === [] ? 1 : max(array_keys($this->keys)) + 1;
        $keys = $this->keys;
        $keys[$id] = $key;

        return new self($keys, $id, $this->source);
    }

    /**
     * Returns a copy without a retired key. The active key cannot be removed.
     */
    public function withoutKey(int $id): self
    {
        if ($id === $this->activeId) {
            throw new CryptoException('The active key cannot be removed.');
        }
        $keys = $this->keys;
        unset($keys[$id]);

        return new self($keys, $this->activeId, $this->source);
    }

    /**
     * PHP source for the key ring file (a PHP file, so a web request executes
     * rather than prints it).
     */
    public function toPhpFile(): string
    {
        $lines = [];
        foreach ($this->keys as $id => $key) {
            $lines[] = sprintf("        %d => 'base64:%s',", $id, base64_encode($key));
        }

        return "<?php\n\n// SEO Control Center encryption key ring. Keep secret, back up separately, never commit.\n"
            . "return [\n    'active' => " . ($this->activeId ?? 'null') . ",\n    'keys' => [\n"
            . implode("\n", $lines) . "\n    ],\n];\n";
    }

    /**
     * @return array<string, mixed>
     */
    public function __debugInfo(): array
    {
        return ['active' => $this->activeId, 'ids' => array_keys($this->keys), 'source' => $this->source];
    }
}
