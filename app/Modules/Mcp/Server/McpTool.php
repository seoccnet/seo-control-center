<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\Mcp\Server;

use SeoControlCenter\Domain\Mcp\McpScope;

/**
 * A read-only MCP tool. Tools receive a context that has already
 * authenticated the token; every project goes through ToolContext::project().
 */
interface McpTool
{
    public function name(): string;

    public function title(): string;

    public function description(): string;

    public function scope(): McpScope;

    /**
     * @return array<string, mixed> JSON Schema of the arguments
     */
    public function inputSchema(): array;

    /**
     * @param array<string, mixed> $arguments
     * @return array<string, mixed> JSON-serializable result (never secrets)
     * @throws ToolError for invalid arguments or inaccessible resources
     */
    public function call(ToolContext $context, array $arguments): array;
}
