<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\Indexing;

use SeoControlCenter\Domain\Integrations\Connection;

interface InspectionProvider
{
    public function inspect(Connection $connection, string $property, string $url): Inspection;
}
