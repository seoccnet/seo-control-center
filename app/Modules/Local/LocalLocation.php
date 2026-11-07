<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\Local;

use SeoControlCenter\Domain\Local\LocalArea;

final readonly class LocalLocation
{
    /** @param list<string> $keywords */
    public function __construct(public string $publicId, public int $projectId, public string $name, public string $cid, public LocalArea $area, public string $language, public array $keywords, public bool $weekly, public ?string $checkedOn = null) {}
}
