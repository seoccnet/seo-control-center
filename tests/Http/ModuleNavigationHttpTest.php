<?php

declare(strict_types=1);

namespace SeoControlCenter\Tests\Http;

use PHPUnit\Framework\TestCase;
use SeoControlCenter\Tests\Support\Scenario;

final class ModuleNavigationHttpTest extends TestCase
{
    public function testSectionNavigationSelectsTheMostSpecificRoute(): void
    {
        $s = new Scenario();
        $browser = $s->loggedIn($s->owner);
        $base = '/projects/' . $s->acmeProject->publicId;
        foreach (['/keywords/research' => 'Research','/content-briefs/new' => 'Improve','/local/locations' => 'Visibility'] as $suffix => $group) {
            $response = $browser->get($base . $suffix);
            self::assertSame(200, $response->getStatusCode());
            $html = (string) $response->getBody();
            $dom = new \DOMDocument();
            @$dom->loadHTML($html);
            $xpath = new \DOMXPath($dom);
            $links = $xpath->query('//nav[@aria-label="' . $group . ' pages"]/a[@aria-current="page"]');
            self::assertNotFalse($links);
            self::assertSame(1, $links->length);
            $link = $links->item(0);
            self::assertInstanceOf(\DOMElement::class, $link);
            self::assertSame($base . ($suffix === '/content-briefs/new' ? '/content-briefs' : $suffix), $link->getAttribute('href'));
            self::assertStringContainsString('module-workspace', $html);
            self::assertStringNotContainsString($s->rivalProject->name, $html);
        }
    }
}
