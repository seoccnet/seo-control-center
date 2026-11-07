<?php

declare(strict_types=1);

namespace SeoControlCenter\Domain\Mcp;

/**
 * Idempotent MCP writes (ADR-036): a token's `client_request_id` per tool
 * is claimed once, and a retry within the retention window gets the first
 * result back instead of writing again.
 */
interface McpWriteRequests
{
    public const string NEW = 'new';
    public const string DONE = 'done';
    public const string PENDING = 'pending';
    public const string MISMATCH = 'mismatch';

    /**
     * Claims the request id. Rows older than $expiredBefore, and unfinished
     * claims older than $staleBefore, are forgotten first.
     *
     * @param string $argumentsHash binary SHA-256 of the canonical arguments
     * @return array{state: string, result: array<string, mixed>|null}
     */
    public function claim(int $tokenId, string $tool, string $requestId, string $argumentsHash, string $now, string $expiredBefore, string $staleBefore): array;

    /**
     * @param array<string, mixed> $result
     */
    public function complete(int $tokenId, string $tool, string $requestId, array $result, string $now): void;

    /** Forgets an unfinished claim after a failed write, so the client can retry. */
    public function release(int $tokenId, string $tool, string $requestId): void;
}
