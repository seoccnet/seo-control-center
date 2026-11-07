<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\System\Controllers;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UploadedFileInterface;
use SeoControlCenter\Core\Audit\ActorType;
use SeoControlCenter\Core\Audit\AuditEvent;
use SeoControlCenter\Core\Audit\AuditLogger;
use SeoControlCenter\Core\Authorization\Authorizer;
use SeoControlCenter\Core\Authorization\Permission;
use SeoControlCenter\Core\Database\Connection;
use SeoControlCenter\Core\Http\ResponseFactory;
use SeoControlCenter\Core\Support\ClientInfo;
use SeoControlCenter\Core\Update\ReleaseManifest;
use SeoControlCenter\Core\Update\UpdateException;
use SeoControlCenter\Core\Update\UpdateInstaller;
use SeoControlCenter\Core\Update\UpdateLock;
use SeoControlCenter\Core\View\ViewRenderer;
use SeoControlCenter\Modules\Shared\Http\WebContext;

/**
 * System → Updates (system.update): upload a signed release package, review
 * it, install it. The package is verified on upload and again right before
 * installing.
 */
final readonly class UpdatesController
{
    private const string PENDING = '/storage/updates/pending.zip';

    public function __construct(
        private UpdateInstaller $installer,
        private Authorizer $authorizer,
        private AuditLogger $audit,
        private Connection $db,
        private ResponseFactory $responses,
        private ViewRenderer $views,
        private string $appRoot,
        private string $currentVersion,
        private string $publicKey,
        private int $maxUploadMb,
    ) {}

    public function index(ServerRequestInterface $request): ResponseInterface
    {
        $this->authorize($request);
        $pending = null;
        $pendingError = null;
        if (is_file($this->appRoot . self::PENDING)) {
            try {
                $pending = $this->installer->verifyPackage($this->appRoot . self::PENDING, $this->publicKey, $this->currentVersion);
            } catch (UpdateException $e) {
                $pendingError = $e->getMessage();
            }
        }

        return $this->responses->html($this->views->render('system/updates', [
            'currentVersion' => $this->currentVersion,
            'pending' => $pending,
            'pendingError' => $pendingError,
            'state' => $this->installer->lastState(),
            'history' => $this->history(),
            'uploadLimitMb' => $this->uploadLimitMb(),
            'keyConfigured' => $this->publicKey !== '',
        ], $request))->withHeader('Cache-Control', 'no-store');
    }

    public function upload(ServerRequestInterface $request): ResponseInterface
    {
        $user = $this->authorize($request);
        $session = WebContext::session($request);
        $file = $request->getUploadedFiles()['package'] ?? null;
        if (!$file instanceof UploadedFileInterface || $file->getError() !== UPLOAD_ERR_OK) {
            $session->flash('error', 'No package received. Choose the .zip file (upload limit ' . $this->uploadLimitMb() . ' MB).');

            return $this->responses->redirect('/system/updates');
        }
        try {
            $manifest = UpdateLock::run($this->appRoot, function () use ($file): ReleaseManifest {
                $target = $this->appRoot . self::PENDING;
                $temporary = tempnam(dirname($target), 'upload-');
                if ($temporary === false) {
                    throw new UpdateException('Cannot stage the uploaded package.');
                }
                try {
                    $file->moveTo($temporary);
                    if (filesize($temporary) > $this->maxUploadMb * 1048576) {
                        throw new UpdateException('The uploaded package exceeds the configured size limit.');
                    }
                    $manifest = $this->installer->verifyPackage($temporary, $this->publicKey, $this->currentVersion);
                    if (!rename($temporary, $target)) {
                        throw new UpdateException('Cannot publish the uploaded package.');
                    }
                    return $manifest;
                } finally {
                    @unlink($temporary);
                }
            });
            $session->flash('status', sprintf('Package for version %s verified. Review it and install below.', $manifest->version));
            $this->record($request, $user, 'system.update_uploaded', true, ['version' => $manifest->version]);
        } catch (UpdateException $e) {
            $session->flash('error', 'Package refused: ' . $e->getMessage());
            $this->record($request, $user, 'system.update_uploaded', false, ['error' => mb_substr($e->getMessage(), 0, 200)]);
        }

        return $this->responses->redirect('/system/updates');
    }

    public function install(ServerRequestInterface $request): ResponseInterface
    {
        $this->authorize($request);
        try {
            return UpdateLock::run($this->appRoot, fn(): ResponseInterface => $this->installLocked($request));
        } catch (UpdateException $e) {
            WebContext::session($request)->flash('error', 'Update failed: ' . $e->getMessage());
            return $this->responses->redirect('/system/updates');
        }
    }

    private function installLocked(ServerRequestInterface $request): ResponseInterface
    {
        $user = $this->authorize($request);
        $session = WebContext::session($request);
        $input = $request->getParsedBody();
        if (!is_array($input) || ($input['backup_confirmed'] ?? null) !== '1') {
            $session->flash('error', 'Back up your database, application files and encryption keys before installing. Confirm your backup below.');
            return $this->responses->redirect('/system/updates');
        }
        $pending = $this->appRoot . self::PENDING;
        $started = gmdate('Y-m-d H:i:s');
        $manifest = null;
        try {
            if (!is_file($pending)) {
                throw new UpdateException('No package is waiting. Upload one first.');
            }
            $manifest = $this->installer->verifyPackage($pending, $this->publicKey, $this->currentVersion);
            $result = $this->installer->install($pending, $manifest);
            @unlink($pending);
            $this->recordHistory($manifest, 'completed', sprintf('%d files changed, %d removed, %d migration(s), backup %s', $result['changed'], $result['removed'], count($result['migrations']), $result['backup'] ?? 'none'), $user->id, $started);
            $this->record($request, $user, 'system.update_installed', true, ['from' => $this->originalVersion(), 'to' => $manifest->version]);
            $session->flash('status', sprintf('Updated to version %s: %d files changed, %d removed, %d database update(s).', $manifest->version, $result['changed'], $result['removed'], count($result['migrations'])));
        } catch (UpdateException $e) {
            if ($manifest !== null) {
                $this->recordHistory($manifest, 'failed', $e->getMessage(), $user->id, $started);
            }
            $this->record($request, $user, 'system.update_installed', false, ['error' => mb_substr($e->getMessage(), 0, 200)]);
            $session->flash('error', 'Update failed: ' . $e->getMessage());
        }

        return $this->responses->redirect('/system/updates');
    }

    public function discard(ServerRequestInterface $request): ResponseInterface
    {
        $this->authorize($request);
        try {
            UpdateLock::run($this->appRoot, function (): void {
                $state = $this->installer->lastState();
                if (is_array($state) && in_array($state['status'] ?? null, ['running', 'failed'], true) && ($state['applying'] ?? false) === true) {
                    throw new UpdateException('Keep the original package until the interrupted update has been recovered.');
                }
                $path = $this->appRoot . self::PENDING;
                if (is_file($path) && !@unlink($path)) {
                    throw new UpdateException('Cannot remove the pending package.');
                }
            });
            WebContext::session($request)->flash('status', 'The uploaded package was removed.');
        } catch (UpdateException $e) {
            WebContext::session($request)->flash('error', $e->getMessage());
        }

        return $this->responses->redirect('/system/updates');
    }

    private function authorize(ServerRequestInterface $request): \SeoControlCenter\Domain\Accounts\User
    {
        $user = WebContext::user($request);
        $this->authorizer->authorize($user->subject(), Permission::SystemUpdate);

        return $user;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function history(): array
    {
        try {
            return $this->db->fetchAll('SELECT from_version, to_version, status, message, finished_at FROM update_history ORDER BY id DESC LIMIT 20');
        } catch (\Throwable) {
            return []; // table not migrated yet (first update to 0.8)
        }
    }

    private function recordHistory(ReleaseManifest $manifest, string $status, string $message, int $userId, string $started): void
    {
        try {
            $this->db->execute(
                'INSERT INTO update_history (from_version, to_version, status, message, installed_by, started_at, finished_at) VALUES (?, ?, ?, ?, ?, ?, ?)',
                [$this->originalVersion(), $manifest->version, $status, mb_substr($message, 0, 1000), $userId, $started, gmdate('Y-m-d H:i:s')],
            );
        } catch (\Throwable) {
            // The history table arrives with the update itself; the state file and audit log still record it.
        }
    }

    private function originalVersion(): string
    {
        $state = $this->installer->lastState();
        return is_string($state['from_version'] ?? null) ? $state['from_version'] : $this->currentVersion;
    }

    /**
     * @param array<string, mixed> $metadata
     */
    private function record(ServerRequestInterface $request, \SeoControlCenter\Domain\Accounts\User $user, string $action, bool $success, array $metadata): void
    {
        $this->audit->record(new AuditEvent($action, ActorType::User, actorUserId: $user->id, actorLabel: $user->email, success: $success, metadata: $metadata), ClientInfo::fromRequest($request));
    }

    private function uploadLimitMb(): int
    {
        $toMb = static function (string $value): int {
            $number = (int) $value;

            return match (strtolower(substr(trim($value), -1))) {
                'g' => $number * 1024,
                'k' => max(1, intdiv($number, 1024)),
                'm' => $number,
                default => max(1, intdiv($number, 1048576)),
            };
        };
        $limits = array_filter([$toMb((string) ini_get('upload_max_filesize')), $toMb((string) ini_get('post_max_size')), $this->maxUploadMb], static fn(int $v): bool => $v > 0);

        return $limits === [] ? $this->maxUploadMb : min($limits);
    }
}
