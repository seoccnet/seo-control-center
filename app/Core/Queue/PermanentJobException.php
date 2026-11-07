<?php

declare(strict_types=1);

namespace SeoControlCenter\Core\Queue;

use RuntimeException;

/**
 * A failure that retrying cannot fix (invalid credentials, invalid input).
 */
final class PermanentJobException extends RuntimeException {}
