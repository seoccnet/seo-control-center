<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\Backlinks;

use SeoControlCenter\Core\Audit\ActorType;
use SeoControlCenter\Core\Audit\AuditEvent;
use SeoControlCenter\Core\Audit\AuditLogger;
use SeoControlCenter\Core\Authorization\Permission;
use SeoControlCenter\Core\Http\Exception\HttpException;
use SeoControlCenter\Core\Support\ClientInfo;
use SeoControlCenter\Core\Support\Clock;
use SeoControlCenter\Domain\Accounts\User;
use SeoControlCenter\Domain\Backlinks\ReferringDomain;
use SeoControlCenter\Domain\Projects\Project;
use SeoControlCenter\Domain\Projects\ProjectService;
use SeoControlCenter\Domain\Shared\ValidationException;
use SeoControlCenter\Modules\Research\ResearchRepository;

/**
 * Disavow file builder (1.7.0): reviews the stored referring domains by
 * spam score, keeps the project's list of disavowed domains and writes it
 * in Google's disavow format. SEOcc never uploads it; Google asks for a
 * disavow file only against manual actions or link schemes you cannot undo.
 */
final readonly class DisavowService
{
    /** Spam scores from this one are listed by default (DataForSEO, 0–100). */
    public const int SPAM = 30;

    public function __construct(
        private DisavowRepository $disavows,
        private ResearchRepository $results,
        private ProjectService $projects,
        private AuditLogger $audit,
        private Clock $clock,
    ) {}

    /**
     * @return array{
     *     fetchedAt: ?string, total: int, all: bool, canEdit: bool,
     *     candidates: list<array{domain: ReferringDomain, disavowed: bool}>,
     *     disavowed: list<array{domain: string, spamScore: ?int, addedAt: string}>
     * }
     */
    public function page(User $user, Project $project, bool $all): array
    {
        $result = $this->results->find($project->id, 'bl_domains');
        $domains = $result !== null && $result->fetchedAt !== null ? $result->referringDomains() : [];
        $disavowed = $this->disavows->domains($project->id);
        $listed = array_fill_keys(array_column($disavowed, 'domain'), true);
        $candidates = [];
        foreach ($domains as $domain) {
            if ($all || ($domain->spamScore ?? 0) >= self::SPAM) {
                $candidates[] = ['domain' => $domain, 'disavowed' => isset($listed[self::normalize($domain->domain) ?? ''])];
            }
        }
        usort($candidates, static fn(array $a, array $b): int => [$b['domain']->spamScore ?? -1, $a['domain']->rank ?? 0, $a['domain']->domain] <=> [$a['domain']->spamScore ?? -1, $b['domain']->rank ?? 0, $b['domain']->domain]);

        return [
            'fetchedAt' => $result?->fetchedAt,
            'total' => count($domains),
            'all' => $all,
            'canEdit' => $this->projects->can($user, Permission::ProjectsEdit, $project),
            'candidates' => $candidates,
            'disavowed' => $disavowed,
        ];
    }

    /**
     * Adds ticked domains and domains typed one per line.
     *
     * @param list<string> $domains
     * @return int domains added
     */
    public function add(User $user, Project $project, array $domains, string $typed, ClientInfo $client): int
    {
        $this->requireEdit($user, $project);
        $spam = [];
        $result = $this->results->find($project->id, 'bl_domains');
        foreach ($result !== null ? $result->referringDomains() : [] as $known) {
            $spam[self::normalize($known->domain) ?? ''] = $known->spamScore;
        }
        $wanted = [];
        $invalid = [];
        foreach ([...$domains, ...preg_split('/[\r\n,]+/', $typed) ?: []] as $raw) {
            if (trim($raw) === '') {
                continue;
            }
            $domain = self::normalize($raw);
            if ($domain === null) {
                $invalid[] = mb_substr(trim($raw), 0, 60);
                continue;
            }
            if ($domain === self::normalize($project->primaryDomain)) {
                $invalid[] = $domain . ' (your own site)';
                continue;
            }
            $wanted[$domain] = $spam[$domain] ?? null;
        }
        if ($invalid !== []) {
            throw new ValidationException(['domains' => 'Not a domain name: ' . implode(', ', array_slice($invalid, 0, 5)) . '. Enter domains such as spam.example; write international names in their xn-- form.']);
        }
        $added = $this->disavows->add($project->id, $project->workspaceId, $wanted, $user->id);
        if ($added > 0) {
            $this->audit->record(new AuditEvent('backlinks.disavow_added', ActorType::User, actorUserId: $user->id, actorLabel: $user->email, workspaceId: $project->workspaceId, targetType: 'project', targetId: $project->publicId, metadata: ['domains' => $added]), $client);
        }

        return $added;
    }

    /**
     * @param list<string> $domains
     */
    public function remove(User $user, Project $project, array $domains, ClientInfo $client): int
    {
        $this->requireEdit($user, $project);
        $removed = $this->disavows->remove($project->id, array_values(array_filter(array_map(self::normalize(...), $domains))));
        if ($removed > 0) {
            $this->audit->record(new AuditEvent('backlinks.disavow_removed', ActorType::User, actorUserId: $user->id, actorLabel: $user->email, workspaceId: $project->workspaceId, targetType: 'project', targetId: $project->publicId, metadata: ['domains' => $removed]), $client);
        }

        return $removed;
    }

    /** The disavow file in Google's format: comments, then one domain: line each. */
    public function file(Project $project): string
    {
        $lines = [
            '# Disavow file for ' . $project->primaryDomain . ', written by SEO Control Center on ' . $this->clock->now()->format('Y-m-d') . '.',
            '# Upload it at https://search.google.com/search-console/disavow-links . It replaces the file uploaded before.',
        ];
        foreach ($this->disavows->domains($project->id) as $row) {
            $lines[] = 'domain:' . $row['domain'];
        }

        return implode("\n", $lines) . "\n";
    }

    /** Lower case, without scheme, path, "domain:" or "www."; null when not a plain ASCII host name. */
    public static function normalize(string $raw): ?string
    {
        $domain = strtolower(trim($raw));
        $domain = (string) preg_replace('#^(domain:|https?://)#', '', $domain);
        $domain = (string) preg_replace('#[/?\#:].*$#', '', $domain);
        $domain = str_starts_with($domain, 'www.') ? substr($domain, 4) : $domain;

        return strlen($domain) <= 253 && preg_match('/^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z0-9-]{2,63}$/', $domain) === 1 ? $domain : null;
    }

    private function requireEdit(User $user, Project $project): void
    {
        if (!$this->projects->can($user, Permission::ProjectsEdit, $project)) {
            throw new HttpException(403, 'You cannot change the disavow list of this project.');
        }
    }
}
