<?php

declare(strict_types=1);

namespace SeoControlCenter\Core\Crypto;

use SeoControlCenter\Core\Config\Environment;

/**
 * Loads the key ring (ENVIRONMENT.md -> Encryption keys). First match wins:
 * 1. APP_KEYS ("1:base64,2:base64") + APP_ACTIVE_KEY_ID
 * 2. APP_KEYRING_PATH (key ring file)
 * 3. storage/keys/keyring.php
 */
final readonly class KeyRingLoader
{
    public function __construct(
        private Environment $env,
        private string $defaultPath,
    ) {}

    public function load(): KeyRing
    {
        $inline = $this->env->string('APP_KEYS');
        if ($inline !== '') {
            $keys = [];
            foreach (explode(',', $inline) as $pair) {
                [$id, $encoded] = array_map('trim', explode(':', $pair, 2)) + [1 => ''];
                $keys[(int) $id] = self::decode($encoded);
            }
            $active = $this->env->int('APP_ACTIVE_KEY_ID', max(array_keys($keys)));

            return new KeyRing($keys, $active, 'environment');
        }

        $path = $this->path();
        if (!is_file($path)) {
            return new KeyRing([], null, 'missing: ' . $path);
        }

        $data = (static fn(string $__path): mixed => require $__path)($path);
        if (!is_array($data) || !is_array($data['keys'] ?? null)) {
            throw new CryptoException('The key ring file is malformed.');
        }
        $keys = [];
        foreach ($data['keys'] as $id => $encoded) {
            $keys[(int) $id] = self::decode(is_string($encoded) ? $encoded : '');
        }
        $active = $data['active'] ?? null;

        return new KeyRing($keys, is_int($active) ? $active : null, 'file: ' . $path);
    }

    public function path(): string
    {
        $configured = $this->env->string('APP_KEYRING_PATH');

        return $configured !== '' ? $configured : $this->defaultPath;
    }

    public function usesEnvironment(): bool
    {
        return $this->env->string('APP_KEYS') !== '';
    }

    private static function decode(string $encoded): string
    {
        $raw = base64_decode(preg_replace('/^base64:/', '', $encoded) ?? '', true);
        if ($raw === false || strlen($raw) !== KeyRing::KEY_BYTES) {
            throw new CryptoException('A key in the key ring is not a valid base64-encoded 32-byte key.');
        }

        return $raw;
    }
}
