<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\Investigations;

use SeoControlCenter\Core\Audit\ActorType;
use SeoControlCenter\Core\Audit\AuditEvent;
use SeoControlCenter\Core\Audit\AuditLogger;
use SeoControlCenter\Core\Authorization\Permission;
use SeoControlCenter\Core\Http\Exception\HttpException;
use SeoControlCenter\Core\Support\ClientInfo;
use SeoControlCenter\Core\Support\Clock;
use SeoControlCenter\Domain\Accounts\User;
use SeoControlCenter\Domain\Comparison\Analysis;
use SeoControlCenter\Domain\Comparison\AnalysisEvidence;
use SeoControlCenter\Domain\Comparison\AnalysisRepository;
use SeoControlCenter\Domain\Investigations\Investigation;
use SeoControlCenter\Domain\Investigations\InvestigationInput;
use SeoControlCenter\Domain\Investigations\InvestigationRepository;
use SeoControlCenter\Domain\Projects\Project;
use SeoControlCenter\Domain\Projects\ProjectService;
use SeoControlCenter\Domain\Shared\ValidationException;
use SeoControlCenter\Modules\Research\ResearchOnFile;

/**
 * Investigations (ADR-038, D3): saved research notebooks with dated
 * sources, checks, limitations and notes. Everyone who sees the project
 * reads them; project editors (and MCP tokens with investigations:write)
 * change them. Adding never buys data: it records what is already stored.
 */
