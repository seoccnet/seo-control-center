<?php

declare(strict_types=1);

namespace SeoControlCenter\Core\Config;

/**
 * Immutable application configuration with dot-notation access.
 */
final readonly class Config
{
    /**
     * @param array<string, mixed> $items
     */
    public function __construct(private array $items) {}

    /**
     * Loads every config/*.php file. Each file must return an array and
     * receives $env (Environment) in scope.
     */
    public static function fromDirectory(string $directory, Environment $env): self
    {
        $items = [];
        $files = glob(rtrim($directory, '/\\') . '/*.php') ?: [];
        sort($files);

        foreach ($files as $file) {
            $loader = static function (string $__file, Environment $env): mixed {
                return require $__file;
            };
            $value = $loader($file, $env);
            if (!is_array($value)) {
                throw new ConfigException(sprintf('Config file %s must return an array.', basename($file)));
            }
            $items[basename($file, '.php')] = $value;
        }

        return new self($items);
    }

    public function has(string $key): bool
    {
        $found = false;
        $this->find($key, $found);

        return $found;
    }

    public function get(string $key, mixed $default = null): mixed
    {
        $found = false;
        $value = $this->find($key, $found);

        return $found ? $value : $default;
    }

    public function string(string $key, string $default = ''): string
    {
        $value = $this->get($key, $default);
        if (!is_string($value)) {
            throw new ConfigException(sprintf('Config value %s must be a string.', $key));
        }

        return $value;
    }

    public function int(string $key, int $default = 0): int
    {
        $value = $this->get($key, $default);
        if (!is_int($value)) {
            throw new ConfigException(sprintf('Config value %s must be an integer.', $key));
        }

        return $value;
    }

    public function bool(string $key, bool $default = false): bool
    {
        $value = $this->get($key, $default);
        if (!is_bool($value)) {
            throw new ConfigException(sprintf('Config value %s must be a boolean.', $key));
        }

        return $value;
    }

    /**
     * @return array<array-key, mixed>
     */
    public function array(string $key): array
    {
        $value = $this->get($key, []);
        if (!is_array($value)) {
            throw new ConfigException(sprintf('Config value %s must be an array.', $key));
        }

        return $value;
    }

    private function find(string $key, bool &$found): mixed
    {
        $found = false;
        $current = $this->items;

        foreach (explode('.', $key) as $segment) {
            if (!is_array($current) || !array_key_exists($segment, $current)) {
                return null;
            }
            $current = $current[$segment];
        }

        $found = true;

        return $current;
    }
}
