<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\Research;

use SeoControlCenter\Core\Http\Exception\HttpException;

/**
 * Refuses a paid lookup that repeats one already stored and still fresh
 * (ADR-038, D3): the same kind and subject with the same market, seeds,
 * competitors and other inputs, finished within the window. The caller
 * passes $runAgain once the user has seen the earlier run and still wants
 * to pay for a new one.
 */
final class RepeatedLookup extends HttpException
{
    /** Research results count as fresh for 30 days (opportunity coverage policy). */
    public const int DAYS = 30;

    /** What each paid lookup is called in the warning. */
    public const array LABELS = [
        'ranked' => 'Fetching your ranking keywords', 'gap' => 'This keyword gap lookup', 'ideas' => 'Expanding these seeds', 'suggestions' => 'These keyword ideas',
        'competitors' => 'Finding competitors', 'overview' => 'The competitor overview', 'llm' => 'The AI mentions check', 'llm_prompts' => 'The AI prompts lookup',
        'llm_answers' => 'Sampling these AI answers', 'serp_strength' => 'The competitor strength check',
        'bl_profiles' => 'The backlink profiles lookup', 'bl_domains' => 'The referring domains lookup', 'bl_new' => 'The new backlinks lookup', 'bl_lost' => 'The lost backlinks lookup',
        'bl_anchors' => 'The anchor texts lookup', 'bl_gap' => 'The link gap lookup', 'bl_links' => 'The backlinks lookup', 'bl_lost_links' => 'The lost links lookup',
    ];

    public function __construct(public readonly string $ranOn, string $message)
    {
        parent::__construct(409, $message);
    }

    /**
     * @param array<string, mixed> $params the new lookup's inputs
     * @throws self when the same lookup finished within $days and $runAgain is false
     */
    public static function guard(?ResearchResult $previous, array $params, \DateTimeImmutable $now, bool $runAgain, string $what, int $days = self::DAYS): void
    {
        if ($runAgain || $previous === null || $previous->status !== 'done' || $previous->fetchedAt === null) {
            return;
        }
        if ($previous->fetchedAt < $now->modify('-' . $days . ' days')->format('Y-m-d H:i:s') || self::inputs($previous->params) != self::inputs($params)) {
            return;
        }
        $date = substr($previous->fetchedAt, 0, 10);

        throw new self($date, sprintf('%s already ran on %s with the same settings, and its results are still current (fresh for %d days). Run it again only if you need newer data; it is charged again.', $what, $date, $days));
    }

    /**
     * The inputs that decide what a lookup buys: internal batch and request
     * markers are left out and lists are compared in any order.
     *
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    public static function inputs(array $params): array
    {
        $inputs = [];
        foreach ($params as $key => $value) {
            if (str_starts_with((string) $key, '_') || $key === 'scheduled') {
                continue;
            }
            if (is_array($value) && array_is_list($value)) {
                $value = array_map(static fn(mixed $v): mixed => is_string($v) ? mb_strtolower(trim($v)) : $v, $value);
                sort($value);
            }
            $inputs[(string) $key] = $value;
        }
        ksort($inputs);

        return $inputs;
    }
}
