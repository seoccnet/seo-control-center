<?php

declare(strict_types=1);

namespace SeoControlCenter\Domain\WebVitals;

interface WebVitalsProvider
{
    public function fetch(string $url, #[\SensitiveParameter] string $apiKey, string $formFactor = 'PHONE'): WebVitalRecord;
}
