<?php

declare(strict_types=1);

namespace SeoControlCenter\Domain\Opportunities;

/**
 * What to do about a finding: SEOcc's own action plan for its kind of work,
 * filled in with the finding's query or page (ADR-031). A playbook is
 * guidance only; it never changes a finding's impact, value, confidence,
 * effort or order.
 */
final readonly class Playbook
{
    public const array SECTIONS = ['When it applies', 'Before you start', 'What to change', 'What to keep', 'What to measure', 'When to revert'];
    /**
     * How well tested this kind of change is (0.41.0), in SEOcc's own words:
     * a label and what it means for the reader. Guidance only, never a score.
     */
    public const array TESTED = [
        'tested' => ['Tested repeatedly', 'Several independent published tests of this kind of change measured an effect. Results elsewhere are not a forecast for your site: change a group of pages, keep a similar group unchanged, and compare.'],
        'varies' => ['Some tests, effect varies', 'Published tests exist, but their results differ with the site, the template or the intent. Start with a few pages and keep a comparison set.'],
        'early' => ['Few published tests', 'Only one or two published tests looked at this kind of change. Try it on a few pages with a comparison set before rolling it out.'],
        'guidance' => ['Search engine guidance', 'Fix it because search engines document it as a requirement or best practice; few published tests measure a traffic effect.'],
    ];

    /**
     * @param list<array{label: string, items: list<string>}> $sections in SECTIONS order
     * @param list<string> $cautions things to avoid for this kind of work
     * @param list<string> $alsoConsider related approaches worth testing next
     */
    public function __construct(
        public string $key,
        public string $title,
        public string $goal,
        public array $sections,
        public array $cautions,
        public array $alsoConsider,
        public string $tested = 'guidance',
    ) {}

    public function testedLabel(): string
    {
        return self::TESTED[$this->tested][0] ?? self::TESTED['guidance'][0];
    }

    public function testedNote(): string
    {
        return self::TESTED[$this->tested][1] ?? self::TESTED['guidance'][1];
    }

    /** @return list<string> */
    public function items(string $label): array
    {
        foreach ($this->sections as $section) {
            if ($section['label'] === $label) {
                return $section['items'];
            }
        }
        return [];
    }

    /** The first concrete change, for compact lists. */
    public function firstChange(): string
    {
        return $this->items('What to change')[0] ?? $this->goal;
    }

    /** @return array{key: string, title: string, goal: string, tested: string, tested_label: string, tested_note: string, sections: list<array{label: string, items: list<string>}>, cautions: list<string>, also_consider: list<string>} */
    public function toArray(): array
    {
        return ['key' => $this->key, 'title' => $this->title, 'goal' => $this->goal, 'tested' => $this->tested, 'tested_label' => $this->testedLabel(), 'tested_note' => $this->testedNote(), 'sections' => $this->sections, 'cautions' => $this->cautions, 'also_consider' => $this->alsoConsider];
    }
}
