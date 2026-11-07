<?php

declare(strict_types=1);

namespace SeoControlCenter\Core\Update;

use RuntimeException;

/**
 * A release package was refused or an update failed. Messages are shown to
 * installation administrators.
 */
final class UpdateException extends RuntimeException {}
