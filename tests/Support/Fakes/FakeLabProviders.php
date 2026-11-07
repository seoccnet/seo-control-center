<?php

declare(strict_types=1);

namespace SeoControlCenter\Tests\Support\Fakes;

use SeoControlCenter\Domain\Integrations\IntegrationException;
use SeoControlCenter\Domain\LabTests\LabResult;
use SeoControlCenter\Domain\LabTests\LabTestProviders;

/** Scripted lab providers: a result or an exception per call, and the calls made. */
final class FakeLabProviders implements LabTestProviders
{
    /** @var list<LabResult|IntegrationException> */
    public array $pageSpeed = [];
    /** @var list<LabResult|IntegrationException|null> */
    public array $collect = [];
    /** @var list<string> */
    public array $calls = [];

    public function pageSpeed(string $url, string $device, #[\SensitiveParameter] string $apiKey): LabResult
    {
        $this->calls[] = 'pagespeed:' . $device . ':' . $url;
        $next = array_shift($this->pageSpeed) ?? new LabResult(0.9, 1800.0, 0.02, 120.0, 900.0, 1500.0, '12.0.0');

        return $next instanceof IntegrationException ? throw $next : $next;
    }

    public function submitPaid(int $workspaceId, int $projectId, string $url, string $device): string
    {
        $this->calls[] = 'submit:' . $device . ':' . $url;

        return '01234567-89ab-cdef-0123-456789abcdef';
    }

    public function collectPaid(int $workspaceId, string $taskId, string $url, string $device): ?LabResult
    {
        $this->calls[] = 'collect:' . $taskId;
        $next = array_shift($this->collect);

        return $next instanceof IntegrationException ? throw $next : $next;
    }
}
