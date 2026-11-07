<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\Reports;

final readonly class ReportSchedule
{
    public const string WEEKLY = 'weekly';
    public const string MONTHLY = 'monthly';
    public const int MAX_RECIPIENTS = 10;

    /**
     * @param list<string> $recipients
     */
    public function __construct(
        public int $projectId,
        public int $workspaceId,
        public string $frequency,
        public array $recipients,
        public bool $enabled,
        public ?string $lastPeriod,
        public ?string $lastSentAt,
    ) {}
}
