<?php

declare(strict_types=1);

namespace SeoControlCenter\Core\Logging;

use Stringable;

/**
 * Central secret redaction for logs (SECURITY.md -> Logging).
 *
 * Redacts values by key name (password, token, secret, …) at any depth and
 * masks bearer tokens / basic credentials / key=value secrets inside strings.
 */
final readonly class Redactor
{
    public const string MASK = '[REDACTED]';

    private const array DEFAULT_SENSITIVE_KEYS = [
        'password', 'passwd', 'pass', 'secret', 'token', 'access_token', 'refresh_token',
        'id_token', 'api_key', 'apikey', 'key', 'authorization', 'cookie', 'set-cookie',
        'session', 'client_secret', 'private_key', 'license_key', 'credentials', 'dsn',
        'x-goog-api-key',
        'assertion',
        'webhook_url',
    ];

    /** @var list<string> */
    private array $sensitiveKeys;

    /**
     * @param list<string> $additionalKeys
     */
    public function __construct(array $additionalKeys = [])
    {
        $this->sensitiveKeys = array_values(array_unique(array_map(
            static fn(string $key): string => self::normalizeKey($key),
            [...self::DEFAULT_SENSITIVE_KEYS, ...$additionalKeys],
        )));
    }

    /**
     * @param array<array-key, mixed> $context
     * @return array<array-key, mixed>
     */
    public function redactArray(array $context, int $depth = 0): array
    {
        if ($depth > 8) {
            return ['_truncated' => true];
        }

        $result = [];
        foreach ($context as $key => $value) {
            if (is_string($key) && $this->isSensitiveKey($key)) {
                $result[$key] = self::MASK;
                continue;
            }
            $result[$key] = $this->redactValue($value, $depth + 1);
        }

        return $result;
    }

    public function redactString(string $value): string
    {
        $patterns = [
            '#https://hooks\.slack(?:-gov)?\.com/services/[^\s"\'<>]+#i' => self::MASK,
            '/\b(webhook_url)(["\']?\s*[:=]\s*["\']?)[^"\'\s,;}]+/i' => '$1$2' . self::MASK,
            // Authorization: Bearer xxx / Basic xxx
            '/\b(Bearer|Basic)\s+[A-Za-z0-9\-._~+\/]+=*/i' => '$1 ' . self::MASK,
            // key=value or "key":"value" pairs with sensitive names
            '/\b((?:access_|refresh_|id_)?token|password|passwd|secret|api_?key|client_secret|assertion|x-goog-api-key)(["\']?\s*[:=]\s*["\']?)[^"\'&\s,;}]+/i'
                => '$1$2' . self::MASK,
            // user:password@ in URLs/DSNs
            '#(://[^:/\s@]+:)[^@/\s]+@#' => '$1' . self::MASK . '@',
        ];

        return (string) preg_replace(array_keys($patterns), array_values($patterns), $value);
    }

    private function redactValue(mixed $value, int $depth): mixed
    {
        return match (true) {
            is_array($value) => $this->redactArray($value, $depth),
            is_string($value) => $this->redactString($value),
            $value instanceof Stringable => $this->redactString((string) $value),
            default => $value,
        };
    }

    private function isSensitiveKey(string $key): bool
    {
        $normalized = self::normalizeKey($key);
        foreach ($this->sensitiveKeys as $sensitive) {
            if ($normalized === $sensitive || str_ends_with($normalized, '_' . $sensitive)) {
                return true;
            }
        }

        return false;
    }

    private static function normalizeKey(string $key): string
    {
        return strtolower(str_replace(['-', ' '], '_', $key));
    }
}
