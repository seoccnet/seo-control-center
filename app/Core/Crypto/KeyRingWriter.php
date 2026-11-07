<?php

declare(strict_types=1);

namespace SeoControlCenter\Core\Crypto;

use SeoControlCenter\Core\Support\Runtime;

/**
 * Writes the key ring file atomically with restrictive permissions.
 */
final class KeyRingWriter
{
    private function __construct() {}

    public static function write(KeyRing $ring, string $path): void
    {
        $dir = dirname($path);
        if (!is_dir($dir) && !mkdir($dir, 0o700, true) && !is_dir($dir)) {
            throw new CryptoException('Cannot create directory ' . $dir);
        }
        $tmp = $path . '.tmp-' . bin2hex(random_bytes(4));
        if (file_put_contents($tmp, $ring->toPhpFile(), LOCK_EX) === false) {
            throw new CryptoException('Cannot write the key ring to ' . $dir);
        }
        Runtime::chmod($tmp, 0o600);
        if (!rename($tmp, $path)) {
            @unlink($tmp);
            throw new CryptoException('Cannot replace the key ring file ' . $path);
        }
        if (function_exists('opcache_invalidate')) {
            opcache_invalidate($path, true);
        }
    }
}
