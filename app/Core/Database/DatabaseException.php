<?php

declare(strict_types=1);

namespace SeoControlCenter\Core\Database;

use RuntimeException;

/**
 * Database failure. The message is safe for logs but never shown to users;
 * the original PDOException is kept as the previous exception.
 */
class DatabaseException extends RuntimeException {}
