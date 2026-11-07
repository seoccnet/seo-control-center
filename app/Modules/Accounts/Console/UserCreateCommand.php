<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\Accounts\Console;

use SeoControlCenter\Core\Audit\ActorType;
use SeoControlCenter\Core\Console\Command;
use SeoControlCenter\Core\Console\Output;
use SeoControlCenter\Core\Support\ClientInfo;
use SeoControlCenter\Domain\Accounts\UserProvisioning;
use SeoControlCenter\Domain\Shared\ValidationException;

/**
 * Creates a user from the command line (until the installer and invitations exist).
 *
 *   php bin/console user:create admin@example.com "Jane Admin" --system-admin --workspace="Acme"
 *
 * A strong random password is generated and shown once, unless
 * --password-stdin is given, in which case the first line of STDIN is used.
 */
final readonly class UserCreateCommand implements Command
{
    /**
     * @param (\Closure(): string)|null $stdinReader
     */
    public function __construct(
        private UserProvisioning $provisioning,
        private ?\Closure $stdinReader = null,
    ) {}

    public function name(): string
    {
        return 'user:create';
    }

    public function description(): string
    {
        return 'Create a user: user:create <email> <name> [--system-admin] [--workspace=Name] [--password-stdin]';
    }

    public function run(array $arguments, Output $output): int
    {
        $positional = [];
        $systemAdmin = false;
        $workspace = null;
        $passwordFromStdin = false;
        foreach ($arguments as $argument) {
            if ($argument === '--system-admin') {
                $systemAdmin = true;
            } elseif (str_starts_with($argument, '--workspace=')) {
                $workspace = substr($argument, strlen('--workspace='));
            } elseif ($argument === '--password-stdin') {
                $passwordFromStdin = true;
            } elseif (str_starts_with($argument, '--')) {
                $output->error('Unknown option ' . $argument);

                return 1;
            } else {
                $positional[] = $argument;
            }
        }
        if (count($positional) !== 2) {
            $output->error('Usage: php bin/console user:create <email> "<name>" [--system-admin] [--workspace="Name"] [--password-stdin]');

            return 1;
        }

        $generated = !$passwordFromStdin;
        $password = $generated ? $this->generatePassword() : trim(($this->stdinReader ?? self::readStdin(...))());

        try {
            $result = $this->provisioning->createUser(
                $positional[0],
                $positional[1],
                $password,
                $systemAdmin,
                $workspace,
                ActorType::Cli,
                ClientInfo::cli(),
            );
        } catch (ValidationException $e) {
            foreach ($e->errors() as $field => $message) {
                $output->error(sprintf('%s: %s', $field, $message));
            }

            return 1;
        }

        $output->info(sprintf('Created user %s (%s)%s.', $result['user']->email, $result['user']->publicId, $systemAdmin ? ' with system admin rights' : ''));
        if ($result['workspace'] !== null) {
            $output->info(sprintf('Created workspace "%s" with this user as owner.', $result['workspace']->name));
        }
        if ($generated) {
            $output->line('');
            $output->line('Password (shown once, store it in your password manager):');
            $output->line('  ' . $password);
        }

        return 0;
    }

    private function generatePassword(): string
    {
        // 20 characters from an unambiguous alphabet ≈ 113 bits of entropy.
        $alphabet = 'abcdefghjkmnpqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        $password = '';
        for ($i = 0; $i < 20; ++$i) {
            $password .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        }

        return $password;
    }

    private static function readStdin(): string
    {
        $line = fgets(STDIN);

        return $line === false ? '' : $line;
    }
}
