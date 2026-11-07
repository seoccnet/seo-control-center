<?php

declare(strict_types=1);

namespace SeoControlCenter\Core\Backup;

final readonly class BackupFile
{
    public function __construct(
        public string $name,
        public string $path,
        public int $size,
        public string $createdAt,
    ) {}

    public static function fromPath(string $path): self
    {
        clearstatcache(true, $path);

        return new self(basename($path), $path, (int) filesize($path), gmdate('Y-m-d H:i:s', (int) filemtime($path)));
    }
}
