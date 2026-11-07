<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\Mcp\Server;

use RuntimeException;

/**
 * A tool could not run (invalid arguments, unknown or inaccessible
 * resource). The message is returned to the AI client, so it must be safe.
 */
final class ToolError extends RuntimeException {}
