<?php

declare(strict_types=1);

namespace SeoControlCenter\Domain\Opportunities;

/**
 * Other changes inside an outcome's measurement window (ADR-039, E2): change
 * notes (site-wide or for the same page), other completed opportunities for
 * the same search or page, and later detected changes of the same page.
 * They do not invalidate the outcome; they say it may not be the only cause.
 */
final class OutcomeOverlaps
{
    public const int VERIFY_DAYS = 14;
    public const int BEFORE_DAYS = 7;
    public const int MAX = 10;

    /**
     * @param list<array{id: string, date: string, title: string, url: ?string}> $notes
     * @param list<OpportunityOutcome> $completions other completions of the project in the window
     * @param list<array{public_id: string, detected_at: string, fields: list<string>}> $pageChanges
     * @return list<array{kind: string, label: string, date: string, ref: ?string}>
     */
    public static function find(OpportunityOutcome $row, array $notes, array $completions, array $pageChanges): array
    {
        $out = [];
        foreach ($notes as $note) {
            if ($note['id'] === $row->annotationId) {
                continue;
            }
            $out[] = ['kind' => 'note', 'label' => ($note['url'] === null ? 'Site-wide change: ' : 'Change on this page: ') . $note['title'], 'date' => $note['date'], 'ref' => $note['id']];
        }
        foreach ($completions as $other) {
            if ($other->opportunityId === $row->opportunityId) {
                continue;
            }
            if ($other->subject === $row->subject || ($row->pageUrl !== null && $other->pageUrl !== null && OpportunityConsolidator::pageKey($other->pageUrl) === OpportunityConsolidator::pageKey($row->pageUrl))) {
                $out[] = ['kind' => 'completion', 'label' => 'Also completed: ' . str_replace('_', ' ', $other->type) . ' for ' . self::subject($other->subject), 'date' => $other->completedOn, 'ref' => $other->opportunityId];
            }
        }
        $verifiedUntil = (new \DateTimeImmutable($row->completedOn))->modify('+' . self::VERIFY_DAYS . ' days')->format('Y-m-d');
        foreach ($pageChanges as $change) {
            if (substr($change['detected_at'], 0, 10) > $verifiedUntil) {
                $out[] = ['kind' => 'page_change', 'label' => 'Later change detected on the page' . ($change['fields'] !== [] ? ': ' . implode(', ', $change['fields']) : ''), 'date' => substr($change['detected_at'], 0, 10), 'ref' => $change['public_id']];
            }
        }
        usort($out, static fn(array $a, array $b): int => [$a['date'], $a['label']] <=> [$b['date'], $b['label']]);

        return array_slice($out, 0, self::MAX);
    }

    private static function subject(string $subject): string
    {
        return (string) preg_replace('/^(query|page|finding):/', '', $subject);
    }
}
