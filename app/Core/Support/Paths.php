<?php

declare(strict_types=1);

namespace SeoControlCenter\Core\Support;

/**
 * Well-known application directories, resolved from the application root.
 */
final readonly class Paths
{
    public function __construct(public string $base) {}

    public function config(): string
    {
        return $this->base . '/config';
    }

    public function storage(string $sub = ''): string
    {
        return $this->base . '/storage' . ($sub !== '' ? '/' . ltrim($sub, '/') : '');
    }

    public function views(): string
    {
        return $this->base . '/resources/views';
    }

    public function migrations(): string
    {
        return $this->base . '/database/migrations';
    }

    public function routes(): string
    {
        return $this->base . '/routes';
    }
}
