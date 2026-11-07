<?php

declare(strict_types=1);

namespace SeoControlCenter\Domain\Integrations;

use RuntimeException;

/**
 * A provider call failed. `retryable` distinguishes temporary problems
 * (timeouts, 429, 5xx) from permanent ones (revoked access, bad request),
 * which must not be retried forever (INTEGRATIONS.md).
 */
final class IntegrationException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly bool $retryable,
        public readonly bool $authorizationLost = false,
        public readonly ?int $retryAfterSeconds = null,
        public readonly bool $chargeUncertain = false,
        public readonly ?int $providerStatusCode = null,
    ) {
        parent::__construct($message);
    }
}
