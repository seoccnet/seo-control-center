<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\Mcp\Tools;

use SeoControlCenter\Domain\Backlinks\AnchorText;
use SeoControlCenter\Domain\Backlinks\BacklinkProfile;
use SeoControlCenter\Domain\Backlinks\ReferringDomain;
use SeoControlCenter\Domain\Mcp\McpScope;
use SeoControlCenter\Modules\Backlinks\BacklinkHistory;
use SeoControlCenter\Modules\Mcp\Server\McpTool;
use SeoControlCenter\Modules\Mcp\Server\ToolContext;
use SeoControlCenter\Modules\Research\ResearchRepository;

final readonly class BacklinksTool implements McpTool
{
    private const array KINDS = ['profiles', 'domains', 'new', 'lost', 'anchors', 'gap', 'links', 'lost_links'];

    public function __construct(
        private ResearchRepository $research,
        private BacklinkHistory $history,
    ) {}

    public function name(): string
    {
        return 'backlinks';
    }

    public function title(): string
    {
        return 'Backlinks';
    }

    public function description(): string
    {
        return 'Stored backlink data of the project from the DataForSEO link index: `profiles` (domain rank 0-100, backlinks, referring domains, nofollow domains and spam score of the project and its competitors, with the monthly history), `domains` (referring domains), `new` and `lost` (referring domains gained or lost in the last 30 days), `anchors` (anchor texts) and `gap` (domains linking to competitors but not to the project; `competitors` is how many of them they link to). Returns the last stored lookup and when it was fetched; it starts no paid lookup.';
    }

    public function scope(): McpScope
    {
        return McpScope::Competitors;
    }

    public function inputSchema(): array
    {
        return Present::projectSchema([
            'kind' => ['type' => 'string', 'enum' => self::KINDS, 'default' => 'profiles'],
            'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 500, 'default' => 100],
            'offset' => ['type' => 'integer', 'minimum' => 0, 'maximum' => 100000, 'default' => 0],
            'url' => ['type' => 'string', 'description' => 'Exact target URL for links/lost_links; filters the stored sample.'],
        ]);
    }

    public function call(ToolContext $context, array $arguments): array
    {
        $project = $context->project($arguments);
        $kind = ToolContext::choice($arguments, 'kind', 'profiles', self::KINDS);
        $result = $this->research->find($project->id, 'bl_' . $kind);
        if ($result === null || $result->fetchedAt === null) {
            return ['project_id' => $project->publicId, 'kind' => $kind, 'fetched_at' => null, 'note' => 'Not looked up yet. Lookups are started in the app (Backlinks).'];
        }
        $limit = ToolContext::int($arguments, 'limit', 100, 1, 500);
        $offset = ToolContext::int($arguments, 'offset', 0, 0, 100000);
        $base = ['project_id' => $project->publicId, 'kind' => $kind, 'fetched_at' => $result->fetchedAt, 'offset' => $offset, 'note' => 'Rows cover only the last stored provider sample; not a complete live link index.'];
        if ($kind === 'profiles') {
            return [...$base, 'profiles' => array_map(static fn(BacklinkProfile $p): array => [
                'domain' => $p->domain,
                'rank' => $p->rank,
                'backlinks' => $p->backlinks,
                'referring_domains' => $p->referringDomains,
                'nofollow_domains' => $p->nofollowDomains,
                'spam_score' => $p->spamScore,
                'broken_backlinks' => $p->brokenBacklinks,
                'first_seen' => $p->firstSeen,
            ], $result->backlinkProfiles()), 'history' => array_map(static fn(array $check): array => [
                'date' => $check['date'],
                'profiles' => array_values(array_map(static fn(BacklinkProfile $p): array => [
                    'domain' => $p->domain,
                    'rank' => $p->rank,
                    'backlinks' => $p->backlinks,
                    'referring_domains' => $p->referringDomains,
                ], $check['profiles'])),
            ], $this->history->history($project->id, 12))];
        }
        if ($kind === 'anchors') {
            $anchors = $result->anchors();

            return [...$base, 'total' => count($anchors), 'next_offset' => $offset + $limit < count($anchors) ? $offset + $limit : null, 'anchors' => array_map(static fn(AnchorText $a): array => [
                'anchor' => $a->anchor,
                'backlinks' => $a->backlinks,
                'referring_domains' => $a->referringDomains,
            ], array_slice($anchors, $offset, $limit))];
        }
        if (in_array($kind, ['links', 'lost_links'], true)) {
            $links = $result->pageLinks();
            if (isset($arguments['url'])) {
                $url = ReadInput::url($arguments);
                $links = array_values(array_filter($links, static fn(\SeoControlCenter\Domain\Backlinks\PageBacklink $l): bool => $l->targetUrl === $url));
            }
            return [...$base, 'total' => count($links), 'sample_limit' => 1000,
                'next_offset' => $offset + $limit < count($links) ? $offset + $limit : null,
                'links' => array_map(static fn(\SeoControlCenter\Domain\Backlinks\PageBacklink $l): array => $l->toArray(), array_slice($links, $offset, $limit))];
        }
        $domains = $result->referringDomains();

        return [...$base, 'total' => count($domains), 'next_offset' => $offset + $limit < count($domains) ? $offset + $limit : null, 'domains' => array_map(static fn(ReferringDomain $d): array => array_filter([
            'domain' => $d->domain,
            'rank' => $d->rank,
            'backlinks' => $d->backlinks,
            'spam_score' => $d->spamScore,
            'first_seen' => $d->firstSeen,
            'lost_date' => $d->lostDate,
            'competitors' => $d->targets,
        ], static fn(mixed $v): bool => $v !== null), array_slice($domains, $offset, $limit))];
    }
}
