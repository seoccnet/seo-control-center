<?php

declare(strict_types=1);

namespace SeoControlCenter\Domain\Opportunities;

use SeoControlCenter\Domain\Keywords\Keyword;

/**
 * Whether a search fits the business, with the reason (ADR-037). The same
 * fixed rules as business potential: a team rating wins, then excluded
 * terms and job searches rule a search out, then a mentioned offering
 * rules it in. Searches that name none of the offerings are "unsure", not
 * irrelevant: wording alone cannot tell. Without offerings or exclusions
 * nothing is checked.
 */
final readonly class Relevance
{
    public const string RELEVANT = 'relevant';
    public const string NOT_RELEVANT = 'not_relevant';
    public const string UNSURE = 'unsure';
    public const string UNCHECKED = 'unchecked';

    public const array LABELS = [
        self::RELEVANT => 'Relevant',
        self::NOT_RELEVANT => 'Not relevant',
        self::UNSURE => 'Unsure',
        self::UNCHECKED => 'Not checked',
    ];

    /**
     * @param array<string, int> $ratings normalized keyword => team rating 0-3
     */
    public function __construct(private ?BusinessContext $context, private array $ratings = []) {}

    /**
     * @return array{verdict: string, reason: string, source: string}
     */
    public function of(string $keyword): array
    {
        $keyword = Keyword::normalize($keyword);
        if (isset($this->ratings[$keyword])) {
            return $this->ratings[$keyword] === 0
                ? ['verdict' => self::NOT_RELEVANT, 'reason' => 'your team marked it as no business value', 'source' => 'team']
                : ['verdict' => self::RELEVANT, 'reason' => sprintf('your team rated it %d/3', $this->ratings[$keyword]), 'source' => 'team'];
        }
        if ($this->context === null) {
            return ['verdict' => self::UNCHECKED, 'reason' => 'no business profile yet', 'source' => 'rules'];
        }
        $clash = $this->clash($keyword);
        if ($clash !== null) {
            return ['verdict' => self::NOT_RELEVANT, 'reason' => $clash, 'source' => 'rules'];
        }
        foreach ($this->context->offerings as $term) {
            if (QueryPatterns::mentions($keyword, $term)) {
                return ['verdict' => self::RELEVANT, 'reason' => 'mentions “' . $term . '”, which you offer', 'source' => 'rules'];
            }
        }
        if ($this->context->offerings === []) {
            return ['verdict' => self::UNCHECKED, 'reason' => 'add your offerings to the business profile to check relevance', 'source' => 'rules'];
        }

        return ['verdict' => self::UNSURE, 'reason' => 'names none of your offerings', 'source' => 'rules'];
    }

    /**
     * Why a seed or keyword is ruled out before any rating: an excluded term
     * or a job search the business does not offer. Null when it fits.
     */
    public function clash(string $keyword): ?string
    {
        if ($this->context === null) {
            return null;
        }
        $keyword = Keyword::normalize($keyword);
        foreach ($this->context->exclusions as $term) {
            if (QueryPatterns::mentions($keyword, $term)) {
                return 'contains “' . $term . '”, a term your profile excludes';
            }
        }
        if (QueryPatterns::isCareer($keyword) && !array_any($this->context->offerings, QueryPatterns::isCareer(...))) {
            return 'a job or salary search: searchers want work, not your offer';
        }

        return null;
    }
}
