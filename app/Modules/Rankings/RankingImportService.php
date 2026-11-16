<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\Rankings;

use SeoControlCenter\Core\Audit\ActorType;
use SeoControlCenter\Core\Audit\AuditEvent;
use SeoControlCenter\Core\Audit\AuditLogger;
use SeoControlCenter\Core\Authorization\Authorizer;
use SeoControlCenter\Core\Authorization\Permission;
use SeoControlCenter\Core\Http\Exception\HttpException;
use SeoControlCenter\Core\RateLimit\RateLimiter;
use SeoControlCenter\Core\Support\ClientInfo;
use SeoControlCenter\Core\Support\Clock;
use SeoControlCenter\Domain\Accounts\User;
use SeoControlCenter\Domain\Keywords\Keyword;
use SeoControlCenter\Domain\Keywords\KeywordLocale;
use SeoControlCenter\Domain\Keywords\KeywordRepository;
use SeoControlCenter\Domain\Projects\Project;
use SeoControlCenter\Domain\Rankings\RankingCsv;
use SeoControlCenter\Domain\Rankings\RankingRepository;
use SeoControlCenter\Domain\Rankings\SerpDevice;
use SeoControlCenter\Domain\Shared\ValidationException;
use SeoControlCenter\Modules\Keywords\KeywordService;

/**
 * Imports keyword lists and ranking history from other tools (1.3.0). New
 * keywords are tracked in the chosen market, up to the project's keyword
 * limit; positions are stored for days SEOcc has not checked itself and
 * marked as imported, and the daily ranking totals of those days are
 * rebuilt. No paid lookup is started: metrics and checks follow the
 * project's normal schedule.
 */
