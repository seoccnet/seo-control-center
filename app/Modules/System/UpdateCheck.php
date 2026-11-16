<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\System;

use SeoControlCenter\Core\Audit\ActorType;
use SeoControlCenter\Core\Audit\AuditEvent;
use SeoControlCenter\Core\Audit\AuditLogger;
use SeoControlCenter\Core\Authorization\Authorizer;
use SeoControlCenter\Core\Authorization\Permission;
use SeoControlCenter\Core\Http\Client\HttpClient;
use SeoControlCenter\Core\Http\Client\HttpClientException;
use SeoControlCenter\Core\Http\Exception\HttpException;
use SeoControlCenter\Core\RateLimit\RateLimiter;
use SeoControlCenter\Core\Support\ClientInfo;
use SeoControlCenter\Core\Support\Clock;
use SeoControlCenter\Domain\Accounts\User;

/**
 * Optional check for new versions on GitHub Releases (ADR-053). Off by
 * default; an administrator turns it on under System → Updates. Once a day
 * (and on "Check now") it asks GitHub's public API for the latest release
 * of the configured repository. The request carries no installation ID,
 * version, domain or any other data about the installation; GitHub sees
 * the server's IP address, as with any download. Nothing is downloaded or
 * installed: updates stay a manual upload of a signed package.
 */
final readonly class UpdateCheck
{
    public const string STATE = 'update_check';
    private const string API = 'https://api.github.com/repos/%s/releases/latest';
    private const int CHECKS_PER_HOUR = 6;

    public function __construct(
        private UpdateCheckStore $state,
        private HttpClient $http,
        private Authorizer $auth,
        private RateLimiter $rateLimiter,
        private AuditLogger $audit,
        private Clock $clock,
        private string $repository,
        private string $currentVersion,
    ) {}

    /**
     * @return array{enabled: bool, repository: string, checkedAt: ?string, latest: ?array{version: string, name: string, url: string, publishedAt: ?string}, newer: bool, error: ?string}
     */
    public function status(): array
    {
        $stored = $this->state->load();
        $latest = is_array($stored['latest'] ?? null) && is_string($stored['latest']['version'] ?? null) ? [
            'version' => $stored['latest']['version'],
            'name' => is_string($stored['latest']['name'] ?? null) ? $stored['latest']['name'] : $stored['latest']['version'],
            'url' => is_string($stored['latest']['url'] ?? null) ? $stored['latest']['url'] : '',
            'publishedAt' => is_string($stored['latest']['publishedAt'] ?? null) ? $stored['latest']['publishedAt'] : null,
        ] : null;

        return [
            'enabled' => ($stored['enabled'] ?? false) === true,
            'repository' => $this->repository,
            'checkedAt' => is_string($stored['checkedAt'] ?? null) ? $stored['checkedAt'] : null,
            'latest' => $latest,
            'newer' => $latest !== null && version_compare($latest['version'], $this->currentVersion, '>'),
            'error' => is_string($stored['error'] ?? null) ? $stored['error'] : null,
        ];
    }

    public function setEnabled(User $user, bool $enabled, ClientInfo $client): void
    {
        $this->auth->authorize($user->subject(), Permission::SystemUpdate);
        $this->state->save([...$this->state->load(), 'enabled' => $enabled]);
        $this->audit->record(new AuditEvent($enabled ? 'system.update_check_enabled' : 'system.update_check_disabled', ActorType::User, actorUserId: $user->id, actorLabel: $user->email, metadata: ['repository' => $this->repository]), $client);
        if ($enabled) {
            $this->check();
        }
    }

    public function checkNow(User $user): void
    {
        $this->auth->authorize($user->subject(), Permission::SystemUpdate);
        if (!$this->status()['enabled']) {
            throw new HttpException(422, 'Turn the update check on first.');
        }
        if (!$this->rateLimiter->hit('update-check', self::CHECKS_PER_HOUR, 3600)->allowed) {
            throw new HttpException(429, 'Checked a few times this hour already. Try again later.');
        }
        $this->check();
    }

    /** Job and "Check now": asks GitHub when the check is on; does nothing otherwise. */
    public function check(): void
    {
        $status = $this->status();
        if (!$status['enabled']) {
            return;
        }
        $result = ['enabled' => true, 'checkedAt' => $this->clock->now()->format('Y-m-d H:i:s'), 'latest' => $status['latest'], 'error' => null];
        if (preg_match('#^[A-Za-z0-9-]{1,39}/[A-Za-z0-9._-]{1,100}$#', $this->repository) !== 1) {
            $result['error'] = 'UPDATE_CHECK_REPOSITORY is not an owner/name GitHub repository.';
            $this->state->save($result);

            return;
        }
        try {
            // A generic user agent: GitHub requires one, and it says nothing about this installation.
            $response = $this->http->send('GET', sprintf(self::API, $this->repository), ['Accept' => 'application/vnd.github+json', 'User-Agent' => 'SEO-Control-Center-update-check', 'X-GitHub-Api-Version' => '2022-11-28'], null, ['api.github.com'], 256 * 1024);
            $data = $response->status === 200 ? json_decode($response->body, true) : null;
            $version = is_array($data) && is_string($data['tag_name'] ?? null) ? ltrim($data['tag_name'], 'vV') : null;
            if ($version === null || preg_match('/^\d+\.\d+\.\d+(?:[-+][0-9A-Za-z.-]+)?$/', $version) !== 1) {
                $result['error'] = $response->status === 404 ? 'The repository has no published release.' : sprintf('GitHub answered HTTP %d without a release version.', $response->status);
            } else {
                $url = is_string($data['html_url'] ?? null) && str_starts_with($data['html_url'], 'https://github.com/') ? $data['html_url'] : 'https://github.com/' . $this->repository . '/releases';
                $result['latest'] = ['version' => $version, 'name' => is_string($data['name'] ?? null) && $data['name'] !== '' ? mb_substr($data['name'], 0, 200) : $version, 'url' => $url, 'publishedAt' => is_string($data['published_at'] ?? null) ? $data['published_at'] : null];
            }
        } catch (HttpClientException) {
            $result['error'] = 'GitHub could not be reached. The next check tries again.';
        }
        $this->state->save($result);
    }
}
