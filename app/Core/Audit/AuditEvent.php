<?php

declare(strict_types=1);

namespace SeoControlCenter\Core\Audit;

/**
 * One audit record: who did what, to what, with which outcome.
 * Never put secrets into metadata (they are redacted anyway).
 */
final readonly class AuditEvent
{
    /**
     * @param array<string, mixed> $metadata
     */
    public function __construct(
        public string $action,
        public ActorType $actorType,
        public ?int $actorUserId = null,
        public ?string $actorLabel = null,
        public ?int $workspaceId = null,
        public ?string $targetType = null,
        public ?string $targetId = null,
        public bool $success = true,
        public array $metadata = [],
    ) {
        if (preg_match('/^[a-z][a-z0-9_]*(\.[a-z][a-z0-9_]*)+$/D', $action) !== 1) {
            throw new \InvalidArgumentException(sprintf('Audit action "%s" must look like "domain.action".', $action));
        }
    }
}
