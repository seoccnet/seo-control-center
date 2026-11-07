<?php

declare(strict_types=1);

namespace SeoControlCenter\Core\Logging;

/**
 * Identifiers attached to every log record of the current request/job
 * (request ID, workspace ID, project ID, job ID, provider …).
 *
 * One instance per request or job run; it is set by middleware or the job
 * runner and injected into the logger explicitly (no global state).
 */
final class LogContext
{
    /** @var array<string, string|int> */
    private array $values = [];

    public function set(string $key, string|int $value): void
    {
        $this->values[$key] = $value;
    }

    public function remove(string $key): void
    {
        unset($this->values[$key]);
    }

    /**
     * @return array<string, string|int>
     */
    public function all(): array
    {
        return $this->values;
    }
}
