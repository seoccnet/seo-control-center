<?php

declare(strict_types=1);

namespace SeoControlCenter\Domain\WebVitals;

final readonly class WebVitalRecord
{
    public function __construct(
        public string $url,
        public ?float $lcp,
        public ?float $inp,
        public ?float $cls,
        public ?string $periodFrom,
        public ?string $periodTo,
        public string $fetchedAt,
        public string $status = 'available',
        public string $formFactor = 'PHONE',
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return ['url' => $this->url, 'form_factor' => $this->formFactor, 'lcp_ms' => $this->lcp, 'inp_ms' => $this->inp, 'cls' => $this->cls,
            'period_from' => $this->periodFrom, 'period_to' => $this->periodTo, 'fetched_at' => $this->fetchedAt, 'status' => $this->status];
    }

    /** @return list<string> */
    public function needsWork(): array
    {
        $result = [];
        foreach (['LCP' => [$this->lcp, 2500], 'INP' => [$this->inp, 200], 'CLS' => [$this->cls, 0.1]] as $name => [$value, $good]) {
            if ($this->status === 'available' && $value !== null && $value > $good) {
                $result[] = $name;
            }
        }
        return $result;
    }
}
