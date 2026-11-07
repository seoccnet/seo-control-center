<?php

declare(strict_types=1);

namespace SeoControlCenter\Core\Session;

final readonly class SessionConfig
{
    public function __construct(
        public string $cookieName = 'scc_session',
        public bool $secure = true,
        public int $idleTimeoutSeconds = 7200,
        public int $absoluteLifetimeSeconds = 604800,
        public int $touchIntervalSeconds = 60,
    ) {}
}
