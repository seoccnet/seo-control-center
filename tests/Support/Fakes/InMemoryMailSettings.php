<?php

declare(strict_types=1);

namespace SeoControlCenter\Tests\Support\Fakes;

use SeoControlCenter\Core\Mail\MailSettings;
use SeoControlCenter\Core\Mail\MailSettingsRepository;

final class InMemoryMailSettings implements MailSettingsRepository
{
    public MailSettings $settings;

    public ?int $updatedBy = null;

    public function __construct()
    {
        $this->settings = new MailSettings();
    }

    public function get(): MailSettings
    {
        return $this->settings;
    }

    public function save(MailSettings $settings, bool $keepPassword, int $updatedBy): void
    {
        $this->settings = new MailSettings(
            $settings->transport,
            $settings->host,
            $settings->port,
            $settings->encryption,
            $settings->username,
            $keepPassword ? $this->settings->password : $settings->password,
            $settings->fromAddress,
            $settings->fromName,
        );
        $this->updatedBy = $updatedBy;
    }
}
