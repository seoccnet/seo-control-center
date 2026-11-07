<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\SiteAudit\Crawler;

/**
 * The rules of a robots.txt (RFC 9309) that apply to the crawler: the
 * groups naming its product token, otherwise the "*" groups. The longest
 * matching rule wins; on a tie, Allow wins.
 */
final readonly class RobotsTxt
{
    private const int MAX_BYTES = 512 * 1024;

    /**
     * @param list<array{allow: bool, pattern: string}> $rules
     * @param list<string> $sitemaps
     * @param bool $named a group names the product token (otherwise the "*" rules apply)
     */
    private function __construct(
        private array $rules,
        public array $sitemaps,
        public bool $named = false,
    ) {}

    public static function allowAll(): self
    {
        return new self([], []);
    }

    /** @return array{rules: list<array{allow: bool, pattern: string}>, sitemaps: list<string>} */
    public function toArray(): array
    {
        return ['rules' => $this->rules, 'sitemaps' => $this->sitemaps];
    }

    /** @param array{rules: list<array{allow: bool, pattern: string}>, sitemaps: list<string>} $data */
    public static function fromArray(array $data): self
    {
        return new self($data['rules'], $data['sitemaps']);
    }

    /**
     * @param bool $exact match a group only when its user-agent equals the token, so
     *        "Applebot" does not also apply to "Applebot-Extended" (AI crawler check, 0.43.0)
     */
    public static function parse(string $text, string $productToken, bool $exact = false): self
    {
        $token = strtolower($productToken);
        $groups = [];
        $agents = [];
        $rules = [];
        $sitemaps = [];
        $inRules = false;
        foreach (preg_split('/\R/', substr($text, 0, self::MAX_BYTES)) ?: [] as $line) {
            $hash = strpos($line, '#');
            if ($hash !== false) {
                $line = substr($line, 0, $hash);
            }
            if (!str_contains($line, ':')) {
                continue;
            }
            [$field, $value] = explode(':', $line, 2);
            $field = strtolower(trim($field));
            $value = trim($value);
            if ($field === 'sitemap') {
                if ($value !== '') {
                    $sitemaps[] = $value;
                }
                continue;
            }
            if ($field === 'user-agent') {
                if ($inRules) {
                    $groups[] = ['agents' => $agents, 'rules' => $rules];
                    $agents = [];
                    $rules = [];
                    $inRules = false;
                }
                $agents[] = strtolower($value);
                continue;
            }
            if ($field === 'allow' || $field === 'disallow') {
                $inRules = true;
                if ($value !== '') {
                    $rules[] = ['allow' => $field === 'allow', 'pattern' => self::octets($value)];
                }
            }
        }
        if ($agents !== []) {
            $groups[] = ['agents' => $agents, 'rules' => $rules];
        }

        $specific = [];
        $generic = [];
        $hasSpecific = false;
        foreach ($groups as $group) {
            foreach ($group['agents'] as $agent) {
                if ($agent === '*') {
                    $generic = [...$generic, ...$group['rules']];
                } elseif ($agent !== '' && ($exact ? $agent === $token : str_contains($token, $agent))) {
                    $hasSpecific = true;
                    $specific = [...$specific, ...$group['rules']];
                }
            }
        }

        return new self($hasSpecific ? $specific : $generic, array_values(array_unique($sitemaps)), $hasSpecific);
    }

    /**
     * @param string $pathAndQuery for example "/blog/?page=2"
     */
    public function allows(string $pathAndQuery): bool
    {
        $pathAndQuery = self::octets($pathAndQuery);
        if ($pathAndQuery === '/robots.txt') {
            return true;
        }
        $best = null;
        foreach ($this->rules as $rule) {
            if (!self::matches($rule['pattern'], $pathAndQuery)) {
                continue;
            }
            $length = strlen($rule['pattern']);
            if ($best === null || $length > $best['length'] || ($length === $best['length'] && $rule['allow'])) {
                $best = ['length' => $length, 'allow' => $rule['allow']];
            }
        }

        return $best === null || $best['allow'];
    }

    private static function matches(string $pattern, string $path): bool
    {
        $anchored = str_ends_with($pattern, '$');
        if ($anchored) {
            $pattern = substr($pattern, 0, -1);
        }
        $regex = '~^' . str_replace('\*', '.*', preg_quote($pattern, '~')) . ($anchored ? '$' : '') . '~';

        return preg_match($regex, $path) === 1;
    }

    /** RFC 9309: compare UTF-8 octets and decode percent-encoded unreserved ASCII only. */
    private static function octets(string $value): string
    {
        $value = (string) preg_replace_callback('/[^\x00-\x7F]/', static fn(array $m): string => rawurlencode($m[0]), $value);
        return (string) preg_replace_callback('/%([0-9a-f]{2})/i', static function (array $m): string {
            $byte = rawurldecode($m[0]);
            return preg_match('/^[A-Za-z0-9._~-]$/D', $byte) === 1 ? $byte : strtoupper($m[0]);
        }, $value);
    }
}
