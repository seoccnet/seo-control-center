<?php

declare(strict_types=1);

namespace SeoControlCenter\Core\Config;

/**
 * Read-only view of environment values.
 *
 * Real environment variables take precedence over values from the .env file,
 * so hosts that support environment variables can override the file.
 */
final readonly class Environment
{
    /**
     * @param array<string, string> $fileValues
     * @param array<string, string> $processValues
     */
    public function __construct(
        private array $fileValues = [],
        private array $processValues = [],
    ) {}

    public static function fromFile(string $path): self
    {
        $process = [];
        foreach (getenv() as $key => $value) {
            $process[(string) $key] = (string) $value;
        }

        return new self(EnvFile::load($path), $process);
    }

    public function has(string $key): bool
    {
        return $this->raw($key) !== null;
    }

    public function string(string $key, string $default = ''): string
    {
        return $this->raw($key) ?? $default;
    }

    public function bool(string $key, bool $default = false): bool
    {
        $value = $this->raw($key);
        if ($value === null || $value === '') {
            return $default;
        }

        return match (strtolower($value)) {
            '1', 'true', 'yes', 'on' => true,
            '0', 'false', 'no', 'off' => false,
            default => throw new ConfigException(sprintf('Environment value %s must be a boolean.', $key)),
        };
    }

    public function int(string $key, int $default = 0): int
    {
        $value = $this->raw($key);
        if ($value === null || $value === '') {
            return $default;
        }
        if (preg_match('/^-?\d+$/D', $value) !== 1) {
            throw new ConfigException(sprintf('Environment value %s must be an integer.', $key));
        }

        return (int) $value;
    }

    private function raw(string $key): ?string
    {
        return $this->processValues[$key] ?? $this->fileValues[$key] ?? null;
    }
}
