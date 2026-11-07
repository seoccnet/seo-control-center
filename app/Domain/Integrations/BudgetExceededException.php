<?php

declare(strict_types=1);

namespace SeoControlCenter\Domain\Integrations;

use RuntimeException;

/**
 * A paid provider request was refused before it was sent because it would
 * exceed a workspace budget. Retrying does not help until the period ends
 * or the budget is raised.
 */
final class BudgetExceededException extends RuntimeException {}
