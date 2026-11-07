<?php

declare(strict_types=1);

namespace SeoControlCenter\Core\Database;

use SensitiveParameter;

final readonly class DatabaseConfig
{
    public function __construct(
        public string $host,
        public int $port,
        public string $database,
        public string $username,
        #[SensitiveParameter]
        public string $password,
        public string $charset = 'utf8mb4',
        public string $collation = 'utf8mb4_unicode_ci',
        public int $connectTimeoutSeconds = 5,
    ) {
        if ($database === '') {
            throw new DatabaseException('Database name is not configured.');
        }
        if (preg_match('/^[a-z0-9_]+$/i', $charset . $collation) !== 1) {
            throw new DatabaseException('Invalid charset or collation.');
        }
    }

    public function dsn(): string
    {
        return sprintf('mysql:host=%s;port=%d;dbname=%s;charset=%s', $this->host, $this->port, $this->database, $this->charset);
    }

    /**
     * Never expose the password when the object is dumped.
     *
     * @return array<string, mixed>
     */
    public function __debugInfo(): array
    {
        return [
            'host' => $this->host,
            'port' => $this->port,
            'database' => $this->database,
            'username' => $this->username,
            'password' => '[REDACTED]',
        ];
    }
}
