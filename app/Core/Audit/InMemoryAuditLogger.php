<?php

declare(strict_types=1);

namespace SeoControlCenter\Core\Audit;

use SeoControlCenter\Core\Support\ClientInfo;

/**
 * Audit logger for tests.
 */
final class InMemoryAuditLogger implements AuditLogger
{
    /** @var list<AuditEvent> */
    public array $events = [];

    public function record(AuditEvent $event, ?ClientInfo $client = null): void
    {
        $this->events[] = $event;
    }

    /**
     * @return list<string>
     */
    public function actions(): array
    {
        return array_map(static fn(AuditEvent $e): string => $e->action, $this->events);
    }
}
