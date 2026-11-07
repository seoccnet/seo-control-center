<?php

declare(strict_types=1);

namespace SeoControlCenter\Core\Database;

use Closure;
use PDO;
use PDOException;
use PDOStatement;
use Throwable;

/**
 * Thin PDO wrapper: lazy connection, prepared statements only, typed binding,
 * UTC session time zone and strict SQL mode.
 *
 * SQL belongs in repositories/query objects; this class only executes it.
 */
final class Connection
{
    private ?PDO $pdo = null;

    private int $transactionDepth = 0;

    public function __construct(private readonly DatabaseConfig $config) {}

    /**
     * @param array<int|string, scalar|null> $params
     * @return array<string, mixed>|null
     */
    public function fetchOne(string $sql, array $params = []): ?array
    {
        $row = $this->run($sql, $params)->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $row : null;
    }

    /**
     * @param array<int|string, scalar|null> $params
     * @return list<array<string, mixed>>
     */
    public function fetchAll(string $sql, array $params = []): array
    {
        /** @var list<array<string, mixed>> */
        return $this->run($sql, $params)->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * @param array<int|string, scalar|null> $params
     */
    public function fetchValue(string $sql, array $params = []): mixed
    {
        $value = $this->run($sql, $params)->fetchColumn();

        return $value === false ? null : $value;
    }

    /**
     * Executes a statement and returns the number of affected rows.
     *
     * @param array<int|string, scalar|null> $params
     */
    public function execute(string $sql, array $params = []): int
    {
        return $this->run($sql, $params)->rowCount();
    }

    /**
     * Executes a statement without parameters (DDL in migrations).
     */
    public function statement(string $sql): void
    {
        try {
            $this->pdo()->exec($sql);
        } catch (PDOException $e) {
            throw new DatabaseException('Database statement failed: ' . $this->describe($e), 0, $e);
        }
    }

    public function lastInsertId(): int
    {
        return (int) $this->pdo()->lastInsertId();
    }

    /**
     * Runs the callback in a transaction. Nested calls join the outer
     * transaction. Note: DDL statements cause an implicit commit in MySQL/MariaDB.
     *
     * @template T
     * @param Closure(self): T $callback
     * @return T
     */
    public function transaction(Closure $callback): mixed
    {
        if ($this->transactionDepth > 0) {
            ++$this->transactionDepth;
            try {
                return $callback($this);
            } finally {
                --$this->transactionDepth;
            }
        }

        $pdo = $this->pdo();
        $pdo->beginTransaction();
        $this->transactionDepth = 1;
        try {
            $result = $callback($this);
            if ($pdo->inTransaction()) {
                $pdo->commit();
            }

            return $result;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        } finally {
            $this->transactionDepth = 0;
        }
    }

    public function serverVersion(): ServerVersion
    {
        return ServerVersion::fromString((string) $this->fetchValue('SELECT VERSION()'));
    }

    public function isConnected(): bool
    {
        return $this->pdo !== null;
    }

    public function disconnect(): void
    {
        $this->pdo = null;
        $this->transactionDepth = 0;
    }

    public function pdo(): PDO
    {
        if ($this->pdo !== null) {
            return $this->pdo;
        }

        try {
            $pdo = new PDO($this->config->dsn(), $this->config->username, $this->config->password, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
                PDO::ATTR_STRINGIFY_FETCHES => false,
                // Fail fast when the host's database server is overloaded instead of holding a PHP worker.
                PDO::ATTR_TIMEOUT => $this->config->connectTimeoutSeconds,
            ]);
            // One round trip for the whole session setup.
            $pdo->exec(sprintf(
                "SET NAMES %s COLLATE %s, time_zone = '+00:00', "
                . "SESSION sql_mode = 'STRICT_TRANS_TABLES,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION,NO_ZERO_DATE,NO_ZERO_IN_DATE'%s",
                $this->config->charset,
                $this->config->collation,
                // Shared hosts often set a short wait_timeout. A cron run can sit idle during a slow
                // provider call, so keep its connection alive for the whole bounded run.
                PHP_SAPI === 'cli' ? ', SESSION wait_timeout = CAST(GREATEST(@@SESSION.wait_timeout, 600) AS UNSIGNED)' : '',
            ));
        } catch (PDOException $e) {
            // Do not include the DSN or credentials in the message.
            throw new DatabaseException(sprintf('Could not connect to the database (SQLSTATE %s).', (string) $e->getCode()), 0, $e);
        }

        return $this->pdo = $pdo;
    }

    /**
     * @param array<int|string, scalar|null> $params
     */
    private function run(string $sql, array $params): PDOStatement
    {
        try {
            $statement = $this->pdo()->prepare($sql);
            foreach ($params as $key => $value) {
                if (is_bool($value)) {
                    $value = (int) $value;
                }
                $statement->bindValue(is_int($key) ? $key + 1 : ':' . ltrim($key, ':'), $value, match (true) {
                    $value === null => PDO::PARAM_NULL,
                    is_int($value) => PDO::PARAM_INT,
                    default => PDO::PARAM_STR,
                });
            }
            $statement->execute();

            return $statement;
        } catch (PDOException $e) {
            throw new DatabaseException('Database query failed: ' . $this->describe($e), 0, $e);
        }
    }

    private function describe(PDOException $e): string
    {
        $info = $e->errorInfo;

        return sprintf('SQLSTATE %s, driver code %s', (string) ($info[0] ?? $e->getCode()), (string) ($info[1] ?? '?'));
    }
}
