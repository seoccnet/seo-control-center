<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\Rankings;

use SeoControlCenter\Core\Authorization\Permission;
use SeoControlCenter\Core\Http\Exception\HttpException;
use SeoControlCenter\Core\Support\Clock;
use SeoControlCenter\Domain\Accounts\User;
use SeoControlCenter\Domain\Projects\Project;
use SeoControlCenter\Domain\Projects\ProjectService;
use SeoControlCenter\Domain\Rankings\RankingRepository;
use SeoControlCenter\Domain\Shared\ValidationException;

/**
 * Target page per keyword (1.8.0): the page the team wants to rank for each
 * tracked keyword, compared with the page Google ranks in the latest check,
 * and keywords for which Google keeps switching between pages.
 */
final readonly class TargetPageService
{
    public const int DAYS = 90;
    /** Changes of the ranking page within DAYS from which a keyword counts as switching. */
    public const int SWITCHES = 2;

    public const string MATCH = 'match';
    public const string OTHER = 'other';
    public const string NOT_RANKING = 'not_ranking';
    public const string NO_TARGET = 'no_target';
    public const string SWITCHING = 'switching';

    public function __construct(
        private RankingRepository $rankings,
        private KeywordTargetRepository $targets,
        private ProjectService $projects,
        private Clock $clock,
    ) {}

    /**
     * @return array{
     *     filter: string, canEdit: bool, counts: array<string, int>,
     *     rows: list<array{id: string, keyword: string, volume: ?int, checkedOn: string, position: ?int, url: ?string, target: ?string, status: string, switches: int, pages: list<string>}>
     * }
     */
    public function report(User $user, Project $project, string $filter): array
    {
        $device = $this->rankings->settings($project->id)->device;
        $since = $this->clock->now()->modify('-' . self::DAYS . ' days')->format('Y-m-d');
        $targets = $this->targets->targets($project->id);
        $rows = [];
        $counts = [self::OTHER => 0, self::SWITCHING => 0, self::NO_TARGET => 0, self::NOT_RANKING => 0, self::MATCH => 0];
        foreach ($this->rankings->urlHistory($project->id, $project->workspaceId, $device, $since) as $id => $keyword) {
            $latest = $keyword['checks'][count($keyword['checks']) - 1];
            $switches = 0;
            $previous = null;
            $pages = [];
            foreach ($keyword['checks'] as $check) {
                if ($check['url'] === null || $check['position'] === null) {
                    continue;
                }
                $key = self::key($check['url']);
                $pages[$key] = $check['url'];
                if ($previous !== null && $previous !== $key) {
                    ++$switches;
                }
                $previous = $key;
            }
            $target = $targets[$id] ?? null;
            $url = $latest['position'] !== null ? $latest['url'] : null;
            $status = match (true) {
                $target === null => self::NO_TARGET,
                $url === null => self::NOT_RANKING,
                self::key($url) === self::key($target) => self::MATCH,
                default => self::OTHER,
            };
            ++$counts[$status];
            if ($switches >= self::SWITCHES) {
                ++$counts[self::SWITCHING];
            }
            $rows[] = [
                'id' => (string) $id,
                'keyword' => $keyword['keyword'],
                'volume' => $keyword['volume'],
                'checkedOn' => $latest['date'],
                'position' => $latest['position'],
                'url' => $url,
                'target' => $target,
                'status' => $status,
                'switches' => $switches,
                'pages' => array_values($pages),
            ];
        }
        $filter = in_array($filter, [self::OTHER, self::SWITCHING, self::NO_TARGET, self::NOT_RANKING, self::MATCH], true) ? $filter : 'all';
        if ($filter !== 'all') {
            $rows = array_values(array_filter($rows, static fn(array $r): bool => $filter === self::SWITCHING ? $r['switches'] >= self::SWITCHES : $r['status'] === $filter));
        }
        $rank = [self::OTHER => 0, self::NOT_RANKING => 1, self::NO_TARGET => 2, self::MATCH => 3];
        usort($rows, static fn(array $a, array $b): int => [$rank[$a['status']], $b['switches'] >= self::SWITCHES, $b['volume'] ?? -1, $a['keyword']] <=> [$rank[$b['status']], $a['switches'] >= self::SWITCHES, $a['volume'] ?? -1, $b['keyword']]);

        return [
            'filter' => $filter,
            'canEdit' => $this->projects->can($user, Permission::ProjectsEdit, $project),
            'counts' => $counts,
            'rows' => $rows,
        ];
    }

    /**
     * Sets or clears one keyword's target page.
     *
     * @throws ValidationException
     */
    public function set(User $user, Project $project, string $keywordId, string $url): void
    {
        $this->requireEdit($user, $project);
        $url = trim($url);
        $normalized = $url === '' ? null : $this->normalize($project, $url);
        if ($this->targets->set($project->id, [$keywordId => $normalized], $user->id) === 0 && $normalized !== null) {
            throw new ValidationException(['url' => 'This keyword is not tracked in the project.']);
        }
    }

    /**
     * Makes the page ranking now the target of every ranking keyword without one.
     *
     * @return int targets set
     */
    public function adoptCurrent(User $user, Project $project): int
    {
        $this->requireEdit($user, $project);
        $set = [];
        foreach ($this->report($user, $project, self::NO_TARGET)['rows'] as $row) {
            if ($row['url'] !== null) {
                $set[$row['id']] = $row['url'];
            }
        }

        return $this->targets->set($project->id, $set, $user->id);
    }

    /** Compares addresses without scheme case, host case, "www.", fragment or trailing slash. */
    public static function key(string $url): string
    {
        $parts = parse_url(trim($url));
        if ($parts === false || !isset($parts['host'])) {
            return strtolower(rtrim($url, '/'));
        }
        $host = strtolower($parts['host']);
        $host = str_starts_with($host, 'www.') ? substr($host, 4) : $host;
        $path = rtrim($parts['path'] ?? '', '/');

        return $host . ($path === '' ? '/' : $path) . (isset($parts['query']) ? '?' . $parts['query'] : '');
    }

    /** @throws ValidationException */
    private function normalize(Project $project, string $url): string
    {
        $own = strtolower($project->primaryDomain);
        $own = str_starts_with($own, 'www.') ? substr($own, 4) : $own;
        if (str_starts_with($url, '/')) {
            $url = 'https://' . $project->primaryDomain . $url;
        }
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
        $bare = str_starts_with($host, 'www.') ? substr($host, 4) : $host;
        if (!in_array($scheme, ['http', 'https'], true) || ($bare !== $own && !str_ends_with($bare, '.' . $own)) || strlen($url) > 2048) {
            throw new ValidationException(['url' => sprintf('Enter a page of %s, for example https://%s/page or /page.', $project->primaryDomain, $project->primaryDomain)]);
        }
        $fragment = strpos($url, '#');

        return $fragment === false ? $url : substr($url, 0, $fragment);
    }

    private function requireEdit(User $user, Project $project): void
    {
        if (!$this->projects->can($user, Permission::ProjectsEdit, $project)) {
            throw new HttpException(403, 'You cannot change the target pages of this project.');
        }
    }
}
