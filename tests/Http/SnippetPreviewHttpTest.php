<?php

declare(strict_types=1);

namespace SeoControlCenter\Tests\Http;

use PHPUnit\Framework\TestCase;
use SeoControlCenter\Modules\SiteAudit\AuditPage;
use SeoControlCenter\Tests\Support\Fakes\InMemorySiteAudit;
use SeoControlCenter\Tests\Support\Scenario;

/** Snippet preview by pixel width (1.6.0). */
final class SnippetPreviewHttpTest extends TestCase
{
    public function testPagesAreMeasuredAndATrialPreviewed(): void
    {
        $s = new Scenario();
        $audits = $s->container->get(InMemorySiteAudit::class);
        self::assertInstanceOf(InMemorySiteAudit::class, $audits);
        $audit = $audits->create($s->acmeProject->id, $s->acme->id, 'https://shop.acme.test/', 10, 'manual', null);
        $first = $audits->pendingPages($audit->id, 1)[0]->id;
        $long = 'WHOLESALE RUNNING SHOES FOR MARATHON TRAINING AND RACING';
        $audits->pages[$audit->id] = [
            $first => new AuditPage($first, 'https://shop.acme.test/', 0, 'done', 200, 'text/html', title: 'Acme shoes', description: 'Shoes since 1990.'),
            $first + 1 => new AuditPage($first + 1, 'https://shop.acme.test/wholesale', 1, 'done', 200, 'text/html', title: $long, description: null),
            $first + 2 => new AuditPage($first + 2, 'https://shop.acme.test/hidden', 1, 'done', 200, 'text/html', title: str_repeat('W', 90), noindex: true),
        ];
        $audits->finish($audit->id, 'completed', 90, ['error' => 0, 'warning' => 0, 'notice' => 0], [], null);
        $viewer = $s->loggedIn($s->viewer);
        $url = '/projects/' . $s->acmeProject->publicId . '/site-audit/snippets';

        $html = (string) $viewer->get($url)->getBody();
        self::assertStringContainsString('1 of 2 indexable pages have a title or description Google cuts.', $html, mb_strlen($long) . ' capitals exceed the width though under 60 characters.');
        self::assertMatchesRegularExpression('#WHOLESALE RUNNING SHOES FOR MARATHON .*\.\.\.</p>.*No description: Google shows text from the page.*Acme shoes#s', $html);
        self::assertStringNotContainsString('/hidden', $html, 'Noindex pages are not shown.');
        self::assertStringNotContainsString('Acme shoes', (string) $viewer->get($url . '?cut=1')->getBody());

        $trial = (string) $viewer->get($url . '?' . http_build_query(['try' => '1', 'url' => 'https://shop.acme.test/new', 'title' => 'New <b>title</b>', 'description' => 'Short.']))->getBody();
        self::assertStringContainsString('<p class="serp-title">New &lt;b&gt;title&lt;/b&gt;</p>', $trial);
        self::assertMatchesRegularExpression('#Title: \d+ / 600 px · fits#', $trial);
        self::assertStringContainsString('shop.acme.test › new', $trial);
        self::assertStringContainsString('/site-audit/snippets', (string) $viewer->get('/projects/' . $s->acmeProject->publicId . '/site-audit')->getBody());
        self::assertSame(404, $viewer->get('/projects/' . $s->rivalProject->publicId . '/site-audit/snippets')->getStatusCode());
    }
}
