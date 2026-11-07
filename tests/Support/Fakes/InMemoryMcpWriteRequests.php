<?php

declare(strict_types=1);

namespace SeoControlCenter\Tests\Support\Fakes;

use SeoControlCenter\Domain\Mcp\McpWriteRequests;

final class InMemoryMcpWriteRequests implements McpWriteRequests
{
    /** @var array<string, array{hash: string, result: array<string, mixed>|null, created: string}> */
    public array $rows = [];

    public function claim(int $tokenId, string $tool, string $requestId, string $argumentsHash, string $now, string $expiredBefore, string $staleBefore): array
    {
        $key = $tokenId . '|' . $tool . '|' . $requestId;
        $row = $this->rows[$key] ?? null;
        if ($row !== null && ($row['created'] < $expiredBefore || ($row['result'] === null && $row['created'] < $staleBefore))) {
            $row = null;
        }
        if ($row === null) {
            $this->rows[$key] = ['hash' => $argumentsHash, 'result' => null, 'created' => $now];

            return ['state' => self::NEW, 'result' => null];
        }
        if (!hash_equals($row['hash'], $argumentsHash)) {
            return ['state' => self::MISMATCH, 'result' => null];
        }

        return $row['result'] === null ? ['state' => self::PENDING, 'result' => null] : ['state' => self::DONE, 'result' => $row['result']];
    }

    public function complete(int $tokenId, string $tool, string $requestId, array $result, string $now): void
    {
        $key = $tokenId . '|' . $tool . '|' . $requestId;
        if (isset($this->rows[$key])) {
            $this->rows[$key]['result'] = $result;
        }
    }

    public function release(int $tokenId, string $tool, string $requestId): void
    {
        $key = $tokenId . '|' . $tool . '|' . $requestId;
        if (isset($this->rows[$key]) && $this->rows[$key]['result'] === null) {
            unset($this->rows[$key]);
        }
    }
}
