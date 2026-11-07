<?php

declare(strict_types=1);

namespace SeoControlCenter\Tests\Unit\ArticleIdeas;

use PHPUnit\Framework\TestCase;
use SeoControlCenter\Domain\ArticleIdeas\ArticleOutline;
use SeoControlCenter\Domain\Comparison\PageStructure;

final class ArticleOutlineTest extends TestCase
{
    public function testSubtopicsAreHeadingsMostArticlesShare(): void
    {
        $outline = $this->outline([
            self::page('https://a.test/blog/water', ['Watering schedule', 'Best soil for seedlings', 'How often should you water?']),
            self::page('https://b.test/guide/water', ['Watering schedule tips', 'Soil for seedlings', 'Our story']),
            PageStructure::unread('https://c.test/blog/water', PageStructure::BLOCKED, 'robots.txt does not allow it.'),
        ], own: self::page('https://shop.acme.test/blog/water', ['Soil for your seedlings']));

        self::assertCount(2, $outline->readable());
        $subtopics = $outline->subtopics();
        self::assertSame(['Watering schedule', 'Best soil for seedlings'], array_column($subtopics, 'topic'), 'Shared by both readable articles ("Our story" is not); missing ones first.');
        self::assertSame([false, true], array_column($subtopics, 'own'), 'Your page already covers the soil subtopic.');
        self::assertSame(['How often should you water?'], $outline->questions());

        $present = $outline->present();
        self::assertSame('blocked', $present['pages'][2]['state']);
        self::assertSame([['level' => 2, 'text' => 'Watering schedule'], ['level' => 2, 'text' => 'Best soil for seedlings'], ['level' => 2, 'text' => 'How often should you water?']], $present['pages'][0]['headings']);
    }

    public function testOneReadableArticleListsItsOwnHeadings(): void
    {
        $outline = $this->outline([self::page('https://a.test/blog/water', ['Watering schedule', 'Soil'])]);

        self::assertSame(['Soil', 'Watering schedule'], array_column($outline->subtopics(), 'topic'));
        self::assertSame([], $this->outline([PageStructure::unread('https://a.test/x', PageStructure::FAILED, 'HTTP 500')])->subtopics());
    }

    public function testSubtopicsNeedTwoSitesAndSkipSiteTemplates(): void
    {
        // 0.28.0 live check, finding 9: two articles of one site shared its embedded privacy policy ("2 of 2").
        $template = ['SECTION 1 – PERSONAL INFORMATION WE COLLECT', '1.2 Newsletters', 'More helpful info'];
        $outline = $this->outline([
            self::page('https://www.meadow.test/us/blog-blue-brandywine', ['What is the Blue Brandywine cultivar?', 'Effects and experience', ...$template]),
            self::page('https://meadow.test/blog-brandywine', ['What is the Brandywine cultivar?', 'Brandywine cultivar effects', ...$template]),
            self::page('https://herbies.test/blog/brandywine-grow', ['Brandywine effects and experience', 'Growing Brandywine indoors']),
        ]);

        $sites = $outline->sites();
        self::assertCount(2, $sites, 'Two sites, not three pages.');
        self::assertSame(['What is the Blue Brandywine cultivar?', 'Effects and experience', 'What is the Brandywine cultivar?', 'Brandywine cultivar effects'], array_column($sites[0]->outline, 'text'), 'The template is dropped; the rest is merged.');
        $subtopics = $outline->subtopics();
        self::assertSame(['Effects and experience'], array_column($subtopics, 'topic'), 'Only what both sites cover.');
        self::assertSame(['https://www.meadow.test/us/blog-blue-brandywine', 'https://herbies.test/blog/brandywine-grow'], $subtopics[0]['competitors']);
        self::assertSame(['What is the Blue Brandywine cultivar?', 'What is the Brandywine cultivar?'], $outline->questions());

        $oneSite = $this->outline([$outline->pages[0], $outline->pages[1]]);
        self::assertSame(['Effects and experience', 'What is the Blue Brandywine cultivar?'], array_column($oneSite->subtopics(), 'topic'), 'One site: its own headings (similar ones grouped), without the template.');
    }

    public function testQuestionsAreAboutTheIdea(): void
    {
        // 0.28.1 live check, finding 13: a flushing post's questions reached the cherry brief.
        $outline = $this->outline([
            self::page('https://meadow.test/blog/flushing', ['What is flushing?', 'How to flush hydroponic plants?', 'Is Cherry worth growing?', 'How do sunflowers start flowering?']),
            self::page('https://auto.test/cherry', ['What is Tomato Cherry?', 'How do sunflowers start flowering?']),
        ]);

        self::assertSame(['Is Cherry worth growing?', 'How do sunflowers start flowering?', 'What is Tomato Cherry?'], $outline->questions('cherry'), 'Naming the search, or asked by two sites.');
        self::assertCount(5, $outline->questions(), 'Without a search: every question.');
        self::assertSame(['Is Cherry worth growing?', 'How do sunflowers start flowering?', 'What is Tomato Cherry?'], $outline->present('cherry')['questions']);
    }

    public function testStoredStructureKeepsOnlyTheOutline(): void
    {
        $page = new PageStructure('https://a.test/x', PageStructure::OK, null, 'Title', 'A description', 'H1', [['level' => 2, 'text' => 'One']], [], ['Article'], 900, 4, 1, 1, 2, 10, 3, '2026-09-01', null, ['byline' => true], 'abc');
        $slim = ArticleOutline::slim($page);

        self::assertSame([null, [], 0, [], null], [$slim->description, $slim->schemaTypes, $slim->images, $slim->elements, $slim->hash]);
        self::assertSame([900, 'H1', [['level' => 2, 'text' => 'One']]], [$slim->wordCount, $slim->h1, $slim->outline]);
    }

    /** @param list<PageStructure> $pages */
    private function outline(array $pages, ?PageStructure $own = null): ArticleOutline
    {
        return new ArticleOutline(str_repeat('a', 64), ArticleOutline::DONE, $pages, $own, null, '2026-10-18 10:00:00', '2026-10-18 10:05:00');
    }

    /** @param list<string> $headings */
    private static function page(string $url, array $headings): PageStructure
    {
        return new PageStructure(
            $url,
            PageStructure::OK,
            outline: array_map(static fn(string $h): array => ['level' => 2, 'text' => $h], $headings),
            questions: array_values(array_filter($headings, static fn(string $h): bool => str_ends_with($h, '?'))),
            wordCount: 800,
        );
    }
}