final readonly class InvestigationService
{
    public const int PER_PAGE = 50;

    public function __construct(
        private InvestigationRepository $investigations,
        private ProjectService $projects,
        private AuditLogger $audit,
        private Clock $clock,
        private ?AnalysisRepository $analyses = null,
        private ?ResearchOnFile $onFile = null,
    ) {}

    /**
     * @return array{items: list<Investigation>, total: int}
     */
    public function list(Project $project, ?string $status = null, int $offset = 0): array
    {
        $status = in_array($status, Investigation::STATUSES, true) ? $status : null;

        return ['items' => $this->investigations->list($project, $status, self::PER_PAGE, max(0, $offset)), 'total' => $this->investigations->count($project, $status)];
    }

    public function get(Project $project, string $publicId): Investigation
    {
        return $this->investigations->find($project, $publicId) ?? throw new HttpException(404, 'Investigation not found.');
    }

    public function canEdit(User $user, Project $project): bool
    {
        return $this->projects->can($user, Permission::ProjectsEdit, $project);
    }

    /**
     * Creates ($publicId null) or updates an investigation's title, question, status and conclusion.
     *
     * @param array<string, mixed> $data
     * @throws ValidationException
     */
    public function save(User $user, Project $project, array $data, ClientInfo $client, ?string $publicId = null, ?int $expectedRevision = null, ?string $via = null): Investigation
    {
        $this->authorize($user, $project);
        $fields = InvestigationInput::fields($data);
        if ($publicId === null) {
            $publicId = $this->investigations->create($project, $fields, $user->id, $via);
            $this->record($user, $project, $publicId, 'created', $client, $via);
        } else {
            $this->get($project, $publicId);
            $this->investigations->update($project, $publicId, $fields, $expectedRevision, $via);
            $this->record($user, $project, $publicId, 'updated', $client, $via, ['status' => $fields['status']]);
        }

        return $this->get($project, $publicId);
    }

    /**
     * @param list<array<string, mixed>> $items
     * @throws ValidationException
     */
    public function addItems(User $user, Project $project, string $publicId, array $items, ClientInfo $client, ?string $via = null): Investigation
    {
        $this->authorize($user, $project);
        $this->get($project, $publicId);
        if ($items === [] || count($items) > InvestigationInput::MAX_ITEMS_PER_CALL) {
            throw new ValidationException(['items' => sprintf('Add between 1 and %d entries at a time.', InvestigationInput::MAX_ITEMS_PER_CALL)]);
        }
        $today = $this->clock->now()->format('Y-m-d');
        $clean = [];
        foreach ($items as $i => $item) {
            try {
                $clean[] = InvestigationInput::item($item, $today);
            } catch (ValidationException $e) {
                throw new ValidationException(count($items) === 1 ? $e->errors() : array_combine(array_map(static fn(string $k): string => 'items.' . $i . '.' . $k, array_keys($e->errors())), array_values($e->errors())));
            }
        }
        if ($this->investigations->itemCount($project, $publicId) + count($clean) > InvestigationInput::MAX_ITEMS) {
            throw new ValidationException(['items' => sprintf('An investigation holds at most %d entries. Start a new one or remove entries you no longer need.', InvestigationInput::MAX_ITEMS)]);
        }
        $this->investigations->addItems($project, $publicId, $clean, $user->id, $via);
        $this->record($user, $project, $publicId, 'items_added', $client, $via, ['count' => count($clean)]);

        return $this->get($project, $publicId);
    }

    /**
     * Adds a finished "Why they outrank you" analysis of this project with
     * its sources, checks and limitations.
     */
    public function addAnalysis(User $user, Project $project, string $publicId, string $analysisId, ClientInfo $client, ?string $via = null): Investigation
    {
        $analysis = $this->analyses?->find($project, $analysisId);
        if ($analysis === null || $analysis->status !== Analysis::DONE) {
            throw new HttpException(404, 'Finished analysis not found.');
        }

        return $this->addItems($user, $project, $publicId, AnalysisEvidence::items($analysis), $client, $via);
    }

    /**
     * Adds one stored research lookup (from Research on file) as a dated source.
     */
    public function addLookup(User $user, Project $project, string $publicId, string $kind, ?string $subject, ClientInfo $client, ?string $via = null): Investigation
    {
        foreach ($this->onFile?->list($project)['lookups'] ?? [] as $l) {
            if ($l['kind'] === $kind && $l['subject'] === $subject && $l['fetched_at'] !== null) {
                $detail = implode(' · ', array_filter([$l['market'], $l['inputs'], number_format($l['rows']) . ($l['rows'] === 1 ? ' row' : ' rows'), $l['fresh'] ? 'current' : 'older than ' . \SeoControlCenter\Modules\Research\RepeatedLookup::DAYS . ' days']));

                return $this->addItems($user, $project, $publicId, [[
                    'kind' => 'source',
                    'ref_type' => 'research',
                    'ref_id' => $kind . ($subject !== null ? ':' . $subject : ''),
                    'label' => $l['label'] . ($subject !== null ? ': ' . $subject : ''),
                    'detail' => $detail,
                    'observed_on' => substr($l['fetched_at'], 0, 10),
                ]], $client, $via);
            }
        }

        throw new HttpException(404, 'That lookup is not on file.');
    }

    public function removeItem(User $user, Project $project, string $publicId, string $itemId, ClientInfo $client): void
    {
        $this->authorize($user, $project);
        $this->get($project, $publicId);
        if (!$this->investigations->removeItem($project, $publicId, $itemId)) {
            throw new HttpException(404, 'Entry not found.');
        }
        $this->record($user, $project, $publicId, 'item_removed', $client, null, ['item_id' => $itemId]);
    }

    /**
     * What can be added from stored data: finished analyses and lookups on file.
     *
     * @return array{analyses: list<array{id: string, label: string}>, lookups: list<array{kind: string, subject: ?string, label: string}>}
     */
    public function choices(Project $project): array
    {
        $file = $this->onFile?->list($project);

        return [
            'analyses' => array_map(static fn(array $a): array => ['id' => $a['analysis_id'], 'label' => $a['topic'] . ' (' . substr((string) $a['finished_at'], 0, 10) . ')'], $file['analyses'] ?? []),
            'lookups' => array_values(array_map(static fn(array $l): array => ['kind' => $l['kind'], 'subject' => $l['subject'], 'label' => $l['label'] . ($l['subject'] !== null ? ': ' . $l['subject'] : '') . ' (' . substr((string) $l['fetched_at'], 0, 10) . ')'], array_filter($file['lookups'] ?? [], static fn(array $l): bool => $l['fetched_at'] !== null))),
        ];
    }

    private function authorize(User $user, Project $project): void
    {
        if (!$this->canEdit($user, $project)) {
            throw new HttpException(403, 'You cannot change investigations of this project.');
        }
    }

    /** @param array<string, mixed> $metadata */
    private function record(User $user, Project $project, string $publicId, string $action, ClientInfo $client, ?string $via, array $metadata = []): void
    {
        $this->audit->record(new AuditEvent('project.investigation_' . $action, $via === null ? ActorType::User : ActorType::Mcp, actorUserId: $user->id, actorLabel: $via ?? $user->email, workspaceId: $project->workspaceId, targetType: 'investigation', targetId: $publicId, metadata: $via === null ? $metadata : [...$metadata, 'via' => $via]), $client);
    }
}
