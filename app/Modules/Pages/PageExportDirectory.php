<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\Pages;

/** Where queued export files are written: storage/tmp/page-exports, outside the web root. */
final readonly class PageExportDirectory
{
    public function __construct(public string $path) {}
}
