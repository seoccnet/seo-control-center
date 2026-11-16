<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\SiteHealth;

/** Reads the TLS certificate a site presents (1.3.0). */
interface CertificateProbe
{
    /**
     * @return array{expires: ?string, issuer: ?string, error: ?string} expires as UTC "Y-m-d H:i:s"
     */
    public function certificate(string $host): array;
}
