<?php

declare(strict_types=1);

namespace SeoControlCenter\Core\Config;

/**
 * Minimal .env parser.
 *
 * Supports KEY=value, quoted values, and # comments. It does not expand
 * variables and never executes anything. Values are returned as strings.
 */
final class EnvFile
{
    /**
     * @return array<string, string>
     */
    public static function parse(string $contents): array
    {
        $values = [];
        $lines = preg_split('/\R/', $contents) ?: [];

        foreach ($lines as $number => $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }
            if (str_starts_with($line, 'export ')) {
                $line = ltrim(substr($line, 7));
            }

            $separator = strpos($line, '=');
            if ($separator === false) {
                throw new ConfigException(sprintf('Invalid .env line %d: missing "=".', $number + 1));
            }

            $key = trim(substr($line, 0, $separator));
            if (preg_match('/^[A-Z][A-Z0-9_]*$/D', $key) !== 1) {
                throw new ConfigException(sprintf('Invalid .env key on line %d.', $number + 1));
            }

            $values[$key] = self::parseValue(trim(substr($line, $separator + 1)));
        }

        return $values;
    }

    /**
     * @return array<string, string>
     */
    public static function load(string $path): array
    {
        if (!is_file($path)) {
            return [];
        }
        $contents = file_get_contents($path);
        if ($contents === false) {
            throw new ConfigException('Unable to read the environment file.');
        }

        return self::parse($contents);
    }

    private static function parseValue(string $raw): string
    {
        if ($raw === '') {
            return '';
        }

        $quote = $raw[0];
        if ($quote === "'") {
            $end = strpos($raw, "'", 1);
            if ($end === false) {
                throw new ConfigException('Unterminated quoted value in .env file.');
            }

            return substr($raw, 1, $end - 1);
        }
        if ($quote === '"') {
            // Double quotes: backslash escapes \" \\ \$ (as written by EnvWriter).
            $value = '';
            $length = strlen($raw);
            for ($i = 1; $i < $length; ++$i) {
                $char = $raw[$i];
                if ($char === '\\' && $i + 1 < $length) {
                    $value .= $raw[++$i];
                    continue;
                }
                if ($char === '"') {
                    return $value;
                }
                $value .= $char;
            }
            throw new ConfigException('Unterminated quoted value in .env file.');
        }

        // Unquoted: strip trailing inline comment ("value   # comment").
        $commentPos = strpos($raw, ' #');
        if ($commentPos !== false) {
            $raw = substr($raw, 0, $commentPos);
        }

        return rtrim($raw);
    }
}
