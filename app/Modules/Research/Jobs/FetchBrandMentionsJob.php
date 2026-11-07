<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\Research\Jobs;

use SeoControlCenter\Core\Queue\JobContext;
use SeoControlCenter\Core\Queue\JobHandler;
use SeoControlCenter\Core\Queue\PermanentJobException;
use SeoControlCenter\Modules\Research\BrandMentionService;

final readonly class FetchBrandMentionsJob implements JobHandler
{
    public function __construct(private BrandMentionService $service) {}
    public function handle(array $payload, JobContext $context): void
    {
        if (!is_int($payload['workspace_id'] ?? null) || !is_string($payload['project'] ?? null) || !is_string($payload['check'] ?? null) || !is_int($payload['step'] ?? null) || $payload['step'] < 0 || $payload['step'] > 3) {
            throw new PermanentJobException('Invalid brand check payload.');
        }
        $this->service->run($payload['workspace_id'], $payload['project'], $payload['check'], $payload['step']);
    }
}
