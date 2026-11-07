<?php

declare(strict_types=1);

namespace SeoControlCenter\Core\Queue;

use DateTimeImmutable;
use InvalidArgumentException;

/**
 * Standard 5-field cron expression (minute hour day-of-month month day-of-week),
 * evaluated in UTC. Supports *, lists (1,2), ranges (1-5) and steps (*\/15, 0-30/5).
 * When both day fields are restricted, a match on either suffices (cron semantics).
 */
final readonly class CronExpression
{
    private const array BOUNDS = [[0, 59], [0, 23], [1, 31], [1, 12], [0, 7]];

    /** @var list<list<int>> */
    private array $fields;

    private bool $domRestricted;

    private bool $dowRestricted;

    public function __construct(public string $expression)
    {
        $parts = preg_split('/\s+/', trim($expression)) ?: [];
        if (count($parts) !== 5) {
            throw new InvalidArgumentException(sprintf('Cron expression "%s" must have 5 fields.', $expression));
        }
        $fields = [];
        foreach ($parts as $i => $part) {
            $fields[] = self::parseField($part, self::BOUNDS[$i][0], self::BOUNDS[$i][1]);
        }
        // Day of week 7 = Sunday = 0.
        $fields[4] = array_values(array_unique(array_map(static fn(int $d): int => $d % 7, $fields[4])));
        $this->fields = $fields;
        $this->domRestricted = $parts[2] !== '*';
        $this->dowRestricted = $parts[4] !== '*';
    }

    public function isDue(DateTimeImmutable $time): bool
    {
        $minute = (int) $time->format('i');
        $hour = (int) $time->format('G');
        $dom = (int) $time->format('j');
        $month = (int) $time->format('n');
        $dow = (int) $time->format('w');

        if (!in_array($minute, $this->fields[0], true) || !in_array($hour, $this->fields[1], true) || !in_array($month, $this->fields[3], true)) {
            return false;
        }
        $domMatch = in_array($dom, $this->fields[2], true);
        $dowMatch = in_array($dow, $this->fields[4], true);

        return $this->domRestricted && $this->dowRestricted ? ($domMatch || $dowMatch) : ($domMatch && $dowMatch);
    }

    /**
     * @return list<int>
     */
    private static function parseField(string $field, int $min, int $max): array
    {
        $values = [];
        foreach (explode(',', $field) as $item) {
            $step = 1;
            if (str_contains($item, '/')) {
                [$item, $stepText] = explode('/', $item, 2);
                if (preg_match('/^\d+$/D', $stepText) !== 1 || (int) $stepText < 1) {
                    throw new InvalidArgumentException(sprintf('Invalid step in cron field "%s".', $field));
                }
                $step = (int) $stepText;
            }
            if ($item === '*') {
                [$from, $to] = [$min, $max];
            } elseif (preg_match('/^(\d+)-(\d+)$/D', $item, $m) === 1) {
                [$from, $to] = [(int) $m[1], (int) $m[2]];
            } elseif (preg_match('/^\d+$/D', $item) === 1) {
                $from = (int) $item;
                $to = $step > 1 ? $max : $from;
            } else {
                throw new InvalidArgumentException(sprintf('Invalid cron field "%s".', $field));
            }
            if ($from < $min || $to > $max || $from > $to) {
                throw new InvalidArgumentException(sprintf('Cron field "%s" is out of range %d-%d.', $field, $min, $max));
            }
            for ($v = $from; $v <= $to; $v += $step) {
                $values[] = $v;
            }
        }
        $values = array_values(array_unique($values));
        sort($values);

        return $values;
    }
}
