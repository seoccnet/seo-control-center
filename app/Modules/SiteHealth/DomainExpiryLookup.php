<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\SiteHealth;

/** A domain's registration expiry (1.3.0). */
interface DomainExpiryLookup
{
    /**
     * @return array{expires: ?string, registrar: ?string, error: ?string} expires as UTC "Y-m-d H:i:s"
     */
    public function expiry(string $domain): array;
}
