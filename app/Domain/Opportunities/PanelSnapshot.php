<?php

declare(strict_types=1);

namespace SeoControlCenter\Domain\Opportunities;

/** Curated local facts; raw provider text is never an instruction. */
final readonly class PanelSnapshot
{
    /** @param list<array{query: string, url: string, clicks: int, impressions: int, before: ?float, after: ?float}> $splits
     * @param list<array{url: string, verdict: string, coverage: string, date: string, lost: bool, former_clicks: ?int, google_canonical?: string, user_canonical?: string, canonical_mismatch?: bool, rich_errors?: list<string>}> $inspections
     * @param list<array{url: string, inbound: int, words: ?int, noindex: bool, canonical?: ?string}> $auditPages
     * @param array<string, list<string>> $outgoing
     * @param array<string, array{pages: int, clicks: ?int, sessions?: ?int, conversions?: ?float, revenue?: ?float}> $issueTraffic
     * @param list<array{id: string, date: string, url: ?string, title: string}> $annotations
     * @param list<array{id: string, date: string, url: string, fields: list<string>}> $pageChanges
     * @param list<array{id: string, date: string, title: string, url: string, lines: list<string>}> $alerts
     * @param array<string, array{status: string, date: ?string, notice?: string}> $coverage
     * @param array<string, list<string>> $bodyOutgoing links from a page's text (in the text, related cards, or not yet classified), not menus and footers (0.42.0)
     */
    public function __construct(public array $splits = [], public array $inspections = [], public array $auditPages = [], public array $outgoing = [], public array $issueTraffic = [], public array $annotations = [], public array $pageChanges = [], public array $alerts = [], public array $coverage = [], public ?string $projectPublicId = null, public array $bodyOutgoing = []) {}
}
