<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\Local;

use SeoControlCenter\Domain\Local\LocalGrid;

final readonly class BranchGrid
{
    public function __construct(public string $publicId, public string $keyword, public LocalGrid $grid, public ?string $error = null) {}

    public function withPoint(int $index, ?int $position): self
    {
        $g = $this->grid;
        return new self($this->publicId, $this->keyword, new LocalGrid(0, $g->checkedOn, $g->size, $g->spacing, $g->center, array_replace($g->positions, [$index => $position]), $g->status));
    }
    public function withStatus(string $status, ?string $error = null): self
    {
        $g = $this->grid;
        return new self($this->publicId, $this->keyword, new LocalGrid(0, $g->checkedOn, $g->size, $g->spacing, $g->center, $g->positions, $status), $error);
    }
}
