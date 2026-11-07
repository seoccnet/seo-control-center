<?php

declare(strict_types=1);

namespace SeoControlCenter\Core\Mail;

interface MailSettingsRepository
{
    /**
     * The stored settings, or defaults (transport "none") when nothing is saved.
     */
    public function get(): MailSettings;

    /**
     * Saves the settings. With $keepPassword the stored SMTP password stays
     * (the form leaves the field empty to keep it).
     */
    public function save(MailSettings $settings, bool $keepPassword, int $updatedBy): void;
}
