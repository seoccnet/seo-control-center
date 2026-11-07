<?php

declare(strict_types=1);

namespace SeoControlCenter\Domain\Integrations;

/**
 * Provider spending of a workspace with a month-end forecast: what was spent
 * today, this month and last month, the pace of the last 14 days, the
 * projected month total at that pace, and how it compares with the budget
 * and the provider account balance. Amounts are USD, days are UTC.
 */
final readonly class SpendForecast
{
    public const int PACE_DAYS = 14;

    /**
     * @param array<string, float> $byCategory this month's spending per category, largest first
     * @param array<string, float> $daily date => spending for the last PACE_DAYS days, oldest first
     */
    public function __construct(
        public float $today,
        public float $month,
        public float $lastMonth,
        public int $requestsMonth,
        public float $dailyPace,
        public float $projectedMonth,
        public int $daysLeft,
        public ApiBudget $budget,
        public ?float $balance,
        public array $byCategory,
        public array $daily,
    ) {}

    /**
     * @param list<array{date: string, operation: string, cost: float, requests: int}> $rows daily spending since the first day of last month
     * @param \Closure(string): string $category operation => category label
     */
    public static function from(array $rows, \DateTimeImmutable $now, ApiBudget $budget, ?float $balance, \Closure $category): self
    {
        $today = $now->format('Y-m-d');
        $monthStart = $now->format('Y-m-01');
        $paceStart = $now->modify('-' . (self::PACE_DAYS - 1) . ' days')->format('Y-m-d');
        $daily = [];
        for ($i = self::PACE_DAYS - 1; $i >= 0; $i--) {
            $daily[$now->modify('-' . $i . ' days')->format('Y-m-d')] = 0.0;
        }
        $spentToday = $month = $lastMonth = 0.0;
        $requests = 0;
        $byCategory = [];
        $firstDay = null;
        foreach ($rows as $row) {
            $firstDay = $firstDay === null || $row['date'] < $firstDay ? $row['date'] : $firstDay;
            if ($row['date'] >= $monthStart) {
                $month += $row['cost'];
                $requests += $row['requests'];
                $label = $category($row['operation']);
                $byCategory[$label] = ($byCategory[$label] ?? 0.0) + $row['cost'];
            } else {
                $lastMonth += $row['cost'];
            }
            if ($row['date'] === $today) {
                $spentToday += $row['cost'];
            }
            if (isset($daily[$row['date']])) {
                $daily[$row['date']] += $row['cost'];
            }
        }
        arsort($byCategory);

        // A new account is measured from its first spending day, not over 14 empty days.
        $paceFrom = $firstDay !== null && $firstDay > $paceStart ? $firstDay : $paceStart;
        $paceDays = max(1, (int) (new \DateTimeImmutable($paceFrom))->diff(new \DateTimeImmutable($today))->days + 1);
        $pace = array_sum($daily) / $paceDays;
        $daysLeft = (int) $now->format('t') - (int) $now->format('j');

        return new self($spentToday, $month, $lastMonth, $requests, $pace, $month + $pace * $daysLeft, $daysLeft, $budget, $balance, $byCategory, $daily);
    }

    /**
     * ok, warn (the forecast or today reaches 80 % of a limit) or over (a limit is reached or will be).
     */
    public function status(): string
    {
        $shares = array_filter([
            $this->budget->monthlyLimit !== null && $this->budget->monthlyLimit > 0 ? $this->projectedMonth / $this->budget->monthlyLimit : null,
            $this->budget->dailyLimit !== null && $this->budget->dailyLimit > 0 ? $this->today / $this->budget->dailyLimit : null,
            $this->balanceDays() !== null && $this->balanceDays() < $this->daysLeft ? 1.0 : null,
        ], static fn(?float $s): bool => $s !== null);
        $max = $shares === [] ? 0.0 : max($shares);

        return $max >= 1.0 ? 'over' : ($max >= 0.8 ? 'warn' : 'ok');
    }

    /**
     * Share of the monthly limit spent so far (0..1+), null without a limit.
     */
    public function monthShare(): ?float
    {
        return $this->budget->monthlyLimit !== null && $this->budget->monthlyLimit > 0 ? $this->month / $this->budget->monthlyLimit : null;
    }

    /**
     * Days until the account balance runs out at the current pace, null when unknown or not spending.
     */
    public function balanceDays(): ?int
    {
        return $this->balance !== null && $this->dailyPace > 0.0001 ? (int) floor($this->balance / $this->dailyPace) : null;
    }
}
