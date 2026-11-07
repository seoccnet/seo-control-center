<?php

declare(strict_types=1);

namespace SeoControlCenter\Domain\Shared;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

/**
 * An inclusive range of calendar days (no time of day, no time zone).
 */
final readonly class DateRange
{
    public DateTimeImmutable $from;
    public DateTimeImmutable $to;

    public function __construct(string $from, string $to)
    {
        $this->from = self::parse($from);
        $this->to = self::parse($to);
        if ($this->from > $this->to) {
            throw new InvalidArgumentException('A date range must not end before it starts.');
        }
    }

    /**
     * The $days days ending on $end (inclusive).
     */
    public static function endingOn(string $end, int $days): self
    {
        if ($days < 1) {
            throw new InvalidArgumentException('A date range covers at least one day.');
        }
        $to = self::parse($end);

        return new self($to->modify(sprintf('-%d days', $days - 1))->format('Y-m-d'), $to->format('Y-m-d'));
    }

    public function fromString(): string
    {
        return $this->from->format('Y-m-d');
    }

    public function toString(): string
    {
        return $this->to->format('Y-m-d');
    }

    public function days(): int
    {
        return (int) $this->from->diff($this->to)->days + 1;
    }

    /**
     * The range of the same length directly before this one.
     */
    public function previous(): self
    {
        return self::endingOn($this->from->modify('-1 day')->format('Y-m-d'), $this->days());
    }

    public function contains(string $date): bool
    {
        $d = self::parse($date);

        return $d >= $this->from && $d <= $this->to;
    }

    /**
     * @return list<string> every day in the range, oldest first
     */
    public function eachDay(): array
    {
        $days = [];
        for ($d = $this->from; $d <= $this->to; $d = $d->modify('+1 day')) {
            $days[] = $d->format('Y-m-d');
        }

        return $days;
    }

    private static function parse(string $date): DateTimeImmutable
    {
        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date, new DateTimeZone('UTC'));
        if ($parsed === false || $parsed->format('Y-m-d') !== $date) {
            throw new InvalidArgumentException(sprintf('Invalid date "%s"; expected YYYY-MM-DD.', $date));
        }

        return $parsed;
    }
}
