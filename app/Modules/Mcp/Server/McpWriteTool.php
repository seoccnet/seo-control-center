<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\Mcp\Server;

/**
 * An MCP tool that changes data (ADR-036). Its scope is a write scope.
 * The server applies the write rate limit and `client_request_id`
 * idempotency; the tool writes only through an application service, with
 * the token's creator as the actor and the token name as `via`. Write
 * tools never start provider or paid work and never hard-delete.
 */
interface McpWriteTool extends McpTool {}
