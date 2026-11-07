<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\ContentBriefs;

final readonly class ContentBrief
{
    /**
     * @param list<string> $evidence
     * @param string|null $publishedAt when the brief was first marked Published (UTC), null while not published
     * @param string|null $via the AI client token that made the last change, null for the browser (ADR-036)
     */
    public function __construct(public string $publicId, public BriefInput $input, public array $evidence, public int $revision, public string $createdAt, public string $updatedAt, public ?string $publishedAt = null, public ?string $via = null) {}

    /** @return array<string, mixed> */
    public function present(): array
    {
        return ['id' => $this->publicId, ...$this->input->form(), 'evidence' => $this->evidence, 'revision' => $this->revision, 'version' => $this->revision, 'via' => $this->via, 'created_at' => $this->createdAt, 'updated_at' => $this->updatedAt, 'published_at' => $this->publishedAt];
    }
}
