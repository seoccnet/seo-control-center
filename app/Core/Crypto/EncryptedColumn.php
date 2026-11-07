<?php

declare(strict_types=1);

namespace SeoControlCenter\Core\Crypto;

use Closure;

/**
 * Registry entry describing an encrypted column, used by key rotation to
 * re-encrypt values with the active key.
 */
final readonly class EncryptedColumn
{
    /**
     * @param list<string> $contextColumns columns selected to build the context
     * @param Closure(array<string, mixed>): string $context builds the AAD context from a row
     */
    public function __construct(
        public string $table,
        public string $primaryKey,
        public string $column,
        public array $contextColumns,
        public Closure $context,
    ) {
        foreach ([$table, $primaryKey, $column, ...$contextColumns] as $identifier) {
            if (preg_match('/^[a-z_][a-z0-9_]*$/D', $identifier) !== 1) {
                throw new \InvalidArgumentException('Invalid SQL identifier in encrypted column definition.');
            }
        }
    }
}
