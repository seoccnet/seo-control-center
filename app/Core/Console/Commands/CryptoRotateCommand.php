<?php

declare(strict_types=1);

namespace SeoControlCenter\Core\Console\Commands;

use SeoControlCenter\Core\Console\Command;
use SeoControlCenter\Core\Console\Output;
use SeoControlCenter\Core\Crypto\KeyRing;
use SeoControlCenter\Core\Crypto\SecretRotator;

/**
 * Shows how many secrets use each key and re-encrypts them with the active key.
 */
final readonly class CryptoRotateCommand implements Command
{
    public function __construct(
        private SecretRotator $rotator,
        private KeyRing $keys,
    ) {}

    public function name(): string
    {
        return 'crypto:rotate';
    }

    public function description(): string
    {
        return 'Re-encrypt stored secrets with the active key (use --status to only report)';
    }

    public function run(array $arguments, Output $output): int
    {
        $active = $this->keys->activeId();
        $output->line(sprintf('Active key: #%d', $active));
        foreach ($this->rotator->countByKey() as $id => $count) {
            $output->line(sprintf('  key #%d: %d value(s)%s', $id, $count, $id === $active ? ' (active)' : ''));
        }
        if (in_array('--status', $arguments, true)) {
            return 0;
        }

        $total = 0;
        do {
            $done = $this->rotator->rotateBatch(500);
            $total += $done;
        } while ($done > 0);
        $output->info(sprintf('Re-encrypted %d value(s).', $total));

        return 0;
    }
}
