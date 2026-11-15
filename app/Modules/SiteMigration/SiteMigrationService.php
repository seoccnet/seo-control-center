<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\SiteMigration;

use Psr\Log\LoggerInterface;
use SeoControlCenter\Core\Audit\ActorType;
use SeoControlCenter\Core\Audit\AuditEvent;
use SeoControlCenter\Core\Audit\AuditLogger;
use SeoControlCenter\Core\Authorization\Authorizer;
use SeoControlCenter\Core\Authorization\Permission;
use SeoControlCenter\Core\Features\Feature;
use SeoControlCenter\Core\Features\FeatureManager;
use SeoControlCenter\Core\Http\Exception\HttpException;
use SeoControlCenter\Core\Http\Exception\NotFoundHttpException;
use SeoControlCenter\Core\RateLimit\RateLimiter;
use SeoControlCenter\Core\Support\ClientInfo;
use SeoControlCenter\Core\Support\Clock;
use SeoControlCenter\Domain\Accounts\User;
use SeoControlCenter\Domain\Projects\Project;
use SeoControlCenter\Domain\SearchConsole\PerformanceDimension;
use SeoControlCenter\Domain\SearchConsole\SearchPerformanceReader;
use SeoControlCenter\Domain\Shared\DateRange;
use SeoControlCenter\Domain\Shared\ValidationException;
use SeoControlCenter\Domain\SiteMigration\RedirectCheck;
use SeoControlCenter\Domain\SiteMigration\RedirectMatcher;
use SeoControlCenter\Modules\SiteAudit\AuditPage;
use SeoControlCenter\Modules\SiteAudit\Crawler\UrlNormalizer;
use SeoControlCenter\Modules\SiteAudit\SiteAuditRepository;

/**
 * Site migration plans (1.2.0): collect the old addresses (pasted, from
 * Search Console or from the latest site audit), the new site's addresses,
 * suggest a redirect for each old address, let people correct them, test
 * the redirects in the background, export them as CSV, Apache or nginx
 * rules, and after launch compare Search Console clicks of each old
 * address before launch with its new address after launch.
 *
 * Controllers authorize the project (404 when not visible) before calling;
 * changes need Edit projects, redirect tests Run analyses.
 */
