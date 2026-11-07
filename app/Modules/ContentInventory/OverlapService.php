<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\ContentInventory;

use SeoControlCenter\Core\Audit\ActorType;
use SeoControlCenter\Core\Audit\AuditEvent;
use SeoControlCenter\Core\Audit\AuditLogger;
use SeoControlCenter\Core\Authorization\Authorizer;
use SeoControlCenter\Core\Authorization\Permission;
use SeoControlCenter\Core\Http\Exception\HttpException;
use SeoControlCenter\Core\Http\Exception\NotFoundHttpException;
use SeoControlCenter\Core\Support\ClientInfo;
use SeoControlCenter\Domain\Accounts\User;
use SeoControlCenter\Domain\ContentInventory\Inventory;
use SeoControlCenter\Domain\ContentInventory\OverlapFinder;
use SeoControlCenter\Domain\ContentInventory\UrlKey;
use SeoControlCenter\Domain\Projects\Project;

/**
 * Content overlap review (ADR-042, 0.36.0): candidates with their evidence
 * and uncertainty, a merge preview for pages (what each page is and gets,
 * canonicals, redirects, topic ownership and a proposed retained address),
 * and a person's decision. SEOcc never redirects or changes content: a
 * planned merge is an approval to record, carried out in your CMS.
 *
 * @phpstan-import-type Review from OverlapReviewRepository
 */
