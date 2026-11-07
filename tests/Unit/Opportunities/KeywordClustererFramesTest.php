<?php

declare(strict_types=1);

namespace SeoControlCenter\Tests\Unit\Opportunities;

use PHPUnit\Framework\TestCase;
use SeoControlCenter\Domain\Opportunities\KeywordClusterer;

/**
 * Question frames never link two topics (found on Web Shop, 0.18.2): only
 * subject words decide the overlap.
 */
final class KeywordClustererFramesTest extends TestCase
{
    public function testQuestionFramesDoNotLinkDifferentSubjects(): void
    {
        $topics = (new KeywordClusterer())->cluster([
            'mushrooms edible' => 50.0,
            'types of edible mushrooms' => 40.0,
            'types of pepper' => 30.0,
            'pepper types' => 10.0,
            'how long does a pepper harvest last' => 90.0,
            'how long does a mushroom harvest last' => 20.0,
            'how long does pepper last' => 70.0,
            'red running shoes' => 60.0,
            'running shoes red' => 5.0,
        ]);
        $label = static fn(string $keyword): string => $topics[$keyword]['label'];

        self::assertSame('mushrooms edible', $label('types of edible mushrooms'), 'Two shared subject words still join.');
        self::assertNotSame($label('how long does a pepper harvest last'), $label('how long does a mushroom harvest last'), 'The same frame does not join two subjects.');
        self::assertNotSame($label('how long does a pepper harvest last'), $label('mushrooms edible'));
        self::assertSame('types of pepper', $label('pepper types'), 'One subject word joins the same subject and frame.');
        self::assertNotSame($label('types of pepper'), $label('how long does pepper last'), 'Same subject, different question: kept apart.');
        self::assertSame('red running shoes', $label('running shoes red'));
    }
}
