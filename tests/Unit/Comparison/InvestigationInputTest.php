<?php

declare(strict_types=1);

namespace SeoControlCenter\Tests\Unit\Comparison;

use PHPUnit\Framework\TestCase;
use SeoControlCenter\Domain\Comparison\Analysis;
use SeoControlCenter\Domain\Comparison\AnalysisEvidence;
use SeoControlCenter\Domain\Comparison\PageStructure;
use SeoControlCenter\Domain\Investigations\InvestigationInput;
use SeoControlCenter\Domain\Shared\ValidationException;

/**
 * Investigation input rules and the entries an analysis adds (ADR-038, D3).
 */
final class InvestigationInputTest extends TestCase
{
    public function testFieldsAndItemsAreValidated(): void
    {
        self::assertSame(['title' => 'Drop in October', 'question' => "Why?\nSince the 3rd.", 'status' => 'open', 'conclusion' => null], InvestigationInput::fields(['title' => "  Drop in\tOctober ", 'question' => "Why?\nSince the 3rd.", 'conclusion' => '  ']));
        self::assertSame(['title', 'status'], array_keys($this->fieldErrors(['title' => '', 'status' => 'closed'])));
        self::assertSame(['conclusion'], array_keys($this->fieldErrors(['title' => 'x', 'status' => 'concluded'])), 'Concluded needs a conclusion.');

        $item = InvestigationInput::item(['kind' => 'source', 'label' => 'GSC export', 'ref_type' => 'url', 'ref_id' => 'https://shop.test/a', 'observed_on' => '2026-10-15'], '2026-10-16');
        self::assertSame(['kind' => 'source', 'ref_type' => 'url', 'ref_id' => 'https://shop.test/a', 'label' => 'GSC export', 'detail' => null, 'observed_on' => '2026-10-15'], $item);
        self::assertSame(['kind', 'label'], array_keys($this->itemErrors(['kind' => 'claim', 'label' => ''])));
        self::assertSame(['observed_on'], array_keys($this->itemErrors(['kind' => 'note', 'label' => 'x', 'observed_on' => '2026-10-17'])), 'Not in the future.');
        self::assertSame(['observed_on'], array_keys($this->itemErrors(['kind' => 'note', 'label' => 'x', 'observed_on' => '2026-02-30'])));
        self::assertSame(['ref_id'], array_keys($this->itemErrors(['kind' => 'source', 'label' => 'x', 'ref_type' => 'url', 'ref_id' => 'javascript:alert(1)'])), 'Only http(s) URLs.');
        self::assertSame(['ref_id'], array_keys($this->itemErrors(['kind' => 'source', 'label' => 'x', 'ref_type' => 'analysis'])), 'A reference needs its ID.');
        self::assertSame(['ref_type'], array_keys($this->itemErrors(['kind' => 'source', 'label' => 'x', 'ref_type' => 'file', 'ref_id' => '1'])));
    }

    public function testAnAnalysisBecomesDatedSourcesChecksAndLimitations(): void
    {
        $pages = [
            'https://a.test/grow' => new PageStructure('https://a.test/grow', PageStructure::OK, title: 'Grow', outline: [['level' => 2, 'text' => 'Soil']], schemaTypes: ['Article'], wordCount: 1500),
            'https://b.test/x' => PageStructure::unread('https://b.test/x', PageStructure::BLOCKED, 'robots.txt'),
        ];
        $verdict = ['format' => ['verdict' => 'dominant', 'explanation' => 'Articles hold the page.'], 'coverage' => [['topic' => 'soil', 'competitors' => ['a', 'b'], 'own' => false]], 'diagnosis' => ['bottleneck' => 'content', 'reasons' => ['Gaps.'], 'confidence' => 'low', 'actions' => []], 'readable' => 1, 'attempted' => 2, 'links' => ['status' => 'no_owner', 'note' => 'No page owns this topic yet.', 'links' => []], 'ai' => ['status' => 'unavailable', 'note' => 'Set up the AI assistant.']];
        $a = new Analysis('0192f0a0-0000-7000-8000-000000000501', 1, 1, '0192f0a0-0000-7000-8000-000000000502', 'sunflower guide', 'sunflower guide', ['sunflower guide', 'sunflower tips'], 2300.0, Analysis::DONE, Analysis::PAID, 2840, 'en', [['position' => 1, 'url' => 'https://a.test/grow', 'domain' => 'a.test', 'title' => 'Grow', 'type' => 'article', 'confidence' => 'high', 'signals' => [], 'own' => false, 'fetched' => true]], null, null, $pages, $verdict, 0.002, null, '2026-10-16 10:00:00', '2026-10-16 10:05:00');
        $items = AnalysisEvidence::items($a);
        $byKind = [];
        foreach ($items as $i) {
            $byKind[$i['kind']][] = $i['label'];
            self::assertSame('2026-10-16', $i['observed_on'], 'Every entry is dated with the analysis.');
        }
        self::assertSame(['Why they outrank you: sunflower guide', 'Results page for “sunflower guide”', 'Competing page: https://a.test/grow', 'Competing page: https://b.test/x'], $byKind['source']);
        self::assertSame(['analysis', '0192f0a0-0000-7000-8000-000000000501'], [$items[0]['ref_type'], $items[0]['ref_id']]);
        self::assertStringContainsString('Looked up from DataForSEO ($0.002)', (string) $items[1]['detail']);
        self::assertSame(['Results-page format: dominant', 'Subtopic coverage against same-type pages', 'Main bottleneck: content', 'Internal links from the site audit'], $byKind['check']);
        self::assertSame(['1 of 2 competing pages could not be read', 'Low confidence in the verdict', 'No page of the site owns or ranks for this topic', 'No AI explanation', 'Structure only'], $byKind['limitation']);
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, string>
     */
    private function fieldErrors(array $data): array
    {
        try {
            InvestigationInput::fields($data);
        } catch (ValidationException $e) {
            return $e->errors();
        }
        self::fail('Expected validation errors.');
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, string>
     */
    private function itemErrors(array $data): array
    {
        try {
            InvestigationInput::item($data, '2026-10-16');
        } catch (ValidationException $e) {
            return $e->errors();
        }
        self::fail('Expected validation errors.');
    }
}