final readonly class SiteMigrationService
{
    public const int MAX_URLS = 20000;
    public const int MAX_TARGETS = 50000;
    public const int MAX_PLANS = 10;
    /** Days of Search Console clicks shown next to each old address, and compared before and after launch. */
    public const int CLICK_DAYS = 28;
    /** Post-launch results wait for this many days of data. */
    public const int MIN_DAYS_AFTER = 7;
    /** Results compare at most this many days after launch. */
    public const int MAX_DAYS_AFTER = 90;
    private const int RESERVE_SECONDS = 20;
    private const int BATCH = 25;
    private const int TESTS_PER_HOUR = 4;

    public function __construct(
        private SiteMigrationRepository $plans,
        private SiteMigrationScheduler $scheduler,
        private UrlProbe $probe,
        private SearchPerformanceReader $search,
        private SiteAuditRepository $audits,
        private Authorizer $auth,
        private FeatureManager $features,
        private RateLimiter $rateLimiter,
        private AuditLogger $audit,
        private Clock $clock,
        private LoggerInterface $logger,
    ) {}

    /** @return list<SiteMigration> */
    public function list(Project $project): array
    {
        return $this->plans->list($project->id);
    }

    public function get(Project $project, string $publicId): SiteMigration
    {
        return $this->plans->find($project->id, $publicId) ?? throw new NotFoundHttpException();
    }

    /**
     * @param array<string, mixed> $input name, old_origin, new_origin, launch_date
     */
    public function create(User $user, Project $project, array $input, ClientInfo $client): SiteMigration
    {
        $this->authorize($user, $project, Permission::ProjectsEdit);
        [$name, $old, $new, $launch] = $this->validate($project, $input);
        if (count($this->plans->list($project->id)) >= self::MAX_PLANS) {
            throw new ValidationException(['name' => sprintf('A project keeps up to %d migration plans. Delete one first.', self::MAX_PLANS)]);
        }
        $plan = $this->plans->create($project->id, $project->workspaceId, $name, $old, $new, $launch, $user->id);
        $this->record($user, $project, 'project.site_migration_created', $plan, ['old_origin' => $old, 'new_origin' => $new, 'launch_date' => $launch], $client);

        return $plan;
    }

    /**
     * @param array<string, mixed> $input
     */
    public function update(User $user, Project $project, SiteMigration $plan, array $input, ClientInfo $client): void
    {
        $this->authorize($user, $project, Permission::ProjectsEdit);
        [$name, $old, $new, $launch] = $this->validate($project, $input);
        $this->plans->update($plan->id, $name, $old, $new, $launch);
        $this->record($user, $project, 'project.site_migration_updated', $plan, ['old_origin' => $old, 'new_origin' => $new, 'launch_date' => $launch], $client);
    }

    public function delete(User $user, Project $project, SiteMigration $plan, ClientInfo $client): void
    {
        $this->authorize($user, $project, Permission::ProjectsEdit);
        $this->plans->delete($plan->id);
        $this->record($user, $project, 'project.site_migration_deleted', $plan, [], $client);
    }

    /**
     * Adds old addresses on the old origin's host. Each takes its Search
     * Console clicks of the CLICK_DAYS before launch (or before today).
     *
     * @param 'paste'|'search_console'|'site_audit' $source
     * @return array{added: int, ignored: int}
     */
    public function importOld(User $user, Project $project, SiteMigration $plan, string $source, string $text, ClientInfo $client): array
    {
        $this->authorize($user, $project, Permission::ProjectsEdit);
        $host = (string) parse_url($plan->oldOrigin, PHP_URL_HOST);
        $candidates = match ($source) {
            'paste' => self::lines($text, $plan->oldOrigin, self::MAX_URLS),
            'search_console' => array_map(static fn($row): string => $row->key, $this->search->top($project->id, PerformanceDimension::Page, $this->history($project), self::MAX_URLS * 2)),
            'site_audit' => $this->auditUrls($project),
        };
        $clicks = $this->clicks($project, $this->beforeLaunch($plan));
        $urls = [];
        $ignored = 0;
        foreach ($candidates as $url) {
            $url = UrlNormalizer::normalize($url);
            if ($url === null || UrlNormalizer::host($url) !== $host) {
                ++$ignored;
                continue;
            }
            $urls[$url] = ['url' => $url, 'clicks' => $clicks[$url] ?? ($clicks === [] ? null : 0)];
        }
        if ($urls === []) {
            throw new ValidationException(['urls' => sprintf('No address on %s was found. Old addresses must be on the old site’s host.', $host)]);
        }
        $added = $this->plans->addUrls($plan->id, array_values($urls), self::MAX_URLS);
        $this->record($user, $project, 'project.site_migration_urls_imported', $plan, ['source' => $source, 'added' => $added], $client);

        return ['added' => $added, 'ignored' => $ignored + count($urls) - $added];
    }

    /**
     * Replaces the new site's addresses (on the new origin's host).
     *
     * @param 'paste'|'site_audit' $source
     */
    public function importNew(User $user, Project $project, SiteMigration $plan, string $source, string $text, ClientInfo $client): int
    {
        $this->authorize($user, $project, Permission::ProjectsEdit);
        $host = (string) parse_url($plan->newOrigin, PHP_URL_HOST);
        $candidates = $source === 'paste' ? self::lines($text, $plan->newOrigin, self::MAX_TARGETS) : $this->auditUrls($project);
        $urls = [];
        foreach ($candidates as $url) {
            $url = UrlNormalizer::normalize($url);
            if ($url !== null && UrlNormalizer::host($url) === $host) {
                $urls[$url] = true;
            }
        }
        if ($urls === []) {
            throw new ValidationException(['targets' => sprintf('No address on %s was found. New addresses must be on the new site’s host.', $host)]);
        }
        $urls = array_slice(array_keys($urls), 0, self::MAX_TARGETS);
        $this->plans->replaceTargets($plan->id, $urls);
        $this->record($user, $project, 'project.site_migration_targets_imported', $plan, ['source' => $source, 'targets' => count($urls)], $client);

        return count($urls);
    }

    public function suggest(User $user, Project $project, SiteMigration $plan, ClientInfo $client): void
    {
        $this->authorize($user, $project, Permission::ProjectsEdit);
        if ($plan->busy()) {
            throw new HttpException(409, 'This plan is already being worked on. Wait until it finishes.');
        }
        if ($plan->urlCount === 0 || $plan->targetCount === 0) {
            throw new HttpException(422, 'Add the old addresses and the new site’s addresses first.');
        }
        $this->plans->setState($plan->id, 'match', SiteMigration::QUEUED);
        $this->scheduler->match($plan->id, $plan->workspaceId);
        $this->record($user, $project, 'project.site_migration_match_requested', $plan, [], $client);
    }

    public function test(User $user, Project $project, SiteMigration $plan, ClientInfo $client): void
    {
        $this->authorize($user, $project, Permission::AnalysisExecute);
        $this->features->require(Feature::SiteAudit);
        if ($plan->busy()) {
            throw new HttpException(409, 'This plan is already being worked on. Wait until it finishes.');
        }
        if ($plan->urlCount === 0) {
            throw new HttpException(422, 'Add the old addresses first.');
        }
        if (!$this->rateLimiter->hit('site-migration-test:project:' . $project->id, self::TESTS_PER_HOUR, 3600)->allowed) {
            throw new HttpException(429, 'Too many redirect tests for this project. Try again later.');
        }
        $this->plans->setState($plan->id, 'test', SiteMigration::QUEUED);
        $this->plans->saveTestCursor($plan->id, 0);
        $this->scheduler->test($plan->id, $plan->workspaceId, 0);
        $this->record($user, $project, 'project.site_migration_test_requested', $plan, ['urls' => $plan->urlCount], $client);
    }

    /** Sets or clears one address's target by hand; the target must be on the new site. */
    public function setTarget(User $user, Project $project, SiteMigration $plan, string $key, string $target): void
    {
        $this->authorize($user, $project, Permission::ProjectsEdit);
        $url = null;
        if (trim($target) !== '') {
            $url = UrlNormalizer::normalize(self::absolute(trim($target), $plan->newOrigin));
            if ($url === null || UrlNormalizer::host($url) !== (string) parse_url($plan->newOrigin, PHP_URL_HOST)) {
                throw new ValidationException(['target' => 'The target must be an address on ' . $plan->newOrigin . '.']);
            }
        }
        if (!$this->plans->setTarget($plan->id, $key, $url)) {
            throw new NotFoundHttpException();
        }
    }

    /** Job: suggests a target for every old address. */
    public function runMatch(int $planId): void
    {
        $plan = $this->plans->byId($planId);
        if ($plan === null || !in_array($plan->matchState, [SiteMigration::QUEUED, SiteMigration::RUNNING], true)) {
            return;
        }
        $this->plans->setState($plan->id, 'match', SiteMigration::RUNNING);
        $matcher = new RedirectMatcher($this->plans->targets($plan->id));
        $after = 0;
        $matched = 0;
        while (($rows = $this->plans->urlsAfter($plan->id, $after, 1000)) !== []) {
            $suggestions = [];
            foreach ($rows as $row) {
                $suggestions[$row->id] = $matcher->match($row->oldUrl);
                $matched += $suggestions[$row->id] !== null ? 1 : 0;
                $after = $row->id;
            }
            $this->plans->saveSuggestions($plan->id, $suggestions);
        }
        $this->plans->setState($plan->id, 'match', SiteMigration::DONE);
        $this->logger->info('Site migration redirects suggested', ['migration_id' => $plan->id, 'matched' => $matched]);
    }

    /**
     * Job: tests redirects from the saved cursor until done or time runs
     * short, then queues a continuation.
     *
     * @param \Closure(): int $secondsRemaining
     * @param (\Closure(): bool)|null $renewLease
     */
    public function runTest(int $planId, \Closure $secondsRemaining, ?\Closure $renewLease = null): void
    {
        $plan = $this->plans->byId($planId);
        if ($plan === null || !in_array($plan->testState, [SiteMigration::QUEUED, SiteMigration::RUNNING], true)) {
            return;
        }
        if (!$this->features->enabled(Feature::SiteAudit)) {
            $this->plans->setState($plan->id, 'test', SiteMigration::FAILED, 'Redirect tests need the Site audit feature, which is turned off.');

            return;
        }
        $this->plans->setState($plan->id, 'test', SiteMigration::RUNNING);
        $hosts = $plan->hosts();
        $cursor = $plan->testCursor;
        /** @var array<string, array{status: ?int, location: ?string, error: ?string, skipped?: bool}> $targets */
        $targets = [];
        while (true) {
            $rows = $this->plans->urlsAfter($plan->id, $cursor, self::BATCH);
            if ($rows === []) {
                $now = $this->clock->now()->format('Y-m-d H:i:s');
                $this->plans->saveTestCursor($plan->id, $cursor, $now);
                $this->plans->setState($plan->id, 'test', SiteMigration::DONE);

                return;
            }
            foreach ($rows as $row) {
                $old = $this->probe->probe($row->oldUrl, $hosts);
                $target = null;
                $location = $old['location'] ?? null;
                if ($location !== null && $old['status'] !== null && $old['status'] >= 300 && $old['status'] < 400) {
                    $target = $targets[$location] ??= $this->probe->probe($location, $hosts);
                }
                $grade = RedirectCheck::grade($old, $target, $row->targetUrl);
                $this->plans->saveTest($plan->id, $row->id, $grade['state'], $grade['detail'], $this->clock->now()->format('Y-m-d H:i:s'));
                $cursor = $row->id;
            }
            $this->plans->saveTestCursor($plan->id, $cursor);
            if ($secondsRemaining() < self::RESERVE_SECONDS || ($renewLease !== null && !$renewLease())) {
                $this->scheduler->test($plan->id, $plan->workspaceId, $cursor);

                return;
            }
        }
    }

    /** Job: marks a plan's work failed after its last attempt. */
    public function fail(int $planId, string $task, string $error): void
    {
        $this->plans->setState($planId, $task === 'match' ? 'match' : 'test', SiteMigration::FAILED, $error);
    }

    /**
     * After launch: Search Console clicks of each old address in the
     * CLICK_DAYS before launch against its new address since launch
     * (scaled to CLICK_DAYS). Null before launch or while fewer than
     * MIN_DAYS_AFTER days of data exist.
     *
     * @return array{before: DateRange, after: DateRange, beforeClicks: int, afterClicks: int, rows: list<array{url: MigrationUrl, before: int, after: int}>, waiting: ?string}|null
     */
    public function results(Project $project, SiteMigration $plan): ?array
    {
        if ($plan->launchDate === null) {
            return null;
        }
        $launch = new \DateTimeImmutable($plan->launchDate);
        $span = $this->search->dataSpan($project->id);
        $before = new DateRange($launch->modify('-' . self::CLICK_DAYS . ' days')->format('Y-m-d'), $launch->modify('-1 day')->format('Y-m-d'));
        $last = $span !== null ? min($span['last'], $launch->modify('+' . (self::MAX_DAYS_AFTER - 1) . ' days')->format('Y-m-d')) : null;
        $days = $last !== null && $last >= $plan->launchDate ? (int) $launch->diff(new \DateTimeImmutable($last))->days + 1 : 0;
        if ($days < self::MIN_DAYS_AFTER || $last === null) {
            $today = $this->clock->now()->format('Y-m-d');

            return ['before' => $before, 'after' => new DateRange($plan->launchDate, max($plan->launchDate, $today)), 'beforeClicks' => 0, 'afterClicks' => 0, 'rows' => [],
                'waiting' => $plan->launchDate > $today ? 'Results appear after launch.' : sprintf('Results appear once Search Console has %d days of data since launch (it has %d).', self::MIN_DAYS_AFTER, $days)];
        }
        $after = new DateRange($plan->launchDate, $last);
        $beforeClicks = $this->clicks($project, $before);
        $afterClicks = $this->clicks($project, $after);
        $scale = self::CLICK_DAYS / $days;
        $rows = [];
        $sumBefore = 0;
        $sumAfter = 0;
        $seen = [];
        foreach ($this->allUrls($plan) as $url) {
            $was = $beforeClicks[$url->oldUrl] ?? 0;
            // Clicks the old address kept (not redirected yet) count for it too.
            $now = (int) round($scale * (($url->targetUrl !== null ? ($afterClicks[$url->targetUrl] ?? 0) : 0) + ($afterClicks[$url->oldUrl] ?? 0)));
            $sumBefore += $was;
            if ($url->targetUrl === null || !isset($seen[$url->targetUrl])) {
                $sumAfter += $now;
            }
            if ($url->targetUrl !== null) {
                $seen[$url->targetUrl] = true;
            }
            if ($was > 0) {
                $rows[] = ['url' => $url, 'before' => $was, 'after' => $now];
            }
        }
        usort($rows, static fn(array $a, array $b): int => ($a['after'] - $a['before']) <=> ($b['after'] - $b['before']));

        return ['before' => $before, 'after' => $after, 'beforeClicks' => $sumBefore, 'afterClicks' => $sumAfter, 'rows' => array_slice($rows, 0, 50), 'waiting' => null];
    }

    /**
     * The chosen redirects as a download.
     *
     * @param 'csv'|'apache'|'nginx' $format
     * @return array{body: string, fileName: string, type: string}
     */
    public function export(Project $project, SiteMigration $plan, string $format): array
    {
        $rows = $this->allUrls($plan);
        $slug = trim((string) preg_replace('/[^a-z0-9]+/', '-', strtolower($plan->name)), '-') ?: 'migration';
        if ($format === 'csv') {
            $lines = [['old_url', 'target_url', 'suggested_url', 'match', 'confidence', 'search_clicks_28d', 'test', 'test_detail']];
            foreach ($rows as $r) {
                $lines[] = [$r->oldUrl, $r->targetUrl ?? '', $r->suggestedUrl ?? '', $r->manual ? 'manual' : ($r->method ?? ''), $r->confidence !== null ? (string) $r->confidence : '', $r->oldClicks !== null ? (string) $r->oldClicks : '', RedirectCheck::label($r->testState), $r->testDetail ?? ''];
            }

            return ['body' => \SeoControlCenter\Core\Http\Csv::encode($lines), 'fileName' => $slug . '-redirects.csv', 'type' => 'text/csv; charset=utf-8'];
        }
        $out = [$format === 'apache'
            ? '# Redirects for ' . $plan->name . ' (SEO Control Center). Put these in the old site’s .htaccess.'
            : '# Redirects for ' . $plan->name . ' (SEO Control Center). Put these in the old site’s server block.'];
        $skipped = 0;
        foreach ($rows as $r) {
            $path = (string) parse_url($r->oldUrl, PHP_URL_PATH);
            if ($r->targetUrl === null || $path === '' || str_contains($r->oldUrl, '?') || $r->targetUrl === $r->oldUrl) {
                $skipped += $r->targetUrl !== null && $r->targetUrl !== $r->oldUrl ? 1 : 0;
                continue;
            }
            $out[] = $format === 'apache'
                ? sprintf('Redirect 301 %s %s', self::directivePath($path), $r->targetUrl)
                : sprintf('location = %s { return 301 %s; }', self::directivePath($path), $r->targetUrl);
        }
        if ($skipped > 0) {
            $out[] = sprintf('# %d address%s with a query string cannot be written as a simple rule; see the CSV export.', $skipped, $skipped === 1 ? '' : 'es');
        }

        return ['body' => implode("\n", $out) . "\n", 'fileName' => $slug . ($format === 'apache' ? '-redirects.htaccess' : '-redirects.nginx.conf'), 'type' => 'text/plain; charset=utf-8'];
    }

    /**
     * @return array{rows: list<MigrationUrl>, total: int, counts: array<string, int>}
     */
    public function rows(SiteMigration $plan, ?string $filter, int $page, int $perPage): array
    {
        $filter = $filter !== null && ($filter === 'problems' || $filter === 'unmatched' || in_array($filter, [...RedirectCheck::PROBLEMS, RedirectCheck::OK, RedirectCheck::FAILED, RedirectCheck::SKIPPED, RedirectCheck::UNTESTED], true)) ? $filter : null;

        return [
            'rows' => $this->plans->urls($plan->id, $filter, ($page - 1) * $perPage, $perPage),
            'total' => $this->plans->countUrls($plan->id, $filter),
            'counts' => $this->plans->stateCounts($plan->id),
        ];
    }

    public function canEdit(User $user, Project $project): bool
    {
        return $this->auth->can($user->subject(), Permission::ProjectsEdit, $project->workspaceId, $project->id);
    }

    public function canTest(User $user, Project $project): bool
    {
        return $this->features->enabled(Feature::SiteAudit) && $this->auth->can($user->subject(), Permission::AnalysisExecute, $project->workspaceId, $project->id);
    }

    /**
     * @param array<string, mixed> $input
     * @return array{string, string, string, ?string}
     */
    private function validate(Project $project, array $input): array
    {
        $errors = [];
        $name = trim(is_string($input['name'] ?? null) ? $input['name'] : '');
        if ($name === '' || mb_strlen($name) > 120) {
            $errors['name'] = 'Give the plan a name of up to 120 characters.';
        }
        $origins = [];
        foreach (['old_origin' => 'old', 'new_origin' => 'new'] as $field => $label) {
            $origin = self::origin(is_string($input[$field] ?? null) ? $input[$field] : '');
            if ($origin === null) {
                $errors[$field] = sprintf('Enter the %s site’s address, such as https://www.example.com.', $label);
            }
            $origins[$field] = $origin;
        }
        if ($origins['old_origin'] !== null && $origins['new_origin'] !== null && !$this->owns($project, $origins['old_origin']) && !$this->owns($project, $origins['new_origin'])) {
            $errors['old_origin'] = sprintf('The old or the new site must be on this project’s domain (%s).', $project->primaryDomain);
        }
        $launch = trim(is_string($input['launch_date'] ?? null) ? $input['launch_date'] : '');
        if ($launch !== '' && (\DateTimeImmutable::createFromFormat('!Y-m-d', $launch) === false || \DateTimeImmutable::createFromFormat('!Y-m-d', $launch)->format('Y-m-d') !== $launch)) {
            $errors['launch_date'] = 'Enter the launch date as a date.';
        }
        if ($errors !== []) {
            throw new ValidationException($errors);
        }

        return [$name, (string) $origins['old_origin'], (string) $origins['new_origin'], $launch !== '' ? $launch : null];
    }

    /** https://host of an address, or null when it is not a public-looking HTTPS host. */
    private static function origin(string $value): ?string
    {
        $value = trim($value);
        if ($value !== '' && !str_contains($value, '://')) {
            $value = 'https://' . $value;
        }
        $url = UrlNormalizer::normalize($value);
        if ($url === null || !str_starts_with($url, 'https://') || parse_url($url, PHP_URL_PORT) !== null) {
            return null;
        }
        $host = UrlNormalizer::host($url);

        return preg_match('/^(?=.{4,253}$)([a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/', $host) === 1 ? 'https://' . $host : null;
    }

    private function owns(Project $project, string $origin): bool
    {
        $host = (string) preg_replace('/^www\./', '', (string) parse_url($origin, PHP_URL_HOST));
        $domain = (string) preg_replace('/^www\./', '', strtolower(trim((string) (parse_url(str_contains($project->primaryDomain, '://') ? $project->primaryDomain : 'https://' . $project->primaryDomain, PHP_URL_HOST) ?? ''))));

        return $domain !== '' && ($host === $domain || str_ends_with($host, '.' . $domain));
    }

    private function authorize(User $user, Project $project, Permission $permission): void
    {
        $this->auth->authorize($user->subject(), $permission, $project->workspaceId, $project->id);
    }

    /**
     * Pasted addresses, one per line; a line starting with / is a path on $origin.
     * A CSV line counts by its first column.
     *
     * @return list<string>
     */
    private static function lines(string $text, string $origin, int $max): array
    {
        $out = [];
        foreach (preg_split('/\R/', mb_substr($text, 0, 4_000_000)) ?: [] as $line) {
            $line = trim(explode(',', $line)[0], " \t\"'");
            if ($line === '') {
                continue;
            }
            $out[] = self::absolute($line, $origin);
            if (count($out) >= $max * 2) {
                break;
            }
        }

        return $out;
    }

    private static function absolute(string $value, string $origin): string
    {
        return str_starts_with($value, '/') && !str_starts_with($value, '//') ? $origin . $value : $value;
    }

    /** Apache and nginx read spaces as separators: encode them. */
    private static function directivePath(string $path): string
    {
        return str_replace([' ', '"'], ['%20', '%22'], $path);
    }

    /** @return list<string> fetched HTML pages of the latest completed site audit */
    private function auditUrls(Project $project): array
    {
        $audit = $this->audits->latest($project->id, 'completed');
        if ($audit === null) {
            throw new ValidationException(['source' => 'This project has no completed site audit yet.']);
        }

        return array_values(array_map(static fn(AuditPage $p): string => $p->url, array_filter($this->audits->allPages($audit->id), static fn(AuditPage $p): bool => $p->state === 'done' && $p->isHtml())));
    }

    /** @return array<string, int> page URL => Search Console clicks */
    private function clicks(Project $project, DateRange $range): array
    {
        $out = [];
        foreach ($this->search->top($project->id, PerformanceDimension::Page, $range, self::MAX_TARGETS) as $row) {
            $out[$row->key] = $row->metrics->clicks;
        }

        return $out;
    }

    /** The CLICK_DAYS before launch, or the latest CLICK_DAYS with data when launch is later or unset. */
    private function beforeLaunch(SiteMigration $plan): DateRange
    {
        $today = $this->clock->now()->format('Y-m-d');
        $end = $plan->launchDate !== null && $plan->launchDate <= $today ? (new \DateTimeImmutable($plan->launchDate))->modify('-1 day') : new \DateTimeImmutable($today);

        return new DateRange($end->modify('-' . (self::CLICK_DAYS - 1) . ' days')->format('Y-m-d'), $end->format('Y-m-d'));
    }

    /** The 16 months Search Console keeps. */
    private function history(Project $project): DateRange
    {
        $today = new \DateTimeImmutable($this->clock->now()->format('Y-m-d'));

        return new DateRange($today->modify('-16 months')->format('Y-m-d'), $today->format('Y-m-d'));
    }

    /** @return list<MigrationUrl> */
    private function allUrls(SiteMigration $plan): array
    {
        $all = [];
        $after = 0;
        while (($rows = $this->plans->urlsAfter($plan->id, $after, 2000)) !== []) {
            foreach ($rows as $row) {
                $all[] = $row;
                $after = $row->id;
            }
        }

        return $all;
    }

    /**
     * @param array<string, mixed> $metadata
     */
    private function record(User $user, Project $project, string $event, SiteMigration $plan, array $metadata, ClientInfo $client): void
    {
        $this->audit->record(new AuditEvent($event, ActorType::User, actorUserId: $user->id, actorLabel: $user->email, workspaceId: $project->workspaceId, targetType: 'project', targetId: $project->publicId, metadata: ['plan' => $plan->publicId, ...$metadata]), $client);
    }
}
