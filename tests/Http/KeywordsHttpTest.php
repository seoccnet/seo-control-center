<?php

declare(strict_types=1);

namespace SeoControlCenter\Tests\Http;

use PHPUnit\Framework\TestCase;
use SeoControlCenter\Modules\DataForSEO\DataForSeoCredentials;
use SeoControlCenter\Tests\Support\Fakes\InMemoryDataForSeo;
use SeoControlCenter\Tests\Support\Fakes\InMemoryKeywords;
use SeoControlCenter\Tests\Support\Scenario;

/**
 * Project keywords page: permissions, validation, metrics requests and
 * tenant isolation. No provider request is ever made from a web request.
 */
final class KeywordsHttpTest extends TestCase
{
    public function testKeywordEntryOpensForFirstUseAndValidationErrors(): void
    {
        $s = new Scenario();
        $browser = $s->loggedIn($s->owner);
        $url = '/projects/' . $s->acmeProject->publicId . '/keywords';
        $doc = \Dom\HTMLDocument::createFromString((string) $browser->get($url)->getBody(), LIBXML_NOERROR);
        self::assertTrue($doc->querySelector('.keyword-entry')?->hasAttribute('open'));
        $s->container->make(InMemoryKeywords::class)->add($s->acmeProject->id, ['red shoes'], \SeoControlCenter\Domain\Keywords\KeywordLocale::default(), $s->owner->id);
        $doc = \Dom\HTMLDocument::createFromString((string) $browser->get($url)->getBody(), LIBXML_NOERROR);
        self::assertFalse($doc->querySelector('.keyword-entry')?->hasAttribute('open'));
        $response = $browser->post($url, ['keywords' => '', 'location' => '2840', 'language' => 'en']);
        self::assertSame(422, $response->getStatusCode());
        $doc = \Dom\HTMLDocument::createFromString((string) $response->getBody(), LIBXML_NOERROR);
        self::assertTrue($doc->querySelector('.keyword-entry')?->hasAttribute('open'));
        self::assertNotNull($doc->querySelector('#keywords[aria-invalid="true"]'));
    }

    private Scenario $s;

    private InMemoryKeywords $keywords;

    private InMemoryDataForSeo $dfs;

    protected function setUp(): void
    {
        $this->s = new Scenario();
        $keywords = $this->s->container->get(InMemoryKeywords::class);
        $dfs = $this->s->container->get(InMemoryDataForSeo::class);
        self::assertInstanceOf(InMemoryKeywords::class, $keywords);
        self::assertInstanceOf(InMemoryDataForSeo::class, $dfs);
        $this->keywords = $keywords;
        $this->dfs = $dfs;
    }

    public function testOwnerAddsKeywordsAndMetricsAreQueuedWhenDataForSeoIsConnected(): void
    {
        $this->dfs->save($this->s->acme->id, new DataForSeoCredentials('api@acme.test', 'x'), 5.0, $this->s->owner->id);
        $browser = $this->s->loggedIn($this->s->owner);
        $url = $this->url();
        $browser->get($url);

        $response = $browser->post($url, ['keywords' => "Red Shoes\nblue shoes\nred shoes", 'location' => '2276', 'language' => 'de']);

        self::assertSame(303, $response->getStatusCode());
        self::assertSame(2, $this->keywords->count($this->s->acmeProject->id));
        self::assertSame([['project_id' => $this->s->acmeProject->id, 'workspace_id' => $this->s->acme->id]], $this->keywords->requests);
        self::assertContains('keywords.added', $this->s->audit()->actions());

        $html = (string) $browser->get($url)->getBody();
        self::assertStringContainsString('2 keywords added. Metrics are being fetched in the background.', $html);
        self::assertStringContainsString('red shoes', $html);
        self::assertStringContainsString('Germany · German', $html);
        self::assertStringContainsString('<option value="2276" selected>', $html, 'The last used market is preselected.');

        // Adding the same keywords again adds and queues nothing.
        $browser->post($url, ['keywords' => 'red shoes', 'location' => '2276', 'language' => 'de']);
        self::assertCount(1, $this->keywords->requests);
    }

    public function testKeywordsCanBeAddedWithoutDataForSeoButNothingIsQueued(): void
    {
        $browser = $this->s->loggedIn($this->s->owner);
        $browser->get($this->url());
        $browser->post($this->url(), ['keywords' => 'red shoes', 'location' => '2840', 'language' => 'en']);

        self::assertSame(1, $this->keywords->count($this->s->acmeProject->id));
        self::assertSame([], $this->keywords->requests);
        $html = (string) $browser->get($this->url())->getBody();
        self::assertStringContainsString('Connect DataForSEO', $html);
        self::assertStringNotContainsString('Update metrics', $html);

        $browser->get('/account/security');
        $browser->post($this->url() . '/refresh');
        self::assertStringContainsString('Connect DataForSEO under Integrations first.', (string) $browser->get($this->url())->getBody());
    }

