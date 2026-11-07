<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\Opportunities\Tasks;

final readonly class SeoTask
{
    /** @param string|null $via the AI client token that made the last change, null for the browser (ADR-036) */
    public function __construct(public string $opportunityId, public string $title, public float $priority, public string $findingStatus, public TaskInput $input, public int $revision, public string $updatedAt, public ?string $via = null) {}
}
