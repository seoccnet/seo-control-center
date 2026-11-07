<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\Annotations;

final readonly class Annotation
{
    /**
     * @param int $revision edit revision, for optimistic concurrency (MCP `version`)
     * @param string|null $via the AI client token that made the last change, null for the browser (ADR-036)
     */
    public function __construct(public string $publicId, public string $date, public string $kind, public string $title, public string $note, public ?string $url, public string $createdAt, public string $updatedAt, public int $revision = 1, public ?string $via = null) {}

    /** @return array<string, string|int|null> */
    public function present(): array
    {
        return ['annotation_id' => $this->publicId, 'date' => $this->date, 'type' => $this->kind, 'title' => $this->title, 'note' => $this->note, 'url' => $this->url, 'created_at' => $this->createdAt, 'updated_at' => $this->updatedAt, 'version' => $this->revision, 'via' => $this->via];
    }
}