    public function testInvalidInputIsRejected(): void
    {
        $browser = $this->s->loggedIn($this->s->owner);
        $browser->get($this->url());

        $response = $browser->post($this->url(), ['keywords' => "fine\nwhat?", 'location' => '1', 'language' => 'xx']);

        self::assertSame(422, $response->getStatusCode());
        $html = (string) $response->getBody();
        self::assertStringContainsString('&quot;what?&quot; contains characters', $html);
        self::assertStringContainsString('Choose a country.', $html);
        self::assertSame(0, $this->keywords->count($this->s->acmeProject->id));
    }

    public function testRefreshQueuesMetricsAndRemoveDeletesKeyword(): void
    {
        $this->dfs->save($this->s->acme->id, new DataForSeoCredentials('api@acme.test', 'x'), 5.0, $this->s->owner->id);
        $browser = $this->s->loggedIn($this->s->member);
        $browser->get($this->url());
        $browser->post($this->url(), ['keywords' => 'red shoes', 'location' => '2840', 'language' => 'en']);
        $browser->get($this->url());

        self::assertSame(303, $browser->post($this->url() . '/refresh')->getStatusCode());
        self::assertCount(2, $this->keywords->requests, 'Members may spend analysis budget (analysis.execute).');

        $keyword = $this->keywords->keywords[$this->s->acmeProject->id][0]['public_id'];
        $browser->get($this->url());
        self::assertSame(303, $browser->post($this->url() . '/' . $keyword . '/delete')->getStatusCode());
        self::assertSame(0, $this->keywords->count($this->s->acmeProject->id));
        self::assertContains('keywords.removed', $this->s->audit()->actions());
    }

    public function testViewerCanReadButNotChangeOrSpend(): void
    {
        $this->dfs->save($this->s->acme->id, new DataForSeoCredentials('api@acme.test', 'x'), 5.0, $this->s->owner->id);
        $this->keywords->add($this->s->acmeProject->id, ['red shoes'], \SeoControlCenter\Domain\Keywords\KeywordLocale::default(), $this->s->owner->id);
        $browser = $this->s->loggedIn($this->s->viewer);

        $html = (string) $browser->get($this->url())->getBody();
        self::assertStringContainsString('red shoes', $html);
        self::assertStringNotContainsString('name="keywords"', $html);
        self::assertStringNotContainsString('Update metrics', $html);

        $browser->get('/account/security');
        $csrf = $browser->csrfToken();
        self::assertSame(403, $browser->post($this->url(), ['keywords' => 'x', 'location' => '2840', 'language' => 'en', '_csrf' => $csrf])->getStatusCode());
        self::assertSame(403, $browser->post($this->url() . '/refresh', ['_csrf' => $csrf])->getStatusCode());
        $keyword = $this->keywords->keywords[$this->s->acmeProject->id][0]['public_id'];
        self::assertSame(403, $browser->post($this->url() . '/' . $keyword . '/delete', ['_csrf' => $csrf])->getStatusCode());
        self::assertSame([], $this->keywords->requests);
    }

    public function testOtherWorkspacesAndUnassignedMembersGetNotFound(): void
    {
        $this->keywords->add($this->s->acmeProject->id, ['secret keyword'], \SeoControlCenter\Domain\Keywords\KeywordLocale::default(), $this->s->owner->id);
        $keyword = $this->keywords->keywords[$this->s->acmeProject->id][0]['public_id'];

        foreach ([$this->s->rivalOwner, $this->s->unassigned] as $user) {
            $browser = $this->s->loggedIn($user);
            self::assertSame(404, $browser->get($this->url())->getStatusCode());
            $browser->get('/account/security');
            $csrf = $browser->csrfToken();
            self::assertSame(404, $browser->post($this->url(), ['keywords' => 'x', 'location' => '2840', 'language' => 'en', '_csrf' => $csrf])->getStatusCode());
            self::assertSame(404, $browser->post($this->url() . '/refresh', ['_csrf' => $csrf])->getStatusCode());
            self::assertSame(404, $browser->post($this->url() . '/' . $keyword . '/delete', ['_csrf' => $csrf])->getStatusCode());
        }
        self::assertSame(1, $this->keywords->count($this->s->acmeProject->id));

        // A keyword ID from another project cannot be deleted through one's own project.
        $rival = $this->s->loggedIn($this->s->rivalOwner);
        $rival->get('/account/security');
        self::assertSame(404, $rival->post('/projects/' . $this->s->rivalProject->publicId . '/keywords/' . $keyword . '/delete')->getStatusCode());
        self::assertSame(1, $this->keywords->count($this->s->acmeProject->id));
    }

    public function testProjectPageLinksToKeywords(): void
    {
        $html = (string) $this->s->loggedIn($this->s->owner)->get('/projects/' . $this->s->acmeProject->publicId)->getBody();
        self::assertStringContainsString('href="' . $this->url() . '"', $html);
        self::assertMatchesRegularExpression('#href="/assets/css/app\.css\?v=[1-9][0-9]+"#', $html, 'Assets carry a version so browsers never use stale styles.');
    }

    private function url(): string
    {
        return '/projects/' . $this->s->acmeProject->publicId . '/keywords';
    }
}
