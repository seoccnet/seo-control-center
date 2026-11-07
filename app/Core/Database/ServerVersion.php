<?php

declare(strict_types=1);

namespace SeoControlCenter\Core\Database;

/**
 * Database server version and the ADR-011 support check.
 */
final readonly class ServerVersion
{
    public const string MINIMUM_MARIADB = '10.6.0';
    public const string MINIMUM_MYSQL = '8.0.0';

    private function __construct(
        public string $raw,
        public bool $isMariaDb,
        public string $version,
    ) {}

    public static function fromString(string $raw): self
    {
        $isMariaDb = stripos($raw, 'mariadb') !== false;
        // Old MariaDB servers report "5.5.5-10.x.y-MariaDB" for replication compatibility.
        $clean = preg_replace('/^5\.5\.5-/', '', $raw) ?? $raw;
        if (preg_match('/^(\d+)\.(\d+)\.(\d+)/', $clean, $m) !== 1) {
            throw new DatabaseException(sprintf('Unrecognised database version "%s".', $raw));
        }

        return new self($raw, $isMariaDb, sprintf('%d.%d.%d', $m[1], $m[2], $m[3]));
    }

    public function product(): string
    {
        return $this->isMariaDb ? 'MariaDB' : 'MySQL';
    }

    public function minimum(): string
    {
        return $this->isMariaDb ? self::MINIMUM_MARIADB : self::MINIMUM_MYSQL;
    }

    public function isSupported(): bool
    {
        return version_compare($this->version, $this->minimum(), '>=');
    }

    public function describe(): string
    {
        return sprintf('%s %s', $this->product(), $this->version);
    }
}
