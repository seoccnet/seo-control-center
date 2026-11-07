<?php

declare(strict_types=1);

namespace SeoControlCenter\Tests\Http;

use PHPUnit\Framework\TestCase;
use SeoControlCenter\Core\Http\Exception\HttpException;
use SeoControlCenter\Core\Http\Routing\Router;
use SeoControlCenter\Domain\Keywords\KeywordLocale;
use SeoControlCenter\Modules\Research\ResearchRepository;
use SeoControlCenter\Tests\Support\OverviewFixture;

/**
 * Every link on a fully populated project overview points to a route that
 * exists. The "Research →" link on the demand card once led to /research,
 * which is 404; keyword research lives at /keywords/research.
 */
final class ProjectOverviewLinksTest extends TestCase
{
    public function testEveryProjectLinkOnTheOverviewMatchesARoute(): void
    {
        $s = OverviewFixture::scenario();
        $research = $s->container->make(ResearchRepository::class);
        $research->queue($s->acmeProject->id, $s->acme->id, 'ranked', '', ['location' => KeywordLocale::default()->locationCode, 'language' => 'en'], $s->owner->id);
        $research->complete($s->acmeProject->id, 'ranked', '', [['k' => 'indoor growing', 'v' => 600]]);
        $owner = $s->loggedIn($s->owner);
        $base = '/projects/' . $s->acmeProject->publicId;
        $html = (string) $owner->get($base)->getBody();
        self::assertStringContainsString('Demand to pursue', $html, 'The research card is rendered.');

        preg_match_all('/href="(' . preg_quote($base, '/') . '[^"#?]*)/', $html, $matches);
        $paths = array_values(array_unique($matches[1]));
        self::assertGreaterThan(15, count($paths));
        $router = $s->container->make(Router::class);
        $unmatched = [];
        foreach ($paths as $path) {
            try {
                $router->match('GET', $path);
            } catch (HttpException) {
                $unmatched[] = $path;
            }
        }
        self::assertSame([], $unmatched, 'Overview links without a route.');

        self::assertStringContainsString('href="' . $base . '/keywords/research">Research →', $html);
        self::assertSame(200, $owner->get($base . '/keywords/research')->getStatusCode());
    }
}
