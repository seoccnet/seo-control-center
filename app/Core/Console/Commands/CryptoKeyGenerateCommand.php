<?php

declare(strict_types=1);

namespace SeoControlCenter\Core\Console\Commands;

use SeoControlCenter\Core\Console\Command;
use SeoControlCenter\Core\Console\Output;
use SeoControlCenter\Core\Crypto\CryptoException;
use SeoControlCenter\Core\Crypto\KeyRingLoader;
use SeoControlCenter\Core\Crypto\KeyRingWriter;

/**
 * Creates the key ring or adds a new active key (rotation step 1, SECURITY.md -> Rotation).
 */
final readonly class CryptoKeyGenerateCommand implements Command
{
    public function __construct(private KeyRingLoader $loader) {}

    public function name(): string
    {
        return 'crypto:key:generate';
    }

    public function description(): string
    {
        return 'Create the encryption key ring, or add a new active key for rotation';
    }

    public function run(array $arguments, Output $output): int
    {
        if ($this->loader->usesEnvironment()) {
            $output->error('Keys come from APP_KEYS in the environment. Generate a key with:');
            $output->line('  php -r "echo base64_encode(random_bytes(32)), PHP_EOL;"');
            $output->line('then append ",<next id>:<key>" to APP_KEYS and set APP_ACTIVE_KEY_ID.');

            return 1;
        }

        $current = $this->loader->load();
        $next = $current->withNewActiveKey(sodium_crypto_aead_xchacha20poly1305_ietf_keygen());
        $path = $this->loader->path();

        try {
            KeyRingWriter::write($next, $path);
        } catch (CryptoException $e) {
            $output->error($e->getMessage());

            return 1;
        }

        $output->info(sprintf('Key #%d is now active. Key ring: %s', $next->activeId(), $path));
        if ($current->isConfigured()) {
            $output->line('Existing secrets still use older keys. Re-encrypt them with: php bin/console crypto:rotate');
        }
        $output->warning('Back up this file separately from your database backups. Without it, stored integration credentials cannot be decrypted.');

        return 0;
    }
}
