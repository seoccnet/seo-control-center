<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\Annotations;

use DateTimeImmutable;
use SeoControlCenter\Core\Support\Clock;
use SeoControlCenter\Domain\Projects\Project;
use SeoControlCenter\Domain\Shared\ValidationException;

final readonly class AnnotationInput
{
    public const array KINDS = ['title' => 'Title / snippet', 'content' => 'Content update', 'links' => 'Internal links', 'technical' => 'Technical change', 'release' => 'Website release', 'other' => 'Other'];

    public function __construct(public string $date, public string $kind, public string $title, public string $note, public ?string $url) {}

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data, Project $project, Clock $clock): self
    {
        $text = static fn(string $key): string => is_string($data[$key] ?? null) ? trim($data[$key]) : '';
        $date = $text('date');
        $kind = $text('kind');
        $title = $text('title');
        $note = $text('note');
        $url = $text('url');
        $errors = [];
        $parsed = preg_match('/^\d{4}-\d{2}-\d{2}$/D', $date) === 1 ? DateTimeImmutable::createFromFormat('!Y-m-d', $date) : false;
        if ($parsed === false || $parsed->format('Y-m-d') !== $date || $date < '2000-01-01' || $date > $clock->now()->format('Y-m-d')) {
            $errors['date'] = 'Choose the actual change date, from 2000 through today (UTC).';
        }
        if (!isset(self::KINDS[$kind])) {
            $errors['kind'] = 'Choose a change type.';
        }
        if ($title === '' || mb_strlen($title) > 120 || preg_match('/[\x00-\x1f\x7f]/', $title)) {
            $errors['title'] = 'Enter a title of 1–120 characters without control characters.';
        }
        if (mb_strlen($note) > 2000 || preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/', $note)) {
            $errors['note'] = 'Use at most 2,000 characters, without control characters.';
        }
        $parts = parse_url($url);
        $host = is_array($parts) ? strtolower($parts['host'] ?? '') : '';
        $domain = strtolower(preg_replace('/^www\./i', '', $project->primaryDomain) ?? $project->primaryDomain);
        if ($url !== '' && (strlen($url) > 2048 || filter_var($url, FILTER_VALIDATE_URL) === false || !is_array($parts) || !in_array($parts['scheme'] ?? '', ['http', 'https'], true) || !in_array($host, [$domain, 'www.' . $domain], true) || isset($parts['user']) || isset($parts['pass']) || isset($parts['port']) || isset($parts['fragment']) || preg_match('/[\s\\\\]/', $url))) {
            $errors['url'] = 'Use an exact HTTP(S) URL on this project’s domain, without credentials, a port or fragment. Leave blank for a site-wide change.';
        }
        if ($errors !== []) {
            throw new ValidationException($errors);
        }
        return new self($date, $kind, $title, $note, $url === '' ? null : $url);
    }
}
