<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\IndexNow;

use Psr\Log\LoggerInterface;
use SeoControlCenter\Core\Audit\ActorType;
use SeoControlCenter\Core\Audit\AuditEvent;
use SeoControlCenter\Core\Audit\AuditLogger;
use SeoControlCenter\Core\Authorization\Authorizer;
use SeoControlCenter\Core\Authorization\Permission;
use SeoControlCenter\Core\Features\Feature;
use SeoControlCenter\Core\Features\FeatureManager;
use SeoControlCenter\Core\Http\Exception\HttpException;
use SeoControlCenter\Core\RateLimit\RateLimiter;
use SeoControlCenter\Core\Support\ClientInfo;
use SeoControlCenter\Domain\Accounts\User;
use SeoControlCenter\Domain\Integrations\IntegrationException;
use SeoControlCenter\Domain\Projects\Project;
use SeoControlCenter\Domain\Shared\ValidationException;
use SeoControlCenter\Modules\IndexNow\Api\IndexNowClient;

/**
 * IndexNow for a project (integrations.manage): a key the site serves as
 * {key}.txt, manual URL submissions, and automatic submission of new and
 * changed sitemap URLs. Submissions and sitemap checks run as jobs.
 */
final readonly class IndexNowService
{
    public const int MAX_MANUAL_URLS = 1000;
    /** Child sitemaps read from a sitemap index per check. */
    private const int MAX_CHILD_SITEMAPS = 50;
    private const int MAX_SITEMAP_URLS = 50000;

    public function __construct(
        private IndexNowRepository $repository,
        private IndexNowClient $client,
        private IndexNowScheduler $scheduler,
        private Authorizer $authorizer,
        private RateLimiter $rateLimiter,
        private AuditLogger $audit,
        private FeatureManager $features,
        private LoggerInterface $logger,
    ) {}

    /**
     * @return array{settings: ?IndexNowSettings, submissions: list<array{source: string, url_count: int, status_code: ?int, result: string, created_at: string}>, hosts: list<string>}
     */
    public function page(Project $project): array
    {
        return [
            'settings' => $this->repository->settings($project->id),
            'submissions' => $this->repository->submissions($project->id, 20),
            'hosts' => self::hosts($project),
        ];
    }

    public function setUp(User $user, Project $project, ClientInfo $client): IndexNowSettings
    {
        $this->authorize($user, $project);
        $this->features->require(Feature::BingWebmaster);
        $this->repository->create($project->id, $project->workspaceId, bin2hex(random_bytes(16)));
        $this->audit->record(new AuditEvent('project.indexnow_key_created', ActorType::User, actorUserId: $user->id, actorLabel: $user->email, workspaceId: $project->workspaceId, targetType: 'project', targetId: $project->publicId), $client);

        return $this->repository->settings($project->id) ?? throw new \RuntimeException('IndexNow settings were not saved.');
    }

    /**
     * Looks for {key}.txt on the project's domain (with and without www), at
     * the site root or under $path (for example "/blog/" for a WordPress site
     * whose Home address is in a subdirectory).
     *
     * @return string the key file URL that serves the key
     * @throws ValidationException when the path is invalid or no host serves the key
     */
    public function verify(User $user, Project $project, string $path = ''): string
    {
        $this->authorize($user, $project);
        $settings = $this->repository->settings($project->id) ?? throw new HttpException(409, 'Set up IndexNow first.');
        $keyPath = IndexNowSettings::path($path) ?? throw new ValidationException(['key_path' => 'Enter a site path such as /blog/ (letters, digits, dots, hyphens, underscores and tildes), or leave it empty for the site root.']);
        if (!$this->rateLimiter->hit('indexnow:verify:project:' . $project->id, 10, 600)->allowed) {
            throw new HttpException(429, 'Too many checks. Try again in a few minutes.');
        }
        $problems = [];
        foreach (self::hosts($project) as $host) {
            try {
                if ($this->client->keyFileServed($host, $settings->key, $keyPath)) {
                    $this->repository->markVerified($project->id, $host, $keyPath);

                    return 'https://' . $host . $keyPath . $settings->fileName();
                }
                $problems[] = sprintf('https://%s%s%s does not serve the key.', $host, $keyPath, $settings->fileName());
            } catch (IntegrationException $e) {
                $problems[] = $e->getMessage();
            }
        }
        throw new ValidationException(['key' => 'The key file was not found. ' . implode(' ', $problems)]);
    }

    /**
     * @throws ValidationException
     */
    public function saveSitemap(User $user, Project $project, string $sitemapUrl, bool $autoSubmit, ClientInfo $client): void
    {
        $this->authorize($user, $project);
        $settings = $this->verifiedSettings($project);
        $sitemapUrl = trim($sitemapUrl);
        if ($sitemapUrl !== '' && !self::onHost($sitemapUrl, (string) $settings->host)) {
            throw new ValidationException(['sitemap_url' => sprintf('Enter the sitemap address on https://%s/ (for example https://%s/sitemap.xml).', $settings->host, $settings->host)]);
        }
        if ($autoSubmit && $sitemapUrl === '') {
            throw new ValidationException(['sitemap_url' => 'Automatic submissions need the sitemap address.']);
        }
        $this->repository->saveSitemap($project->id, $sitemapUrl !== '' ? $sitemapUrl : null, $autoSubmit);
        $this->audit->record(new AuditEvent('project.indexnow_sitemap_saved', ActorType::User, actorUserId: $user->id, actorLabel: $user->email, workspaceId: $project->workspaceId, targetType: 'project', targetId: $project->publicId, metadata: [
            'sitemap' => $sitemapUrl !== '' ? $sitemapUrl : null,
            'auto_submit' => $autoSubmit,
        ]), $client);
        if ($autoSubmit) {
            $this->scheduler->checkSitemap($project->id, $project->workspaceId);
        }
    }

    /**
     * Queues the submission of up to MAX_MANUAL_URLS URLs (one per line).
     *
     * @return int the number of URLs queued
     * @throws ValidationException
     */
    public function submitUrls(User $user, Project $project, string $text, ClientInfo $client): int
    {
        $this->authorize($user, $project);
        $this->features->require(Feature::BingWebmaster);
        $settings = $this->verifiedSettings($project);
        $urls = [];
        $invalid = [];
        foreach (preg_split('/\R/', $text) ?: [] as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            if (!$settings->covers($line)) {
                $invalid[] = $line;
                continue;
            }
            $urls[$line] = true;
        }
        if ($invalid !== []) {
            throw new ValidationException(['urls' => sprintf('Only addresses on https://%s%s can be submitted; %d line(s) are not: %s', $settings->host, $settings->keyPath, count($invalid), mb_substr(implode(', ', array_slice($invalid, 0, 3)), 0, 300))]);
        }
        if ($urls === [] || count($urls) > self::MAX_MANUAL_URLS) {
            throw new ValidationException(['urls' => sprintf('Enter between 1 and %d addresses, one per line.', self::MAX_MANUAL_URLS)]);
        }
        $this->scheduler->submit($project->id, $project->workspaceId, array_keys($urls), $user->id);
        $this->audit->record(new AuditEvent('project.indexnow_submitted', ActorType::User, actorUserId: $user->id, actorLabel: $user->email, workspaceId: $project->workspaceId, targetType: 'project', targetId: $project->publicId, metadata: [
            'urls' => count($urls),
        ]), $client);

        return count($urls);
    }

    /**
     * Job: submits URLs and logs the answer.
     *
     * @param list<string> $urls
     * @throws IntegrationException when IndexNow is temporarily unavailable (the job retries)
     */
    public function runSubmit(int $projectId, array $urls, string $source, ?int $userId): void
    {
        $settings = $this->repository->settings($projectId);
        if ($settings === null || !$settings->isVerified() || $urls === []) {
            return;
        }
        $urls = array_values(array_filter($urls, $settings->covers(...)));
        if ($urls === []) {
            // The key was re-verified for another host or path after these URLs were queued.
            $this->repository->logSubmission($projectId, $source, 0, null, 'Not submitted: no URL is covered by the verified key file.', $userId);

            return;
        }
        try {
            $result = $this->client->submit((string) $settings->host, $settings->key, $urls, $settings->keyPath);
        } catch (IntegrationException $e) {
            if ($e->retryable) {
                throw $e;
            }
            $this->repository->logSubmission($projectId, $source, count($urls), null, $e->getMessage(), $userId);

            return;
        }
        $this->repository->logSubmission($projectId, $source, count($urls), $result['status'], $result['message'], $userId);
    }

    /**
     * Job: reads the sitemap and submits new and changed URLs. The first check
     * only records what is there, so an existing site is not submitted at once.
     *
     * @throws IntegrationException when the site or IndexNow is temporarily unavailable
     */
    public function runSitemap(int $projectId): void
    {
        $settings = $this->repository->settings($projectId);
        if ($settings === null || !$settings->isVerified() || $settings->sitemapUrl === null) {
            return;
        }
        $host = (string) $settings->host;
        try {
            $found = $this->readSitemap($settings->sitemapUrl, $settings);
        } catch (IntegrationException $e) {
            $this->repository->recordSitemapCheck($projectId, $e->getMessage());
            if ($e->retryable) {
                throw $e;
            }

            return;
        } catch (\InvalidArgumentException $e) {
            $this->repository->recordSitemapCheck($projectId, $e->getMessage());

            return;
        }

        $known = $this->repository->knownUrls($projectId);
        $changed = [];
        if ($known !== []) {
            foreach ($found as $url) {
                if (!array_key_exists($url['loc'], $known) || ($url['lastmod'] !== null && $url['lastmod'] !== $known[$url['loc']])) {
                    $changed[] = $url['loc'];
                }
            }
        }
        $changed = array_slice($changed, 0, IndexNowClient::MAX_URLS);
        $submitted = [];
        if ($changed !== []) {
            try {
                $result = $this->client->submit($host, $settings->key, $changed, $settings->keyPath);
                $this->repository->logSubmission($projectId, 'sitemap', count($changed), $result['status'], $result['message'], null);
                $submitted = $result['ok'] ? $changed : [];
            } catch (IntegrationException $e) {
                $this->repository->recordSitemapCheck($projectId, $e->getMessage());
                if ($e->retryable) {
                    throw $e;
                }
                $this->repository->logSubmission($projectId, 'sitemap', count($changed), null, $e->getMessage(), null);

                return;
            }
        }
        // URLs whose submission failed keep their old lastmod, so the next check offers them again.
        $failed = array_flip(array_diff($changed, $submitted));
        $this->repository->rememberUrls($projectId, array_values(array_filter($found, static fn(array $u): bool => !isset($failed[$u['loc']]))), $submitted);
        $this->repository->recordSitemapCheck($projectId, null);
        $this->logger->info('IndexNow sitemap checked', ['project_id' => $projectId, 'urls' => count($found), 'submitted' => count($submitted), 'baseline' => $known === []]);
    }

    /**
     * @return list<array{loc: string, lastmod: ?string}>
     * @throws IntegrationException|\InvalidArgumentException
     */
    private function readSitemap(string $sitemapUrl, IndexNowSettings $settings): array
    {
        $host = (string) $settings->host;
        $document = SitemapParser::parse($this->client->fetch($sitemapUrl));
        $urls = $document['urls'];
        foreach (array_slice(array_values(array_filter($document['sitemaps'], static fn(string $u): bool => self::onHost($u, $host))), 0, self::MAX_CHILD_SITEMAPS) as $child) {
            $urls = [...$urls, ...SitemapParser::parse($this->client->fetch($child))['urls']];
            if (count($urls) >= self::MAX_SITEMAP_URLS) {
                break;
            }
        }
        $unique = [];
        // The sitemap may live anywhere on the host; only URLs the key covers are tracked.
        foreach ($urls as $url) {
            if ($settings->covers($url['loc'])) {
                $unique[$url['loc']] = $url;
            }
        }

        return array_slice(array_values($unique), 0, self::MAX_SITEMAP_URLS);
    }

    private function verifiedSettings(Project $project): IndexNowSettings
    {
        $settings = $this->repository->settings($project->id);
        if ($settings === null || !$settings->isVerified()) {
            throw new HttpException(409, 'Put the key file on your site and check it first.');
        }

        return $settings;
    }

    private function authorize(User $user, Project $project): void
    {
        $this->authorizer->authorize($user->subject(), Permission::IntegrationsManage, $project->workspaceId, $project->id);
    }

    /**
     * The project's domain with and without "www.".
     *
     * @return list<string>
     */
    public static function hosts(Project $project): array
    {
        $domain = strtolower(trim($project->primaryDomain));
        $bare = str_starts_with($domain, 'www.') ? substr($domain, 4) : $domain;

        return array_values(array_unique([$domain, $bare, 'www.' . $bare]));
    }

    private static function onHost(string $url, string $host): bool
    {
        $parts = parse_url($url);

        return is_array($parts) && in_array(strtolower((string) ($parts['scheme'] ?? '')), ['http', 'https'], true)
            && strtolower((string) ($parts['host'] ?? '')) === $host && !isset($parts['user']) && strlen($url) <= 2048;
    }
}
