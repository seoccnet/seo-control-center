<?php

declare(strict_types=1);

namespace SeoControlCenter\Core\Http\Client;

final readonly class HttpResponse
{
    /**
     * @param array<string, string> $headers lower-case name => value (the last one when repeated)
     * @param int|null $durationMs total time of the request, when measured
     * @param bool $truncated the body was cut off at the caller's byte limit
     */
    public function __construct(
        public int $status,
        public string $body,
        public ?int $retryAfterSeconds = null,
        public array $headers = [],
        public ?int $durationMs = null,
        public bool $truncated = false,
    ) {}

    public function header(string $name): ?string
    {
        return $this->headers[strtolower($name)] ?? null;
    }

    /**
     * @return array<array-key, mixed>|null
     */
    public function json(): ?array
    {
        $data = json_decode($this->body, true, 64);

        return is_array($data) ? $data : null;
    }

    public function isSuccess(): bool
    {
        return $this->status >= 200 && $this->status < 300;
    }
}