final readonly class OverlapService
{
    public const array DECISIONS = ['undecided' => 'Undecided', 'keep_separate' => 'Keep separate', 'merge_planned' => 'Merge planned'];
    public const int MAX_PAGES = 2000;

    /**
     * @param ?\Closure(Project): list<array{key: string, main_keyword: string, kind: string}> $ideas article ideas
     * @param ?\Closure(Project): list<array{query: string, kind: string, harmful: ?bool, note: string, pages: list<array{url: string, share: float, position: float}>}> $queryOverlaps searches shown by several pages (Topics)
     * @param ?\Closure(Project): array<string, list<string>> $owners page URL => topics it owns
     */
    public function __construct(
        private OverlapReviewRepository $reviews,
        private ContentInventoryService $inventory,
        private Authorizer $auth,
        private AuditLogger $audit,
        private ?\Closure $ideas = null,
        private ?\Closure $queryOverlaps = null,
        private ?\Closure $owners = null,
    ) {}

    /** @return array{audit_at: ?string, candidates: list<array<string, mixed>>, counts: array<string, int>, shown: array<string, int>, open: int, can_edit: bool} */
    public function overview(User $user, Project $project, string $kind = ''): array
    {
        $this->auth->authorize($user->subject(), Permission::ProjectsView, $project->workspaceId, $project->id);
        $texts = $this->reviews->pageTexts($project->id, self::MAX_PAGES);
        $result = OverlapFinder::find($texts['pages'], $this->queryOverlaps !== null ? ($this->queryOverlaps)($project) : [], $this->ideas !== null ? ($this->ideas)($project) : []);
        $found = $result['candidates'];
        $reviews = $this->reviews->reviews($project->id);
        $rows = $this->inventory->rowsByKey($project);
        $owners = $this->owners !== null ? ($this->owners)($project) : [];
        $counts = array_merge(array_fill_keys(array_keys(OverlapFinder::KINDS), 0), $result['totals']);
        $shown = array_fill_keys(array_keys(OverlapFinder::KINDS), 0);
        $candidates = [];
        foreach ($found as $candidate) {
            $shown[$candidate['kind']]++;
            if ($kind !== '' && $candidate['kind'] !== $kind) {
                continue;
            }
            $preview = $candidate['kind'] === OverlapFinder::SYNONYM_IDEAS ? null : $this->preview($candidate['members'], $rows, $owners);
            $candidates[] = [...$candidate, 'review' => $reviews[$candidate['key']] ?? null, 'preview' => $preview];
        }

        return [
            'audit_at' => $texts['audit_at'],
            'candidates' => $candidates,
            'counts' => $counts,
            'shown' => $shown,
            'open' => count(array_filter($found, static fn(array $c): bool => !isset($reviews[$c['key']]) || $reviews[$c['key']]['decision'] === 'undecided')),
            'can_edit' => $this->auth->can($user->subject(), Permission::ProjectsEdit, $project->workspaceId, $project->id),
        ];
    }

    /** Records a person's decision on a current candidate; a planned merge needs the retained address. */
    public function decide(User $user, Project $project, string $key, string $decision, ?string $retained, string $note, int $version, ClientInfo $client): void
    {
        $this->auth->authorize($user->subject(), Permission::ProjectsEdit, $project->workspaceId, $project->id);
        if (!isset(self::DECISIONS[$decision])) {
            throw new HttpException(422, 'Choose keep separate, merge planned or undecided.');
        }
        if (mb_strlen($note) > 2000) {
            throw new HttpException(422, 'Keep the note to 2,000 characters.');
        }
        $candidate = null;
        foreach (OverlapFinder::find($this->reviews->pageTexts($project->id, self::MAX_PAGES)['pages'], $this->queryOverlaps !== null ? ($this->queryOverlaps)($project) : [], $this->ideas !== null ? ($this->ideas)($project) : [])['candidates'] as $found) {
            if (hash_equals($found['key'], $key)) {
                $candidate = $found;
                break;
            }
        }
        if ($candidate === null) {
            throw new NotFoundHttpException('This overlap is no longer found in the current data.');
        }
        if ($decision === 'merge_planned') {
            if ($retained === null || !in_array($retained, $candidate['members'], true)) {
                throw new HttpException(422, 'Choose which of the pages or ideas to keep.');
            }
        } else {
            $retained = null;
        }
        if (!$this->reviews->decide($project->workspaceId, $project->id, $key, $candidate['kind'], $candidate['members'], $decision, $retained, trim($note), $version, $user->id)) {
            throw new HttpException(409, 'Someone decided on this overlap since you opened it. Reload and try again.');
        }
        $this->audit->record(new AuditEvent('inventory.overlap_decided', ActorType::User, actorUserId: $user->id, workspaceId: $project->workspaceId, targetType: 'project', targetId: $project->publicId, metadata: ['kind' => $candidate['kind'], 'decision' => $decision]), $client);
    }

    /**
     * What each page is and gets, and which address to keep if they are merged:
     * the target of another member's redirect or canonical first, then the page
     * with the most clicks, then impressions.
     *
     * @param list<string> $members
     * @param array<string, array{key: string, url: ?string, title: ?string, state: string, sources: list<array<string, mixed>>}> $rows
     * @param array<string, list<string>> $owners
     * @return array{pages: list<array{url: string, state: string, state_label: string, title: ?string, clicks: ?int, impressions: ?int, target: ?string, owns: list<string>}>, retained: ?string, reason: string}
     */
    private function preview(array $members, array $rows, array $owners): array
    {
        $pages = [];
        foreach ($members as $url) {
            $row = $rows[UrlKey::of($url) ?? ''] ?? null;
            $search = null;
            $target = null;
            foreach ($row['sources'] ?? [] as $source) {
                if ($source['source'] === Inventory::SEARCH) {
                    $search = $source;
                }
                if ($source['source'] === Inventory::CRAWL && is_string($source['target'] ?? null) && UrlKey::of($source['target']) !== UrlKey::of($url)) {
                    $target = $source['target'];
                }
            }
            $pages[] = [
                'url' => $url,
                'state' => $row['state'] ?? 'unknown',
                'state_label' => Inventory::STATES[$row['state'] ?? ''] ?? 'Not in the inventory',
                'title' => $row['title'] ?? null,
                'clicks' => is_int($search['clicks'] ?? null) ? $search['clicks'] : null,
                'impressions' => is_int($search['impressions'] ?? null) ? $search['impressions'] : null,
                'target' => $target,
                'owns' => $owners[$url] ?? [],
            ];
        }
        foreach ($pages as $page) {
            foreach ($pages as $other) {
                if ($page['target'] !== null && UrlKey::of($page['target']) === UrlKey::of($other['url'])) {
                    return ['pages' => $pages, 'retained' => $other['url'], 'reason' => 'The other page already points here with a redirect or canonical.'];
                }
            }
        }
        $ranked = $pages;
        usort($ranked, static fn(array $a, array $b): int => [$b['clicks'] ?? -1, $b['impressions'] ?? -1, strlen($a['url'])] <=> [$a['clicks'] ?? -1, $a['impressions'] ?? -1, strlen($b['url'])]);
        $best = $ranked[0] ?? null;

        return ['pages' => $pages, 'retained' => $best['url'] ?? null, 'reason' => ($best['clicks'] ?? null) !== null ? 'It has the most Google clicks in the last 28 days.' : 'No page has search data; the shorter address is proposed. Check before deciding.'];
    }
}
