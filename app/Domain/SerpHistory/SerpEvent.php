<?php

declare(strict_types=1);

namespace SeoControlCenter\Domain\SerpHistory;

/**
 * One stored results-page change of a tracked keyword (ADR-039, E1).
 */
final readonly class SerpEvent
{
    /** @param array<string, mixed> $detail */
    public function __construct(
        public string $keyword,
        public string $keywordPublicId,
        public string $device,
        public string $checkedOn,
        public string $previousOn,
        public string $kind,
        public string $subject,
        public array $detail,
    ) {}

    /** @return array<string, mixed> */
    public function present(): array
    {
        return ['keyword' => $this->keyword, 'keyword_id' => $this->keywordPublicId, 'device' => $this->device, 'checked_on' => $this->checkedOn, 'compared_with' => $this->previousOn, 'kind' => $this->kind, 'subject' => $this->subject, 'detail' => $this->detail, 'summary' => SerpEventText::summary($this)];
    }
}
