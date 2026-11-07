<?php

declare(strict_types=1);

namespace SeoControlCenter\Core\Crypto;

use SeoControlCenter\Core\Database\Connection;

/**
 * Re-encrypts stored secrets with the active key, in bounded batches
 * (SECURITY.md -> Rotation). Safe to run repeatedly.
 */
final readonly class SecretRotator
{
    /**
     * @param list<EncryptedColumn> $columns
     */
    public function __construct(
        private Connection $db,
        private Encrypter $encrypter,
        private KeyRing $keys,
        private array $columns,
    ) {}

    /**
     * Number of stored values per key ID, across all registered columns.
     *
     * @return array<int, int>
     */
    public function countByKey(): array
    {
        $counts = [];
        foreach ($this->columns as $column) {
            $rows = $this->db->fetchAll(sprintf(
                'SELECT CONV(HEX(SUBSTRING(%1$s, 2, 2)), 16, 10) AS key_id, COUNT(*) AS n FROM %2$s WHERE %1$s IS NOT NULL GROUP BY key_id',
                $column->column,
                $column->table,
            ));
            foreach ($rows as $row) {
                $id = (int) $row['key_id'];
                $counts[$id] = ($counts[$id] ?? 0) + (int) $row['n'];
            }
        }
        ksort($counts);

        return $counts;
    }

    /**
     * Re-encrypts up to $limit values that use a non-active key.
     * Returns the number of values rewritten.
     */
    public function rotateBatch(int $limit = 200): int
    {
        $active = pack('n', $this->keys->activeId());
        $done = 0;
        foreach ($this->columns as $column) {
            if ($done >= $limit) {
                break;
            }
            $select = implode(', ', array_unique([$column->primaryKey, $column->column, ...$column->contextColumns]));
            $rows = $this->db->fetchAll(sprintf(
                'SELECT %s FROM %s WHERE %s IS NOT NULL AND SUBSTRING(%s, 2, 2) <> ? LIMIT %d',
                $select,
                $column->table,
                $column->column,
                $column->column,
                $limit - $done,
            ), [$active]);

            foreach ($rows as $row) {
                $context = ($column->context)($row);
                $plaintext = $this->encrypter->decrypt((string) $row[$column->column], $context);
                $this->db->execute(
                    sprintf('UPDATE %s SET %s = ? WHERE %s = ? AND %s = ?', $column->table, $column->column, $column->primaryKey, $column->column),
                    [$this->encrypter->encrypt($plaintext, $context), $row[$column->primaryKey], $row[$column->column]],
                );
                ++$done;
            }
        }

        return $done;
    }
}
