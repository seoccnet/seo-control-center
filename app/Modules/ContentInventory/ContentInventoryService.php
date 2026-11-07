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
use SeoControlCenter\Core\Support\Uuid;
use SeoControlCenter\Domain\Accounts\User;
use SeoControlCenter\Domain\ContentInventory\ContentItem;
use SeoControlCenter\Domain\ContentInventory\Inventory;
use SeoControlCenter\Domain\Projects\Project;

/**
 * The shared content inventory (ADR-042): CMS records from CSV or manual
 * entry joined with crawls, sitemaps, Search Console, briefs and plans,
 * and the check that runs before SEOcc recommends new content.
 */
final readonly class ContentInventoryService
{
    public const int PER_PAGE = 50;
    public const int MAX_CSV_ROWS = 500;

    /** WordPress and common CMS export statuses mapped to inventory statuses. */
    private const array STATUS_ALIASES = [
        'draft' => ContentItem::DRAFT, 'pending' => ContentItem::DRAFT, 'private' => ContentItem::DRAFT, 'auto-draft' => ContentItem::DRAFT,
        'scheduled' => ContentItem::SCHEDULED, 'future' => ContentItem::SCHEDULED,
        'published' => ContentItem::PUBLISHED, 'publish' => ContentItem::PUBLISHED, 'live' => ContentItem::PUBLISHED,
        'retired' => ContentItem::RETIRED, 'trash' => ContentItem::RETIRED, 'archived' => ContentItem::RETIRED, 'deleted' => ContentItem::RETIRED,
    ];

    public function __construct(
        private ContentItemRepository $items,
        private InventorySources $sources,
        private Authorizer $auth,
        private AuditLogger $audit,
    ) {}

    /**
     * @param array{state?: string, source?: string, q?: string} $filters
     * @return array<string, mixed>
     */
    public function overview(User $user, Project $project, array $filters = [], int $offset = 0, int $limit = self::PER_PAGE): array
    {
        $this->auth->authorize($user->subject(), Permission::ProjectsView, $project->workspaceId, $project->id);
        $inventory = $this->inventory($project);
        $state = $filters['state'] ?? '';
        $source = $filters['source'] ?? '';
        $q = mb_strtolower(trim($filters['q'] ?? ''));
        $rows = array_values(array_filter($inventory['rows'], static fn(array $r): bool => ($state === '' || $r['state'] === $state)
            && ($source === '' || in_array($source, array_column($r['sources'], 'source'), true))
            && ($q === '' || str_contains(mb_strtolower(($r['url'] ?? '') . ' ' . ($r['title'] ?? '')), $q))));
        $counts = array_fill_keys(array_keys(Inventory::STATES), 0);
        foreach ($inventory['rows'] as $row) {
            $counts[$row['state']]++;
        }

        return [
            'rows' => array_slice($rows, max(0, $offset), $limit),
            'total' => count($rows),
            'offset' => max(0, $offset),
            'limit' => $limit,
            'all' => count($inventory['rows']),
            'counts' => $counts,
            'coverage' => $inventory['coverage'],
            'needs_review' => count(array_filter($inventory['rows'], static fn(array $r): bool => $r['candidates'] !== [])),
            'can_edit' => $this->auth->can($user->subject(), Permission::ProjectsEdit, $project->workspaceId, $project->id),
        ];
    }

    /**
     * Inventory rows on each topic. Article ideas and briefs ask this before
     * recommending new content, so an existing page or a draft without
     * rankings is never proposed again as missing. The inventory is joined
     * once for all topics. `article` false marks a shop or other page on the
     * topic that does not answer it as an article.
     *
     * @param list<string> $topics
     * @return array<string, list<array{url: ?string, title: ?string, state: string, state_label: string, sources: list<string>, article: bool}>> per topic
     */
    public function covering(Project $project, array $topics): array
    {
        $rows = $this->inventory($project)['rows'];
        $found = [];
        foreach (array_unique($topics) as $topic) {
            $found[$topic] = array_map(static fn(array $r): array => [
                'url' => $r['url'],
                'title' => $r['title'],
                'state' => $r['state'],
                'state_label' => Inventory::STATES[$r['state']],
                'sources' => array_values(array_unique(array_column($r['sources'], 'source'))),
                'article' => $r['article'],
            ], array_slice(Inventory::covering($rows, $topic), 0, 5));
        }

        return $found;
    }

    /**
     * The joined inventory rows per matching key, for other features of this
     * module (the overlap review's merge preview). No permission check: the
     * caller has checked the project.
     *
     * @return array<string, array{key: string, url: ?string, title: ?string, state: string, sources: list<array<string, mixed>>, variants: list<string>, candidates: list<array{url: string, title: ?string}>}>
     */
    public function rowsByKey(Project $project): array
    {
        return array_column($this->inventory($project)['rows'], null, 'key');
    }

    /** @param array<string, mixed> $input */
    public function add(User $user, Project $project, array $input, ClientInfo $client): ContentItem
    {
        $this->auth->authorize($user->subject(), Permission::ProjectsEdit, $project->workspaceId, $project->id);
        if ($this->items->count($project->id) >= ContentItem::MAX_PER_PROJECT) {
            throw new HttpException(422, 'A project can hold up to ' . number_format(ContentItem::MAX_PER_PROJECT) . ' CMS records.');
        }
        $data = self::validate($input, $project);
        $item = $this->items->add($project->workspaceId, $project->id, $data, ContentItem::MANUAL, $user->id);
        $this->audit->record(new AuditEvent('inventory.item_added', ActorType::User, actorUserId: $user->id, workspaceId: $project->workspaceId, targetType: 'project', targetId: $project->publicId, metadata: ['status' => $data['status']]), $client);

        return $item;
    }

    /** @param array<string, mixed> $input */
    public function update(User $user, Project $project, string $id, array $input, ClientInfo $client): void
    {
        $this->auth->authorize($user->subject(), Permission::ProjectsEdit, $project->workspaceId, $project->id);
        $item = $this->item($project, $id);
        $version = is_string($input['version'] ?? null) && ctype_digit($input['version']) ? (int) $input['version'] : (is_int($input['version'] ?? null) ? $input['version'] : 0);
        $data = self::validate($input, $project) + ['linked_url' => $item->linkedUrl];
        if (!$this->items->update($project->id, $item->publicId, $version, $data)) {
            throw new HttpException(409, 'Someone changed this record since you opened it. Reload and try again.');
        }
        $this->audit->record(new AuditEvent('inventory.item_updated', ActorType::User, actorUserId: $user->id, workspaceId: $project->workspaceId, targetType: 'project', targetId: $project->publicId, metadata: ['item_id' => $id]), $client);
    }

    /** A person confirms (or clears) which page a record without its own address is. */
    public function link(User $user, Project $project, string $id, ?string $url, int $version, ClientInfo $client): void
    {
        $this->auth->authorize($user->subject(), Permission::ProjectsEdit, $project->workspaceId, $project->id);
        $item = $this->item($project, $id);
        if ($item->url !== null) {
            throw new HttpException(422, 'This record has its own address; edit it instead.');
        }
        if ($url !== null && !self::onSite($url, $project)) {
            throw new HttpException(422, 'Link a page on ' . $project->primaryDomain . '.');
        }
        $data = ['title' => $item->title, 'url' => null, 'status' => $item->status, 'publish_on' => $item->publishOn, 'cms_id' => $item->cmsId, 'note' => $item->note, 'linked_url' => $url];
        if (!$this->items->update($project->id, $item->publicId, $version, $data)) {
            throw new HttpException(409, 'Someone changed this record since you opened it. Reload and try again.');
        }
        $this->audit->record(new AuditEvent('inventory.item_linked', ActorType::User, actorUserId: $user->id, workspaceId: $project->workspaceId, targetType: 'project', targetId: $project->publicId, metadata: ['item_id' => $id, 'linked' => $url !== null]), $client);
    }

    public function remove(User $user, Project $project, string $id, ClientInfo $client): void
    {
        $this->auth->authorize($user->subject(), Permission::ProjectsEdit, $project->workspaceId, $project->id);
        if (!$this->items->remove($project->id, $this->item($project, $id)->publicId)) {
            throw new NotFoundHttpException();
        }
        $this->audit->record(new AuditEvent('inventory.item_removed', ActorType::User, actorUserId: $user->id, workspaceId: $project->workspaceId, targetType: 'project', targetId: $project->publicId, metadata: ['item_id' => $id]), $client);
    }

    /**
     * Imports a CMS export: a header row naming title, url, status, publish_date,
     * cms_id and note (in any order; title and status required). Rows with a
     * known cms_id update that record. Invalid rows are skipped and counted.
     *
     * @return array{added: int, updated: int, skipped: list<string>}
     */
    public function importCsv(User $user, Project $project, string $csv, ClientInfo $client): array
    {
        $this->auth->authorize($user->subject(), Permission::ProjectsEdit, $project->workspaceId, $project->id);
        $lines = preg_split('/\r\n|\n|\r/', trim(preg_replace('/^\xEF\xBB\xBF/', '', $csv) ?? '')) ?: [];
        if (count($lines) < 2) {
            throw new HttpException(422, 'Paste a header row and at least one record.');
        }
        if (count($lines) - 1 > self::MAX_CSV_ROWS) {
            throw new HttpException(422, 'Import at most ' . self::MAX_CSV_ROWS . ' records at a time.');
        }
        $delimiter = substr_count($lines[0], ';') > substr_count($lines[0], ',') ? ';' : (str_contains($lines[0], "\t") ? "\t" : ',');
        $header = array_map(static fn(?string $h): string => strtolower(trim((string) $h)), str_getcsv($lines[0], $delimiter, '"', ''));
        $header = array_map(static fn(string $h): string => match ($h) {
            'post_title', 'name' => 'title', 'link', 'permalink', 'address' => 'url', 'post_status', 'state' => 'status', 'publish_on', 'date', 'post_date' => 'publish_date', 'id', 'post_id' => 'cms_id', default => $h
        }, $header);
        if (!in_array('title', $header, true) || !in_array('status', $header, true)) {
            throw new HttpException(422, 'The header row needs at least title and status columns.');
        }
        $added = $updated = 0;
        $skipped = [];
        foreach (array_slice($lines, 1) as $n => $line) {
            if (trim($line) === '') {
                continue;
            }
            $values = str_getcsv($line, $delimiter, '"', '');
            $row = [];
            foreach ($header as $i => $name) {
                $row[$name] = trim((string) ($values[$i] ?? ''));
            }
            try {
                $data = self::validate(['title' => $row['title'] ?? '', 'url' => $row['url'] ?? '', 'status' => $row['status'] ?? '', 'publish_on' => $row['publish_date'] ?? '', 'cms_id' => $row['cms_id'] ?? '', 'note' => $row['note'] ?? ''], $project);
            } catch (HttpException $e) {
                $skipped[] = 'Row ' . ($n + 2) . ': ' . $e->getMessage();
                continue;
            }
            $existing = $data['cms_id'] !== null ? $this->items->findByCmsId($project->id, $data['cms_id']) : null;
            if ($existing !== null) {
                $this->items->update($project->id, $existing->publicId, $existing->version, $data + ['linked_url' => $data['url'] === null ? $existing->linkedUrl : null]);
                $updated++;
            } elseif ($this->items->count($project->id) < ContentItem::MAX_PER_PROJECT) {
                $this->items->add($project->workspaceId, $project->id, $data, ContentItem::CSV, $user->id);
                $added++;
            } else {
                $skipped[] = 'Row ' . ($n + 2) . ': the project already holds ' . number_format(ContentItem::MAX_PER_PROJECT) . ' records.';
            }
        }
        $this->audit->record(new AuditEvent('inventory.csv_imported', ActorType::User, actorUserId: $user->id, workspaceId: $project->workspaceId, targetType: 'project', targetId: $project->publicId, metadata: ['added' => $added, 'updated' => $updated, 'skipped' => count($skipped)]), $client);

        return ['added' => $added, 'updated' => $updated, 'skipped' => array_slice($skipped, 0, 20)];
    }

    public function item(Project $project, string $id): ContentItem
    {
        $item = Uuid::isValid($id) ? $this->items->find($project->id, $id) : null;
        if ($item === null) {
            throw new NotFoundHttpException();
        }

        return $item;
    }

    /** @return array{rows: list<array{key: string, url: ?string, title: ?string, state: string, sources: list<array<string, mixed>>, variants: list<string>, candidates: list<array{url: string, title: ?string}>}>, coverage: array<string, array<string, mixed>>} */
    private function inventory(Project $project): array
    {
        $stored = $this->sources->records($project->id);
        $records = $stored['records'];
        $items = $this->items->items($project->id);
        foreach ($items as $item) {
            $records[] = [
                'source' => Inventory::CMS,
                'url' => $item->matchUrl(),
                'ref' => $item->publicId,
                'title' => $item->title,
                'status' => $item->status,
                'observed_at' => $item->updatedAt,
                'detail' => ContentItem::STATUSES[$item->status] . ($item->publishOn !== null ? ' · ' . $item->publishOn : '') . ($item->cmsId !== null ? ' · CMS ID ' . $item->cmsId : '') . ($item->linkedUrl !== null ? ' · linked by a person' : '') . ' · ' . ($item->source === ContentItem::CSV ? 'CSV' : 'entered by hand'),
                'keyword' => $item->title,
                'item' => $item->present(),
            ];
        }
        $coverage = $stored['coverage'];
        $coverage[Inventory::CMS] = ['observed_at' => $items !== [] ? max(array_map(static fn(ContentItem $i): string => $i->updatedAt, $items)) : null, 'count' => count($items), 'limited' => false, 'note' => 'CMS records from CSV or entered by hand; SEOcc has no CMS connection.'];

        return ['rows' => Inventory::build($records), 'coverage' => $coverage];
    }

    /**
     * @param array<string, mixed> $input
     * @return array{title: string, url: ?string, status: string, publish_on: ?string, cms_id: ?string, note: string}
     */
    private static function validate(array $input, Project $project): array
    {
        $text = static fn(string $key): string => is_string($input[$key] ?? null) ? trim($input[$key]) : '';
        $title = $text('title');
        $url = $text('url');
        $status = self::STATUS_ALIASES[strtolower($text('status'))] ?? null;
        $date = $text('publish_on');
        $cms = $text('cms_id');
        if ($title === '' || mb_strlen($title) > 300) {
            throw new HttpException(422, 'Enter a title of at most 300 characters.');
        }
        if ($status === null) {
            throw new HttpException(422, 'Use status draft, scheduled, published or retired.');
        }
        if ($url !== '' && (strlen($url) > 2048 || !self::onSite($url, $project))) {
            throw new HttpException(422, 'The address must be a full URL on ' . $project->primaryDomain . '.');
        }
        if ($date !== '') {
            $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', substr($date, 0, 10));
            if ($parsed === false) {
                throw new HttpException(422, 'Write the publish date as YYYY-MM-DD.');
            }
            $date = $parsed->format('Y-m-d');
        }
        if (mb_strlen($cms) > 100 || mb_strlen($text('note')) > 500) {
            throw new HttpException(422, 'Keep the CMS ID to 100 and the note to 500 characters.');
        }

        return ['title' => $title, 'url' => $url !== '' ? $url : null, 'status' => $status, 'publish_on' => $date !== '' ? $date : null, 'cms_id' => $cms !== '' ? $cms : null, 'note' => $text('note')];
    }

    private static function onSite(string $url, Project $project): bool
    {
        $parts = parse_url($url);
        if (!is_array($parts) || !in_array(strtolower((string) ($parts['scheme'] ?? '')), ['http', 'https'], true) || !isset($parts['host'])) {
            return false;
        }
        $bare = static fn(string $host): string => (string) preg_replace('/^www\./', '', strtolower(trim($host)));
        $host = $bare($parts['host']);
        $site = $bare($project->primaryDomain);

        return $host === $site || str_ends_with($host, '.' . $site);
    }
}
