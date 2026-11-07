<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\ChangeBundles;

/** Reads the facts of one live page that a bundle guards and verifies. */
interface LivePageReader
{
    /**
     * @return array<string, int|string|bool|null>|string the page facts, or why the page could not be read
     */
    public function read(string $url): array|string;
}
