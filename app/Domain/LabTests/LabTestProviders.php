<?php

declare(strict_types=1);

namespace SeoControlCenter\Domain\LabTests;

use SeoControlCenter\Domain\Integrations\IntegrationException;

/**
 * Where lab runs come from (ADR-040, F2): PageSpeed Insights answers in one
 * request; DataForSEO Lighthouse is a paid task that is submitted, then
 * collected later.
 */
interface LabTestProviders
{
    /** @throws IntegrationException */
    public function pageSpeed(string $url, string $device, #[\SensitiveParameter] string $apiKey): LabResult;

    /**
     * Submits a paid Lighthouse task; returns its task ID.
     *
     * @throws IntegrationException
     */
    public function submitPaid(int $workspaceId, int $projectId, string $url, string $device): string;

    /**
     * The task's result, or null while DataForSEO is still testing.
     *
     * @throws IntegrationException
     */
    public function collectPaid(int $workspaceId, string $taskId, string $url, string $device): ?LabResult;
}
