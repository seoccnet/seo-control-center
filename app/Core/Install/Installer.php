<?php

declare(strict_types=1);

namespace SeoControlCenter\Core\Install;

use Closure;
use SensitiveParameter;
use SeoControlCenter\Core\Audit\ActorType;
use SeoControlCenter\Core\Container\Container;
use SeoControlCenter\Core\Crypto\KeyRing;
use SeoControlCenter\Core\Crypto\KeyRingLoader;
use SeoControlCenter\Core\Crypto\KeyRingWriter;
use SeoControlCenter\Core\Database\Connection;
use SeoControlCenter\Core\Database\DatabaseConfig;
use SeoControlCenter\Core\Database\DatabaseException;
use SeoControlCenter\Core\Database\Migrations\Migrator;
use SeoControlCenter\Core\Security\PasswordHasher;
use SeoControlCenter\Core\Support\ClientInfo;
use SeoControlCenter\Core\Support\Paths;
use SeoControlCenter\Domain\Accounts\Email;
use SeoControlCenter\Domain\Accounts\UserProvisioning;
use SeoControlCenter\Domain\Shared\ValidationException;
use Throwable;

/**
 * One-time web installer for hosts without SSH (INSTALLATION: DEPLOYMENT.md).
 *
 * Steps: validate → test database → write .env → create key ring → migrate
 * → create administrator + workspace → lock. The setup key (read from
 * storage/setup-key.txt via FTP/File Manager) proves control of the server.
 */
final readonly class Installer
{
    /**
     * @param Closure(string): Container $bootstrap rebuilds the application after .env is written
     */
    public function __construct(
        private Paths $paths,
        private InstallState $state,
        private Requirements $requirements,
        private Closure $bootstrap,
    ) {}

    /**
     * @param array<array-key, mixed> $input form data
     * @return array{email: string, url: string, environment: string}
     * @throws ValidationException
     */
    public function install(#[SensitiveParameter] array $input, ClientInfo $client): array
    {
        if ($this->state->isInstalled()) {
            throw new ValidationException(['setup_key' => 'SEO Control Center is already installed.']);
        }
        $get = static fn(string $key): string => is_string($input[$key] ?? null) ? trim($input[$key]) : '';
        $errors = [];

        if (!hash_equals($this->state->setupKey(), strtolower($get('setup_key')))) {
            // Nothing else is checked until the key is right.
            throw new ValidationException(['setup_key' => 'The setup key does not match storage/setup-key.txt.']);
        }
        if (!$this->requirements->allPass()) {
            throw new ValidationException(['requirements' => 'Fix the failed server requirements first.']);
        }

        $environment = 'production';
        $url = rtrim($get('app_url'), '/');
        $parts = parse_url($url);
        if (!is_array($parts) || ($parts['scheme'] ?? '') !== 'https' || !isset($parts['host']) || isset($parts['path']) || isset($parts['query'])) {
            $errors['app_url'] = 'Enter the site address with https://, without a path (for example https://seo.example.com).';
        }
        if (Email::tryFrom($get('admin_email')) === null) {
            $errors['admin_email'] = 'Enter a valid e-mail address.';
        }
        if ($get('admin_name') === '' || mb_strlen($get('admin_name')) > 120) {
            $errors['admin_name'] = 'Enter your name.';
        }
        $password = is_string($input['admin_password'] ?? null) ? $input['admin_password'] : '';
        $passwordErrors = (new PasswordHasher())->validate($password);
        if ($passwordErrors !== []) {
            $errors['admin_password'] = implode(' ', $passwordErrors);
        } elseif ($password !== ($input['admin_password_confirm'] ?? null)) {
            $errors['admin_password_confirm'] = 'The passwords do not match.';
        }
        if ($get('workspace') === '' || mb_strlen($get('workspace')) > 120) {
            $errors['workspace'] = 'Enter a workspace name (for example your agency or company).';
        }
        foreach (['db_host', 'db_name', 'db_user'] as $field) {
            if ($get($field) === '') {
                $errors[$field] = 'Required.';
            }
        }
        $port = $get('db_port') === '' ? 3306 : (int) $get('db_port');
        if ($get('db_port') !== '' && filter_var($get('db_port'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 65535]]) === false) {
            $errors['db_port'] = 'Enter a port between 1 and 65535.';
        }
        $interval = $get('cron_interval') === '' ? '5' : $get('cron_interval');
        if (!in_array($interval, ['1', '5', '10', '15', '30', '60'], true)) {
            $errors['cron_interval'] = 'Choose a supported cron schedule.';
        }
        if ($errors !== []) {
            throw new ValidationException($errors);
        }

        $dbPassword = is_string($input['db_password'] ?? null) ? $input['db_password'] : '';
        $this->checkDatabase(new DatabaseConfig($get('db_host'), $port, $get('db_name'), $get('db_user'), $dbPassword));

        // From here on the installation is "pending" until the lock is written.
        $this->state->markPending();
        EnvWriter::write($this->state->envFile(), [
            'APP_ENV' => $environment,
            'APP_DEBUG' => 'false',
            'APP_URL' => $url,
            'APP_TIMEZONE' => 'UTC',
            'DB_HOST' => $get('db_host'),
            'DB_PORT' => (string) $port,
            'DB_DATABASE' => $get('db_name'),
            'DB_USERNAME' => $get('db_user'),
            'DB_PASSWORD' => $dbPassword,
            'LOG_LEVEL' => 'info',
            'CRON_INTERVAL_MINUTES' => $interval,
        ]);

        $app = ($this->bootstrap)($this->paths->base);
        $loader = $app->make(KeyRingLoader::class);
        if (!$loader->load()->isConfigured() && !$loader->usesEnvironment()) {
            KeyRingWriter::write(KeyRing::empty()->withNewActiveKey(sodium_crypto_aead_xchacha20poly1305_ietf_keygen()), $loader->path());
            $app = ($this->bootstrap)($this->paths->base); // reload with the new key ring
        }

        $app->make(Migrator::class)->migrate();
        $app->make(UserProvisioning::class)->createUser(
            $get('admin_email'),
            $get('admin_name'),
            $password,
            true,
            $get('workspace'),
            ActorType::System,
            $client,
        );

        $this->state->markInstalled();

        return ['email' => strtolower($get('admin_email')), 'url' => $url, 'environment' => $environment];
    }

    private function checkDatabase(DatabaseConfig $config): void
    {
        $db = new Connection($config);
        try {
            $version = $db->serverVersion();
        } catch (DatabaseException) {
            throw new ValidationException(['db_host' => 'Could not connect to the database with these details. Check host, database name, user and password (cPanel: MySQL Databases).']);
        }
        if (!$version->isSupported()) {
            throw new ValidationException(['db_host' => sprintf('%s is too old; %s %s or newer is required.', $version->describe(), $version->product(), $version->minimum())]);
        }
        try {
            $tables = (int) $db->fetchValue('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE()');
        } catch (Throwable) {
            throw new ValidationException(['db_name' => 'Could not check this database. Confirm the full names and ALL PRIVILEGES for this database user in cPanel.']);
        }
        // A previous attempt that stopped half-way may be continued (migrations are idempotent).
        if ($tables > 0 && !is_file($this->state->pendingFile())) {
            throw new ValidationException(['db_name' => 'This database already contains tables. Create a new empty database for SEO Control Center.']);
        }
        $db->disconnect();
    }
}
