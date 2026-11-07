<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\Mcp\Persistence;

use SeoControlCenter\Core\Database\Connection;
use SeoControlCenter\Domain\Mcp\McpWriteRequests;

final readonly class PdoMcpWriteRequests implements McpWriteRequests
{
    public function __construct(private Connection $db) {}

    public function claim(int $tokenId, string $tool, string $requestId, string $argumentsHash, string $now, string $expiredBefore, string $staleBefore): array
    {
        $this->db->execute(
            'DELETE FROM mcp_write_requests WHERE token_id = ? AND tool = ? AND request_id = ? AND (created_at < ? OR (completed_at IS NULL AND created_at < ?))',
            [$tokenId, $tool, $requestId, $expiredBefore, $staleBefore],
        );
        // The unique key decides between two concurrent claims: only one insert wins.
        if ($this->db->execute('INSERT IGNORE INTO mcp_write_requests (token_id, tool, request_id, arguments_hash, created_at) VALUES (?, ?, ?, ?, ?)', [$tokenId, $tool, $requestId, $argumentsHash, $now]) === 1) {
            return ['state' => self::NEW, 'result' => null];
        }
        $row = $this->db->fetchOne('SELECT arguments_hash, result, completed_at FROM mcp_write_requests WHERE token_id = ? AND tool = ? AND request_id = ?', [$tokenId, $tool, $requestId]);
        if ($row === null) {
            return ['state' => self::PENDING, 'result' => null];
        }
        if (!hash_equals((string) $row['arguments_hash'], $argumentsHash)) {
            return ['state' => self::MISMATCH, 'result' => null];
        }
        if ($row['completed_at'] === null || !is_string($row['result'])) {
            return ['state' => self::PENDING, 'result' => null];
        }
        $result = json_decode($row['result'], true, 64, JSON_THROW_ON_ERROR);
        /** @var array<string, mixed> $result */
        $result = is_array($result) ? $result : [];

        return ['state' => self::DONE, 'result' => $result];
    }

    public function complete(int $tokenId, string $tool, string $requestId, array $result, string $now): void
    {
        $this->db->execute(
            'UPDATE mcp_write_requests SET result = ?, completed_at = ? WHERE token_id = ? AND tool = ? AND request_id = ?',
            [json_encode($result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $now, $tokenId, $tool, $requestId],
        );
    }

    public function release(int $tokenId, string $tool, string $requestId): void
    {
        $this->db->execute('DELETE FROM mcp_write_requests WHERE token_id = ? AND tool = ? AND request_id = ? AND completed_at IS NULL', [$tokenId, $tool, $requestId]);
    }
}
