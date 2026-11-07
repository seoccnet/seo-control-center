<?php

declare(strict_types=1);

namespace SeoControlCenter\Domain\Rankings;

/**
 * Google's AI Overview on a result page, with the sources it cites in the
 * order shown.
 */
final readonly class AiOverview
{
    public const int MAX_REFERENCES = 20;

    /**
     * @param list<AiOverviewReference> $references
     */
    public function __construct(public array $references) {}

    /**
     * 1-based position of the first source on $domain (or its subdomains), or null.
     */
    public function positionOf(string $domain): ?int
    {
        $domain = strtolower($domain);
        foreach ($this->references as $i => $reference) {
            if ($reference->domain === $domain || str_ends_with($reference->domain, '.' . $domain)) {
                return $i + 1;
            }
        }

        return null;
    }

    public function referenceFor(string $domain): ?AiOverviewReference
    {
        $position = $this->positionOf($domain);

        return $position !== null ? $this->references[$position - 1] : null;
    }
}
