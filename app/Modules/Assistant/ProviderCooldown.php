<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\Assistant;

use SensitiveParameter;
use SeoControlCenter\Core\RateLimit\RateLimiter;
use SeoControlCenter\Domain\Integrations\IntegrationException;

/** Shared chat/digest backoff across workers; no sleeping or automatic retry. */
final readonly class ProviderCooldown
{
    private string $key;
    public function __construct(private RateLimiter $limiter, int $workspaceId, string $provider, #[SensitiveParameter] string $apiKey)
    {
        $this->key = 'assistant:provider:' . $workspaceId . ':' . $provider . ':' . hash('sha256', $apiKey);
    }
    public function remaining(): int
    {
        return $this->limiter->peek($this->key . ':cooldown', 0)->retryAfterSeconds;
    }
    public function failed(IntegrationException $error): int
    {
        if (!$error->retryable) {
            return 0;
        }
        $failures = $this->limiter->hit($this->key . ':failures', 1000, 600)->hits;
        $wait = max(min(86400, max(0, $error->retryAfterSeconds ?? 0)), min(300, 15 * (2 ** min(4, $failures - 1))) + random_int(1, 5));
        $this->limiter->hit($this->key . ':cooldown', 0, $wait);
        return $wait;
    }
    public function succeeded(): void
    {
        $this->limiter->clear($this->key . ':failures');
    }
}
