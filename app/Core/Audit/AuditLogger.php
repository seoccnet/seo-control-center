<?php

declare(strict_types=1);

namespace SeoControlCenter\Core\Audit;

use SeoControlCenter\Core\Support\ClientInfo;

interface AuditLogger
{
    public function record(AuditEvent $event, ?ClientInfo $client = null): void;
}
