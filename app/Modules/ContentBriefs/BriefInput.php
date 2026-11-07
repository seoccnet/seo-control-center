<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\ContentBriefs;

use SeoControlCenter\Domain\Projects\Project;
use SeoControlCenter\Domain\Shared\ValidationException;

final readonly class BriefInput
{
    public const array STATUSES = ['draft' => 'Draft', 'in_progress' => 'In progress', 'published' => 'Published'];
    public const array FIELDS = ['intent' => 'Search intent and audience', 'related_queries' => 'Related queries', 'seo_title' => 'Suggested SEO title', 'meta_description' => 'Suggested meta description', 'outline' => 'Heading outline', 'topics' => 'Topics and questions to cover', 'internal_links' => 'Internal links to add', 'notes' => 'Writer instructions and checks'];

    /** @param array<string, string> $sections */
    public function __construct(public string $title, public string $keyword, public ?string $url, public string $status, public array $sections) {}

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data, Project $project): self
    {
        $text = static fn(string $key): string => is_string($data[$key] ?? null) ? trim($data[$key]) : '';
        $title = $text('title');
        $keyword = $text('keyword');
        $url = $text('url');
        $status = $text('status');
        $errors = [];
        foreach (['title' => [$title, 160], 'keyword' => [$keyword, 200]] as $key => [$value, $limit]) {
            if ($value === '' || !mb_check_encoding($value, 'UTF-8') || mb_strlen($value) > $limit || preg_match('/[\x00-\x1f\x7f]/', $value)) {
                $errors[$key] = 'Enter 1–' . $limit . ' characters without control characters.';
            }
        }
        if (!isset(self::STATUSES[$status])) {
            $errors['status'] = 'Choose a workflow status.';
        }
        $parts = parse_url($url);
        if (isset($data['url']) && !is_string($data['url'])) {
            $errors['url'] = 'Use a plain-text URL or leave the field blank.';
        }
        $domain = strtolower(preg_replace('/^www\./i', '', $project->primaryDomain) ?? $project->primaryDomain);
        if ($url !== '' && (strlen($url) > 2048 || filter_var($url, FILTER_VALIDATE_URL) === false || !is_array($parts) || !in_array($parts['scheme'] ?? '', ['http', 'https'], true) || !in_array(strtolower($parts['host'] ?? ''), [$domain, 'www.' . $domain], true) || isset($parts['user']) || isset($parts['pass']) || isset($parts['port']) || isset($parts['fragment']) || preg_match('/[\s\\\\]/', $url))) {
            $errors['url'] = 'Use an exact HTTP(S) URL on this project’s domain, without credentials, a port or fragment. Leave blank for a new page.';
        }
        $sections = [];
        foreach (self::FIELDS as $key => $label) {
            $sections[$key] = $text($key);
            $limit = match ($key) {
                'seo_title' => 200, 'meta_description' => 500, default => 4000
            };
            if (isset($data[$key]) && !is_string($data[$key]) || !mb_check_encoding($sections[$key], 'UTF-8') || mb_strlen($sections[$key]) > $limit || preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/', $sections[$key])) {
                $errors[$key] = 'Use plain text of at most ' . $limit . ' characters without control characters.';
            }
        }
        if ($errors !== []) {
            throw new ValidationException($errors);
        }
        return new self($title, $keyword, $url === '' ? null : $url, $status, $sections);
    }

    /** @return array<string, string> */
    public function form(): array
    {
        return ['title' => $this->title, 'keyword' => $this->keyword, 'url' => $this->url ?? '', 'status' => $this->status, ...$this->sections];
    }
}
