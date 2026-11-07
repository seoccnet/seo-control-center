<?php

declare(strict_types=1);

namespace SeoControlCenter\Core\Install;

use SensitiveParameter;
use SeoControlCenter\Core\Support\Runtime;

/**
 * Writes the .env file. Values are double-quoted and escaped so they are
 * read back verbatim by EnvFile.
 */
final class EnvWriter
{
    private function __construct() {}

    /**
     * @param array<string, string> $values
     */
    public static function render(#[SensitiveParameter] array $values): string
    {
        $lines = ['# SEO Control Center configuration (written by the installer). Never commit or share this file.'];
        foreach ($values as $key => $value) {
            if (preg_match('/^[A-Z][A-Z0-9_]*$/D', $key) !== 1) {
                throw new \InvalidArgumentException('Invalid .env key: ' . $key);
            }
            if (preg_match('/[\r\n]/', $value) === 1) {
                throw new \InvalidArgumentException('Line breaks are not allowed in ' . $key . '.');
            }
            $lines[] = sprintf('%s="%s"', $key, addcslashes($value, '\\"$'));
        }

        return implode("\n", $lines) . "\n";
    }

    /**
     * @param array<string, string> $values
     */
    public static function write(string $path, #[SensitiveParameter] array $values): void
    {
        $tmp = $path . '.tmp-' . bin2hex(random_bytes(4));
        if (file_put_contents($tmp, self::render($values), LOCK_EX) === false) {
            throw new \RuntimeException('Cannot write the .env file. Make the application folder writable.');
        }
        Runtime::chmod($tmp, 0o600);
        if (!rename($tmp, $path)) {
            @unlink($tmp);
            throw new \RuntimeException('Cannot create the .env file.');
        }
    }
}
