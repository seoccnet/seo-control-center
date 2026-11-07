<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\Alerts\Jobs;

use SeoControlCenter\Core\Queue\JobContext;
use SeoControlCenter\Core\Queue\JobHandler;
use SeoControlCenter\Core\Queue\PermanentJobException;
use SeoControlCenter\Modules\Alerts\WebhookService;

final readonly class SendWebhookJob implements JobHandler
{
    public function __construct(private WebhookService $service) {}

    public function handle(array $payload, JobContext $context): void
    {
        if (!is_int($payload['alert'] ?? null) || !is_string($payload['version'] ?? null)) {
            throw new PermanentJobException('Invalid webhook payload.');
        }
        $this->service->deliver($payload['alert'], $payload['version']);
    }
}
