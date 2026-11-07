<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\Mail\Persistence;

use SeoControlCenter\Core\Crypto\Encrypter;
use SeoControlCenter\Core\Database\Connection;
use SeoControlCenter\Core\Mail\MailSettings;
use SeoControlCenter\Core\Mail\MailSettingsRepository;
use SeoControlCenter\Core\Support\Clock;

/**
 * The single row of `mail_settings`. The SMTP password is encrypted with the
 * key ring (context `mail.smtp`, ADR-014/027).
 */
final readonly class PdoMailSettingsRepository implements MailSettingsRepository
{
    public const string CONTEXT = 'mail.smtp';

    public function __construct(
        private Connection $db,
        private Encrypter $encrypter,
        private Clock $clock,
    ) {}

    public function get(): MailSettings
    {
        $row = $this->db->fetchOne('SELECT transport, host, port, encryption, username, password, from_address, from_name FROM mail_settings WHERE id = 1');
        if ($row === null) {
            return new MailSettings();
        }

        return new MailSettings(
            (string) $row['transport'],
            $row['host'] !== null ? (string) $row['host'] : null,
            $row['port'] !== null ? (int) $row['port'] : null,
            (string) $row['encryption'],
            $row['username'] !== null ? (string) $row['username'] : null,
            $row['password'] !== null ? $this->encrypter->decrypt((string) $row['password'], self::CONTEXT) : null,
            $row['from_address'] !== null ? (string) $row['from_address'] : null,
            $row['from_name'] !== null ? (string) $row['from_name'] : null,
        );
    }

    public function save(MailSettings $settings, bool $keepPassword, int $updatedBy): void
    {
        $password = $settings->password !== null && $settings->password !== ''
            ? $this->encrypter->encrypt($settings->password, self::CONTEXT)
            : null;
        $now = $this->clock->now()->format('Y-m-d H:i:s');
        $values = [
            $settings->transport, $settings->host, $settings->port, $settings->encryption,
            $settings->username, $settings->fromAddress, $settings->fromName, $updatedBy, $now,
        ];

        if ($keepPassword) {
            $this->db->execute(
                'INSERT INTO mail_settings (id, transport, host, port, encryption, username, from_address, from_name, updated_by, updated_at)
                 VALUES (1, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE transport = VALUES(transport), host = VALUES(host), port = VALUES(port), encryption = VALUES(encryption),
                     username = VALUES(username), from_address = VALUES(from_address), from_name = VALUES(from_name),
                     updated_by = VALUES(updated_by), updated_at = VALUES(updated_at)',
                $values,
            );

            return;
        }
        $this->db->execute(
            'INSERT INTO mail_settings (id, transport, host, port, encryption, username, from_address, from_name, updated_by, updated_at, password)
             VALUES (1, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE transport = VALUES(transport), host = VALUES(host), port = VALUES(port), encryption = VALUES(encryption),
                 username = VALUES(username), from_address = VALUES(from_address), from_name = VALUES(from_name),
                 updated_by = VALUES(updated_by), updated_at = VALUES(updated_at), password = VALUES(password)',
            [...$values, $password],
        );
    }
}
