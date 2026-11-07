<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\ContentBriefs;

use SeoControlCenter\Core\Audit\ActorType;
use SeoControlCenter\Core\Audit\AuditEvent;
use SeoControlCenter\Core\Audit\AuditLogger;
use SeoControlCenter\Core\Authorization\Authorizer;
use SeoControlCenter\Core\Authorization\Permission;
use SeoControlCenter\Core\Http\Exception\HttpException;
use SeoControlCenter\Core\Http\Exception\NotFoundHttpException;
use SeoControlCenter\Core\Support\ClientInfo;
use SeoControlCenter\Core\Support\Clock;
use SeoControlCenter\Core\Support\Uuid;
use SeoControlCenter\Domain\Accounts\User;
use SeoControlCenter\Domain\Claims\Claim;
use SeoControlCenter\Domain\Claims\ClaimFlags;
use SeoControlCenter\Domain\Projects\Project;

/**
 * Claim verification on content briefs (ADR-042): the factual claims of a
 * brief, the source each was checked against, the scope of both, a
 * person's verdict and the history of every change. SEOcc flags evidence
 * problems; it never rewrites a claim, never decides a verdict and never
 * publishes anything.
 *
 * @phpstan-import-type ClaimData from ClaimRepository
 */
final readonly class ClaimService
{
    /** Fields whose change makes an earlier verdict stale. */
    private const array EVIDENCE = ['claim', 'subject', 'claim_scope', 'source_url', 'checked_on', 'passage', 'source_scope'];

    /** @param ?\Closure(int): list<string> $competitorDomains the project's competitor domains */
    public function __construct(
        private ClaimRepository $claims,
        private BriefService $briefs,
        private Authorizer $auth,
        private AuditLogger $audit,
        private Clock $clock,
        private ?\Closure $competitorDomains = null,
    ) {}

    /**
     * A brief's claims with their automated flags and a count per verdict.
     *
     * @return array{claims: list<array{claim: Claim, flags: list<array{code: string, level: string, message: string}>, revisions: list<array{version: int, snapshot: array<string, mixed>, changed_by: ?int, changed_at: string}>}>, counts: array<string, int>, flagged: int}
     */
    public function forBrief(Project $project, string $briefId): array
    {
        $this->briefs->get($project, $briefId);
        $competitors = $this->competitorDomains !== null ? ($this->competitorDomains)($project->id) : [];
        $today = $this->clock->now()->format('Y-m-d');
        $rows = array_map(fn(Claim $c): array => ['claim' => $c, 'flags' => ClaimFlags::of($c, $project->primaryDomain, $competitors, $today), 'revisions' => $c->version > 1 ? $this->claims->revisions($project->id, $c->publicId) : []], $this->claims->forBrief($project->id, $briefId));
        $counts = array_fill_keys(array_keys(Claim::STATUSES), 0);
        foreach ($rows as $row) {
            $counts[$row['claim']->status]++;
        }

        return ['claims' => $rows, 'counts' => $counts, 'flagged' => count(array_filter($rows, static fn(array $r): bool => $r['flags'] !== []))];
    }

    /**
     * Claims across the project, for MCP.
     *
     * @return array{items: list<array<string, mixed>>, total: int}
     */
    public function listing(Project $project, string $status, int $offset, int $limit): array
    {
        $page = $this->claims->forProject($project->id, $status, $offset, $limit);
        $competitors = $this->competitorDomains !== null ? ($this->competitorDomains)($project->id) : [];
        $today = $this->clock->now()->format('Y-m-d');

        return ['items' => array_map(static fn(Claim $c): array => [...$c->present(), 'flags' => ClaimFlags::of($c, $project->primaryDomain, $competitors, $today)], $page['items']), 'total' => $page['total']];
    }

    /** @param array<string, mixed> $input */
    public function add(User $user, Project $project, string $briefId, array $input, ClientInfo $client): Claim
    {
        $this->auth->authorize($user->subject(), Permission::ProjectsEdit, $project->workspaceId, $project->id);
        $brief = $this->briefs->get($project, $briefId);
        if ($this->claims->countForBrief($project->id, $brief->publicId) >= Claim::MAX_PER_BRIEF) {
            throw new HttpException(422, 'A brief can hold up to ' . Claim::MAX_PER_BRIEF . ' claims.');
        }
        $data = $this->validate($input);
        $claim = $this->claims->add($project->workspaceId, $project->id, $brief->publicId, $data, $user->id);
        $this->audit->record(new AuditEvent('briefs.claim_added', ActorType::User, actorUserId: $user->id, workspaceId: $project->workspaceId, targetType: 'project', targetId: $project->publicId, metadata: ['brief_id' => $brief->publicId, 'claim_id' => $claim->publicId, 'status' => $data['status']]), $client);

        return $claim;
    }

    /**
     * Saves an edit or a verdict. When the evidence changes but the verdict is
     * left as it was, the verdict is reset to unreviewed: it was given for
     * different evidence.
     *
     * @param array<string, mixed> $input
     */
    public function update(User $user, Project $project, string $claimId, array $input, ClientInfo $client): Claim
    {
        $this->auth->authorize($user->subject(), Permission::ProjectsEdit, $project->workspaceId, $project->id);
        $claim = $this->claim($project, $claimId);
        $version = is_string($input['version'] ?? null) && ctype_digit($input['version']) ? (int) $input['version'] : (is_int($input['version'] ?? null) ? $input['version'] : 0);
        $data = $this->validate($input);
        $before = $this->data($claim);
        $evidenceChanged = array_intersect_key($data, array_flip(self::EVIDENCE)) != array_intersect_key($before, array_flip(self::EVIDENCE));
        if ($evidenceChanged && $data['status'] === $claim->status && $claim->status !== Claim::UNREVIEWED) {
            $data['status'] = Claim::UNREVIEWED;
        }
        $reviewed = $data['status'] !== $claim->status || $data['note'] !== $claim->note;
        if (!$this->claims->update($project->id, $claim->publicId, $version, $data, $reviewed, $user->id)) {
            throw new HttpException(409, 'Someone changed this claim since you opened it. Reload and try again.');
        }
        $this->audit->record(new AuditEvent('briefs.claim_updated', ActorType::User, actorUserId: $user->id, workspaceId: $project->workspaceId, targetType: 'project', targetId: $project->publicId, metadata: ['claim_id' => $claim->publicId, 'from' => $claim->status, 'to' => $data['status']]), $client);

        return $this->claim($project, $claimId);
    }

    public function remove(User $user, Project $project, string $claimId, ClientInfo $client): void
    {
        $this->auth->authorize($user->subject(), Permission::ProjectsEdit, $project->workspaceId, $project->id);
        $claim = $this->claim($project, $claimId);
        $this->claims->remove($project->id, $claim->publicId);
        $this->audit->record(new AuditEvent('briefs.claim_removed', ActorType::User, actorUserId: $user->id, workspaceId: $project->workspaceId, targetType: 'project', targetId: $project->publicId, metadata: ['claim_id' => $claim->publicId]), $client);
    }

    /** @return list<array{version: int, snapshot: array<string, mixed>, changed_by: ?int, changed_at: string}> */
    public function revisions(Project $project, string $claimId): array
    {
        return $this->claims->revisions($project->id, $this->claim($project, $claimId)->publicId);
    }

    public function claim(Project $project, string $claimId): Claim
    {
        $claim = Uuid::isValid($claimId) ? $this->claims->find($project->id, $claimId) : null;

        return $claim ?? throw new NotFoundHttpException('Claim not found.');
    }

    /** @return ClaimData */
    private function data(Claim $claim): array
    {
        return ['claim' => $claim->claim, 'subject' => $claim->subject, 'claim_scope' => $claim->claimScope, 'source_url' => $claim->sourceUrl, 'publisher' => $claim->publisher, 'checked_on' => $claim->checkedOn, 'passage' => $claim->passage, 'source_scope' => $claim->sourceScope, 'status' => $claim->status, 'note' => $claim->note];
    }

    /**
     * @param array<string, mixed> $input
     * @return ClaimData
     */
    private function validate(array $input): array
    {
        $text = static fn(string $key): string => is_string($input[$key] ?? null) ? trim($input[$key]) : '';
        $claim = $text('claim');
        $url = $text('source_url');
        $checked = $text('checked_on');
        $status = $text('status') !== '' ? $text('status') : Claim::UNREVIEWED;
        if ($claim === '' || mb_strlen($claim) > 1000) {
            throw new HttpException(422, 'Write the claim exactly as it should appear, in at most 1,000 characters.');
        }
        if (!isset(Claim::STATUSES[$status])) {
            throw new HttpException(422, 'Choose a valid verdict.');
        }
        if ($url !== '' && (strlen($url) > 2048 || preg_match('#^https?://[^\s/$.?\#][^\s]*$#i', $url) !== 1)) {
            throw new HttpException(422, 'The source must be a full http or https address.');
        }
        if ($checked !== '') {
            $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $checked);
            if ($date === false || $date->format('Y-m-d') !== $checked || $checked > $this->clock->now()->format('Y-m-d')) {
                throw new HttpException(422, 'The date checked must be a real date, not in the future.');
            }
        }
        $scope = static function (string $prefix) use ($text): array {
            $values = [];
            foreach (array_keys(Claim::SCOPE) as $field) {
                $value = mb_substr($text($prefix . $field), 0, 200);
                if ($value !== '') {
                    $values[$field] = $value;
                }
            }

            return $values;
        };
        foreach (['subject' => 300, 'publisher' => 200, 'passage' => 2000, 'note' => 2000] as $field => $max) {
            if (mb_strlen($text($field)) > $max) {
                throw new HttpException(422, sprintf('Keep the %s to %s characters.', $field, number_format($max)));
            }
        }

        return [
            'claim' => $claim,
            'subject' => $text('subject'),
            'claim_scope' => $scope('claim_'),
            'source_url' => $url !== '' ? $url : null,
            'publisher' => $text('publisher'),
            'checked_on' => $checked !== '' ? $checked : null,
            'passage' => $text('passage'),
            'source_scope' => $scope('source_'),
            'status' => $status,
            'note' => $text('note'),
        ];
    }
}