final readonly class RankingImportService
{
    public const int MAX_BYTES = 10 * 1024 * 1024;
    public const int MAX_POSITIONS = 100000;
    public const int MAX_DAYS = 800;
    public const int YEARS_BACK = 5;
    private const int IMPORTS_PER_HOUR = 10;

    public function __construct(
        private KeywordRepository $keywords,
        private RankingRepository $rankings,
        private RankingImportStore $store,
        private Authorizer $auth,
        private RateLimiter $rateLimiter,
        private AuditLogger $audit,
        private Clock $clock,
    ) {}

    /** @return list<KeywordLocale> the project's markets, most keywords first, or the default market */
    public function markets(Project $project): array
    {
        $markets = $this->keywords->marketsByCount($project->id, 20);

        return $markets !== [] ? $markets : [KeywordLocale::default()];
    }

    public function canImport(User $user, Project $project): bool
    {
        return $this->auth->can($user->subject(), Permission::ProjectsEdit, $project->workspaceId, $project->id);
    }

    /**
     * @param string $device desktop or mobile, for rows without a device
     * @param string $fallbackDate Y-m-d, for rows without a date
     * @return array{layout: string, columns: array<string, string>, lines: int, keywordsAdded: int, keywordsSkipped: int, invalidKeywords: int, positions: int, existing: int, days: int, ignoredRows: int}
     */
    public function import(User $user, Project $project, string $bytes, string $market, string $device, string $fallbackDate, ClientInfo $client): array
    {
        $this->auth->authorize($user->subject(), Permission::ProjectsEdit, $project->workspaceId, $project->id);
        $locale = KeywordLocale::fromKey($market);
        $errors = [];
        if ($locale === null) {
            $errors['market'] = 'Choose a market.';
        }
        if (!in_array($device, [SerpDevice::Desktop->value, SerpDevice::Mobile->value], true)) {
            $errors['device'] = 'Choose desktop or mobile.';
        }
        $today = $this->clock->now()->format('Y-m-d');
        $oldest = $this->clock->now()->modify('-' . self::YEARS_BACK . ' years')->format('Y-m-d');
        if (RankingCsv::date($fallbackDate) !== $fallbackDate || $fallbackDate > $today || $fallbackDate < $oldest) {
            $errors['date'] = 'Choose a date in the last five years.';
        }
        if ($bytes === '' || strlen($bytes) > self::MAX_BYTES) {
            $errors['file'] = sprintf('Choose a CSV file of up to %d MB.', intdiv(self::MAX_BYTES, 1048576));
        }
        if ($errors !== [] || $locale === null) {
            throw new ValidationException($errors);
        }
        if (!$this->rateLimiter->hit('ranking-import:project:' . $project->id, self::IMPORTS_PER_HOUR, 3600)->allowed) {
            throw new HttpException(429, 'Too many imports for this project. Try again later.');
        }
        try {
            $csv = RankingCsv::parse($bytes);
        } catch (\InvalidArgumentException $e) {
            throw new ValidationException(['file' => $e->getMessage()]);
        }

        // Keywords: normalized, valid, new ones up to the project's limit.
        $invalid = [];
        $terms = [];
        foreach ($csv['rows'] as $row) {
            $normalized = Keyword::normalize($row['keyword']);
            if (Keyword::problem($normalized) !== null) {
                $invalid[$normalized] = true;
                continue;
            }
            $terms[$normalized] = true;
        }
        $known = $this->store->keywordIds($project->id, array_keys($terms), $locale);
        $new = array_values(array_diff(array_keys($terms), array_keys($known)));
        $room = max(0, KeywordService::MAX_PER_PROJECT - $this->keywords->count($project->id));
        $added = $new !== [] && $room > 0 ? $this->keywords->add($project->id, array_slice($new, 0, $room), $locale, $user->id) : 0;
        $ids = $this->store->keywordIds($project->id, array_keys($terms), $locale);

        // Positions: one per keyword, device and day; the last row of a day wins.
        $positions = [];
        $ignored = 0;
        foreach ($csv['layout'] === 'keywords' ? [] : $csv['rows'] as $row) {
            $normalized = Keyword::normalize($row['keyword']);
            if (isset($invalid[$normalized])) {
                continue;
            }
            $id = $ids[$normalized] ?? null;
            $date = $row['date'] ?? $fallbackDate;
            if ($id === null || $date > $today || $date < $oldest) {
                ++$ignored;
                continue;
            }
            $rowDevice = $row['device'] ?? $device;
            $positions[$id . '|' . $rowDevice . '|' . $date] = ['keywordId' => $id, 'device' => $rowDevice, 'date' => $date, 'position' => $row['position'], 'url' => $row['position'] !== null ? $row['url'] : null];
        }
        $days = array_values(array_unique(array_map(static fn(array $p): string => $p['device'] . '|' . $p['date'], $positions)));
        if (count($positions) > self::MAX_POSITIONS || count($days) > self::MAX_DAYS) {
            throw new ValidationException(['file' => sprintf('An import can hold up to %s positions on %d days. Split the file by date.', number_format(self::MAX_POSITIONS), self::MAX_DAYS)]);
        }
        $inserted = $this->store->insert($project->id, array_values($positions));
        foreach ($days as $day) {
            [$dayDevice, $date] = explode('|', $day);
            $this->rankings->aggregate($project->id, $project->workspaceId, SerpDevice::from($dayDevice), $date);
        }

        $result = [
            'layout' => $csv['layout'],
            'columns' => $csv['columns'],
            'lines' => $csv['lines'],
            'keywordsAdded' => $added,
            'keywordsSkipped' => count($new) - $added,
            'invalidKeywords' => count($invalid),
            'positions' => $inserted,
            'existing' => count($positions) - $inserted,
            'days' => count($days),
            'ignoredRows' => $ignored,
        ];
        $this->audit->record(new AuditEvent('rankings.history_imported', ActorType::User, actorUserId: $user->id, actorLabel: $user->email, workspaceId: $project->workspaceId, targetType: 'project', targetId: $project->publicId, metadata: [
            'market' => $locale->key(), 'layout' => $csv['layout'], 'keywords_added' => $added, 'positions' => $inserted, 'days' => count($days),
        ]), $client);

        return $result;
    }
}
