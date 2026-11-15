<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\SiteMigration;

/**
 * One guarded request for a redirect test (1.2.0): the status and Location
 * an address answers with, never following redirects.
 */
interface UrlProbe
{
    /**
     * @param list<string> $hosts the hosts the plan may reach
     * @return array{status: ?int, location: ?string, error: ?string, skipped?: bool}
     */
    public function probe(string $url, array $hosts): array;
}
