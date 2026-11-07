<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\Indexing;

final readonly class InspectionRecord
{
    public function __construct(public string $url, public bool $watched, public ?Inspection $result, public ?string $checkedAt, public ?string $attemptedAt, public ?string $error) {}

    public function status(string $staleBefore): string
    {
        if ($this->error !== null) {
            return 'error';
        }
        if ($this->result === null) {
            return 'unchecked';
        }
        if (($this->checkedAt ?? '') < $staleBefore) {
            return 'stale';
        }
        return $this->result->verdict === 'PASS' ? 'indexed' : ($this->result->verdict === 'UNKNOWN' ? 'unknown' : 'problem');
    }

    /** @return array<string, mixed> */
    public function present(string $staleBefore): array
    {
        return ['url' => $this->url, 'watched' => $this->watched, 'status' => $this->status($staleBefore), 'checked_at' => $this->checkedAt, 'last_attempt_at' => $this->attemptedAt, 'error' => $this->error, 'result' => $this->result?->present()];
    }
}
