<?php

declare(strict_types=1);

namespace SeoControlCenter\Domain\Projects;

use DateTimeZone;
use SeoControlCenter\Domain\Shared\ValidationException;
use SeoControlCenter\Domain\Websites\DomainName;

/**
 * Validated input for creating or editing a project.
 */
final readonly class ProjectInput
{
    private function __construct(
        public string $name,
        public DomainName $primaryDomain,
        public string $timezone,
    ) {}

    /**
     * @param array<array-key, mixed> $data raw form data
     * @throws ValidationException
     */
    public static function fromArray(array $data): self
    {
        $errors = [];

        $name = trim(is_string($data['name'] ?? null) ? $data['name'] : '');
        if ($name === '') {
            $errors['name'] = 'Enter a project name.';
        } elseif (mb_strlen($name) > 120) {
            $errors['name'] = 'Use at most 120 characters.';
        }

        $domainInput = is_string($data['primary_domain'] ?? null) ? $data['primary_domain'] : '';
        $domain = DomainName::tryFrom($domainInput);
        if ($domain === null) {
            $errors['primary_domain'] = 'Enter a valid domain name, for example example.com.';
        }

        $timezone = is_string($data['timezone'] ?? null) && $data['timezone'] !== '' ? $data['timezone'] : 'UTC';
        if (!in_array($timezone, DateTimeZone::listIdentifiers(), true)) {
            $errors['timezone'] = 'Choose a valid time zone.';
        }

        if ($errors !== [] || $domain === null) {
            throw new ValidationException($errors);
        }

        return new self($name, $domain, $timezone);
    }
}
