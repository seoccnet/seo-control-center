<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\Annotations;

use DateTimeImmutable;

/**
 * When a recorded change can be expected to show in search data, and how
 * far along it is: the typical signal window per change type (search
 * engines must recrawl and reassess pages first), the date of the full
 * 28-day before/after comparison and the current phase. The windows are
 * planning guidance from common experience, not a prediction for this site;
 * measured results come from ChangeImpactService.
 */
final readonly class ImpactWindow
{
    /** Days after the change: [signal from, signal to, metric to watch, why it takes time]. */
    public const array KINDS = [
        'title' => [7, 21, 'CTR and clicks', 'A new title or snippet appears after Google recrawls the page; CTR usually moves before rankings do.'],
        'content' => [14, 42, 'Impressions and average position', 'Updated content is reassessed after a recrawl; ranking movement often takes several weeks.'],
        'links' => [14, 56, 'Position of the linked pages', 'Internal links count once both pages have been recrawled and reprocessed.'],
        'technical' => [3, 28, 'Indexed pages, impressions and errors', 'Crawling and indexing fixes show as Google recrawls the affected URLs.'],
        'release' => [7, 56, 'Site-wide clicks and impressions', 'A release touches many URLs; effects spread as they are recrawled.'],
        'other' => [14, 42, 'Clicks and impressions', 'Most changes need a recrawl and a few weeks of data before a comparison is meaningful.'],
        'new_page' => [28, 90, 'Impressions for the target keyword', 'A new page must be discovered, indexed and earn rankings; this usually takes one to three months.'],
    ];

    /** The default before/after comparison (ChangeImpactService) needs 28 full days after the change day. */
    public const int COMPARISON_DAYS = 28;
    /** After this, the change is history: the countdown stops. */
    public const int SETTLED_DAYS = 120;

    public const string WAITING = 'waiting';
    public const string SIGNAL = 'signal';
    public const string READY = 'ready';
    public const string SETTLED = 'settled';

    public int $elapsed;
    public int $signalFrom;
    public int $signalTo;
    public string $watch;
    public string $reason;
    /** Day on which the full comparison and the signal window have both passed. */
    public int $horizon;
    public string $comparisonDate;
    public string $phase;

    public function __construct(public string $kind, public string $date, string $today)
    {
        [$this->signalFrom, $this->signalTo, $this->watch, $this->reason] = self::KINDS[$kind] ?? self::KINDS['other'];
        $start = new DateTimeImmutable($date);
        $this->elapsed = max(0, (int) $start->diff(new DateTimeImmutable($today))->format('%r%a'));
        $this->horizon = max($this->signalTo, self::COMPARISON_DAYS + 1);
        $this->comparisonDate = $start->modify('+' . (self::COMPARISON_DAYS + 1) . ' days')->format('Y-m-d');
        $this->phase = match (true) {
            $this->elapsed < $this->signalFrom => self::WAITING,
            $this->elapsed < $this->horizon => self::SIGNAL,
            $this->elapsed < self::SETTLED_DAYS => self::READY,
            default => self::SETTLED,
        };
    }

    public function label(): string
    {
        return match ($this->phase) {
            self::WAITING => 'Waiting for recrawl',
            self::SIGNAL => 'In the effect window',
            self::READY => 'Ready to review',
            default => 'Settled',
        };
    }

    /** Days until the horizon (0 once it has passed). */
    public function daysLeft(): int
    {
        return max(0, $this->horizon - $this->elapsed);
    }

    /** Days until the signal window opens (0 once it has). */
    public function daysToSignal(): int
    {
        return max(0, $this->signalFrom - $this->elapsed);
    }

    public function comparisonReady(): bool
    {
        return $this->elapsed > self::COMPARISON_DAYS;
    }

    /**
     * Positions on a 0-100 track for the timeline graphic: the signal window and today.
     *
     * @return array{signal_from: float, signal_to: float, today: float}
     */
    public function track(): array
    {
        $scale = static fn(int $day, int $horizon): float => round(min(100, $day / max(1, $horizon) * 100), 1);

        return ['signal_from' => $scale($this->signalFrom, $this->horizon), 'signal_to' => $scale($this->signalTo, $this->horizon), 'today' => $scale($this->elapsed, $this->horizon)];
    }
}
