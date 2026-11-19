<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\Backlinks;

use SeoControlCenter\Core\Authorization\Permission;
use SeoControlCenter\Core\Http\Exception\HttpException;
use SeoControlCenter\Domain\Accounts\User;
use SeoControlCenter\Domain\Backlinks\PageBacklink;
use SeoControlCenter\Domain\Backlinks\WebMention;
use SeoControlCenter\Domain\Projects\Project;
use SeoControlCenter\Domain\Projects\ProjectService;
use SeoControlCenter\Domain\Shared\ValidationException;
use SeoControlCenter\Modules\Research\ResearchRepository;

/**
 * Link outreach tracker (1.8.0): one list of the sites worth asking for a
 * link — unlinked brand mentions, lost links and links to missing pages,
 * from stored lookups — and how far each request got. A site that is asked
 * shows "link found" once a later backlink lookup lists it as linking.
 */
final readonly class OutreachService
{
    public const int CANDIDATES = 300;
    public const array STATUS_LABELS = ['to_contact' => 'To contact', 'contacted' => 'Contacted', 'won' => 'Won', 'declined' => 'Declined'];
    public const array SOURCE_LABELS = ['mention' => 'Unlinked mention', 'lost' => 'Lost link', 'broken' => 'Link to a missing page', 'manual' => 'Added by hand'];

    public function __construct(
        private OutreachRepository $outreach,
        private ResearchRepository $results,
        private LinkReclamationService $reclamation,
        private DisavowRepository $disavows,
        private ProjectService $projects,
    ) {}

    /**
     * @return array{
     *     canEdit: bool, status: ?string, counts: array<string, int>, linksCheckedAt: ?string,
     *     entries: list<array{domain: string, source: string, pageUrl: ?string, targetUrl: ?string, status: string, note: ?string, addedAt: string, updatedAt: string, linking: bool, found: bool}>,
     *     candidates: list<array{domain: string, source: string, pageUrl: ?string, targetUrl: ?string, rank: ?int, detail: ?string}>
     * }
     */
    public function page(User $user, Project $project, ?string $status): array
    {
        $status = in_array($status, OutreachRepository::STATUSES, true) ? $status : null;
        $domains = $this->results->find($project->id, 'bl_domains');
        $linksAt = $domains?->fetchedAt;
        $linking = [];
        foreach ($domains !== null && $domains->fetchedAt !== null ? $domains->referringDomains() : [] as $domain) {
            $linking[self::site($domain->domain)] = true;
        }
        $links = $this->results->find($project->id, 'bl_links');
        if ($links !== null && $links->fetchedAt !== null) {
            foreach ($links->items as $item) {
                $link = is_array($item) ? PageBacklink::fromArray($item) : null;
                if ($link !== null && $link->lostOn === null) {
                    $linking[self::site((string) parse_url($link->sourceUrl, PHP_URL_HOST))] = true;
                }
            }
            $linksAt = max((string) $linksAt, $links->fetchedAt);
        }

        $counts = array_fill_keys(OutreachRepository::STATUSES, 0);
        $entries = [];
        $listed = [];
        foreach ($this->outreach->entries($project->id) as $entry) {
            ++$counts[$entry['status']];
            $listed[$entry['domain']] = true;
            $isLinking = self::linking($entry['domain'], $linking);
            $entry = [...$entry, 'linking' => $isLinking, 'found' => $isLinking && $entry['status'] === 'contacted' && $linksAt !== null && $linksAt > $entry['updatedAt']];
            if ($status === null || $entry['status'] === $status) {
                $entries[] = $entry;
            }
        }
        $canEdit = $this->projects->can($user, Permission::ProjectsEdit, $project);

        return [
            'canEdit' => $canEdit,
            'status' => $status,
            'counts' => $counts,
            'linksCheckedAt' => $linksAt,
            'entries' => $entries,
            'candidates' => $canEdit ? $this->candidates($user, $project, $listed, $linking) : [],
        ];
    }

    /**
     * Adds a domain (a suggestion or one typed by hand).
     *
     * @throws ValidationException
     */
    public function add(User $user, Project $project, string $domain, string $source, string $pageUrl, string $targetUrl): string
    {
        $this->requireEdit($user, $project);
        $normalized = DisavowService::normalize($domain) ?? throw new ValidationException(['domain' => 'Enter a domain, for example example.com.']);
        $own = DisavowService::normalize($project->primaryDomain);
        if ($own !== null && ($normalized === $own || str_ends_with($normalized, '.' . $own))) {
            throw new ValidationException(['domain' => 'This is your own domain.']);
        }
        if ($this->outreach->count($project->id) >= OutreachRepository::MAX) {
            throw new ValidationException(['domain' => sprintf('The list holds up to %s sites. Remove finished ones first.', number_format(OutreachRepository::MAX))]);
        }
        $source = in_array($source, OutreachRepository::SOURCES, true) ? $source : 'manual';
        if (!$this->outreach->add($project->id, $project->workspaceId, $normalized, $source, self::url($pageUrl), self::url($targetUrl), $user->id)) {
            throw new ValidationException(['domain' => sprintf('%s is already on the list.', $normalized)]);
        }

        return $normalized;
    }

    /** @throws ValidationException */
    public function update(User $user, Project $project, string $domain, string $status, string $note): void
    {
        $this->requireEdit($user, $project);
        if (!in_array($status, OutreachRepository::STATUSES, true)) {
            throw new ValidationException(['status' => 'Choose a status.']);
        }
        $note = trim($note);
        if (mb_strlen($note) > 500) {
            throw new ValidationException(['note' => 'Keep the note under 500 characters.']);
        }
        if (!$this->outreach->update($project->id, $domain, $status, $note === '' ? null : $note)) {
            throw new ValidationException(['domain' => 'This site is not on the list.']);
        }
    }

    public function remove(User $user, Project $project, string $domain): void
    {
        $this->requireEdit($user, $project);
        $this->outreach->remove($project->id, $domain);
    }

    /**
     * @param array<string, true> $listed
     * @param array<string, true> $linking
     * @return list<array{domain: string, source: string, pageUrl: ?string, targetUrl: ?string, rank: ?int, detail: ?string}>
     */
    private function candidates(User $user, Project $project, array $listed, array $linking): array
    {
        $skip = $listed;
        foreach ($this->disavows->domains($project->id) as $row) {
            $skip[$row['domain']] = true;
        }
        $own = DisavowService::normalize($project->primaryDomain);
        $found = [];
        $offer = static function (string $host, string $source, ?string $page, ?string $target, ?int $rank, ?string $detail) use (&$found, $skip, $own): void {
            $domain = DisavowService::normalize($host);
            if ($domain === null || isset($skip[$domain]) || ($own !== null && ($domain === $own || str_ends_with($domain, '.' . $own)))) {
                return;
            }
            if (!isset($found[$domain]) || ($rank ?? -1) > ($found[$domain]['rank'] ?? -1)) {
                $found[$domain] = ['domain' => $domain, 'source' => $source, 'pageUrl' => $page, 'targetUrl' => $target, 'rank' => $rank, 'detail' => $detail];
            }
        };

        $mentions = $this->results->find($project->id, 'bl_mentions');
        if ($mentions !== null && $mentions->fetchedAt !== null) {
            foreach ($mentions->items as $item) {
                $mention = is_array($item) ? WebMention::fromArray($item) : null;
                if ($mention !== null && !self::linking($mention->domain, $linking)) {
                    $offer($mention->domain, 'mention', $mention->url, null, $mention->domainRank, $mention->title);
                }
            }
        }
        $lost = $this->results->find($project->id, 'bl_lost_links');
        if ($lost !== null && $lost->fetchedAt !== null) {
            foreach ($lost->items as $item) {
                $link = is_array($item) ? PageBacklink::fromArray($item) : null;
                $host = $link !== null ? (string) parse_url($link->sourceUrl, PHP_URL_HOST) : '';
                if ($link !== null && $host !== '' && !self::linking($host, $linking)) {
                    $offer($host, 'lost', $link->sourceUrl, $link->targetUrl, $link->domainRank, $link->anchor !== '' ? 'Anchor: ' . $link->anchor : null);
                }
            }
        }
        foreach ($this->reclamation->page($user, $project)['dead'] as $dead) {
            foreach ($dead['sources'] as $link) {
                $offer((string) parse_url($link->sourceUrl, PHP_URL_HOST), 'broken', $link->sourceUrl, $dead['url'], $link->domainRank, 'Points to ' . $dead['url'] . ': ' . $dead['problem']);
            }
        }
        $list = array_values($found);
        usort($list, static fn(array $a, array $b): int => [$b['rank'] ?? -1, $a['domain']] <=> [$a['rank'] ?? -1, $b['domain']]);

        return array_slice($list, 0, self::CANDIDATES);
    }

    /** @param array<string, true> $linking */
    private static function linking(string $domain, array $linking): bool
    {
        $site = self::site($domain);
        // A subdomain counts as its site: blog.example.com linking satisfies example.com.
        for ($host = $site; $host !== '' && str_contains($host, '.'); $host = (string) substr($host, (int) strpos($host, '.') + 1)) {
            if (isset($linking[$host])) {
                return true;
            }
        }

        return false;
    }

    private static function site(string $host): string
    {
        $host = strtolower(trim($host));

        return str_starts_with($host, 'www.') ? substr($host, 4) : $host;
    }

    /** @throws ValidationException */
    private static function url(string $url): ?string
    {
        $url = trim($url);
        if ($url === '') {
            return null;
        }
        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
        if (!in_array($scheme, ['http', 'https'], true) || strlen($url) > 2048 || parse_url($url, PHP_URL_HOST) === null) {
            throw new ValidationException(['url' => 'Addresses must start with http:// or https://.']);
        }

        return $url;
    }

    private function requireEdit(User $user, Project $project): void
    {
        if (!$this->projects->can($user, Permission::ProjectsEdit, $project)) {
            throw new HttpException(403, 'You cannot change the outreach list of this project.');
        }
    }
}
