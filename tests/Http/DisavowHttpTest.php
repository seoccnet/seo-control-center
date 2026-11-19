<?php

declare(strict_types=1);

namespace SeoControlCenter\Tests\Http;

use PHPUnit\Framework\TestCase;
use SeoControlCenter\Domain\Backlinks\ReferringDomain;
use SeoControlCenter\Modules\Backlinks\DisavowService;
use SeoControlCenter\Tests\Support\Fakes\InMemoryDisavows;
use SeoControlCenter\Tests\Support\Fakes\InMemoryResearch;
use SeoControlCenter\Tests\Support\Scenario;

/** Disavow file builder (1.7.0). */
final class DisavowHttpTest extends TestCase
{
    public function testNormalize(): void
    {
        self::assertSame('spam.test', DisavowService::normalize(' https://www.Spam.test/page?x=1 '));
        self::assertSame('spam.test', DisavowService::normalize('domain:spam.test'));
        self::assertSame('sub.spam.co.uk', DisavowService::normalize('sub.spam.co.uk:8080'));
        self::assertSame('xn--bcher-kva.test', DisavowService::normalize('xn--bcher-kva.test'));
        self::assertNull(DisavowService::normalize('bücher.test'));
        self::assertNull(DisavowService::normalize('localhost'));
        self::assertNull(DisavowService::normalize('spam .test'));
    }

    public function testReviewListAndFile(): void
    {
        $s = new Scenario();
        $project = $s->acmeProject;
        $url = '/projects/' . $project->publicId . '/backlinks/disavow';
        $owner = $s->loggedIn($s->owner);
        self::assertStringContainsString('No referring domains are stored yet', (string) $owner->get($url)->getBody());

        $research = $s->container->get(InMemoryResearch::class);
        $disavows = $s->container->get(InMemoryDisavows::class);
        self::assertInstanceOf(InMemoryResearch::class, $research);
        self::assertInstanceOf(InMemoryDisavows::class, $disavows);
        $research->queue($project->id, $project->workspaceId, 'bl_domains', '', ['domain' => 'shop.acme.test'], null);
        $research->complete($project->id, 'bl_domains', '', [
            (new ReferringDomain('news.test', 70, 12, 2, '2024-01-01'))->toArray(),
            (new ReferringDomain('cheap-links.test', 3, 900, 88, '2026-10-01'))->toArray(),
            (new ReferringDomain('www.casino-spam.test', 1, 40, 45, '2026-09-01'))->toArray(),
        ]);

        $html = (string) $owner->get($url)->getBody();
        self::assertMatchesRegularExpression('#cheap-links\.test</label>.*casino-spam\.test</label>#s', $html, 'Highest spam score first.');
        self::assertStringNotContainsString('news.test', $html, 'Low spam scores are hidden by default.');
        self::assertStringContainsString('news.test', (string) $owner->get($url . '?all=1')->getBody());

        $owner->get($url);
        self::assertSame(303, $owner->post($url, ['action' => 'add', 'domains' => ['cheap-links.test', 'www.casino-spam.test'], 'typed' => "domain:other-spam.test\n\n https://spam.example/x "])->getStatusCode());
        self::assertSame(['casino-spam.test', 'cheap-links.test', 'other-spam.test', 'spam.example'], array_column($disavows->domains($project->id), 'domain'));
        self::assertSame(88, $disavows->domains($project->id)[1]['spamScore']);

        $owner->get($url);
        $invalid = $owner->post($url, ['action' => 'add', 'typed' => "fine.test\nnot a domain\nshop.acme.test"]);
        self::assertSame(422, $invalid->getStatusCode());
        self::assertStringContainsString('Not a domain name: not a domain, shop.acme.test (your own site)', (string) $invalid->getBody());
        self::assertCount(4, $disavows->domains($project->id), 'Nothing is added when one line is wrong.');

        $page = (string) $owner->get($url)->getBody();
        self::assertStringContainsString('<span class="badge">listed</span>', $page);
        self::assertStringContainsString('Your disavow list (4)', $page);

        $file = $owner->get($url . '/file');
        self::assertStringContainsString('attachment; filename="disavow-shop.acme.test.txt"', $file->getHeaderLine('Content-Disposition'));
        self::assertStringStartsWith('text/plain', $file->getHeaderLine('Content-Type'));
        $text = (string) $file->getBody();
        self::assertStringStartsWith('# Disavow file for shop.acme.test', $text);
        self::assertStringEndsWith("domain:casino-spam.test\ndomain:cheap-links.test\ndomain:other-spam.test\ndomain:spam.example\n", $text);

        $owner->get($url);
        $owner->post($url, ['action' => 'remove', 'domains' => ['spam.example', 'unknown.test']]);
        self::assertCount(3, $disavows->domains($project->id));

        // Viewers can read and download, not change.
        $viewer = $s->loggedIn($s->viewer);
        $viewerPage = (string) $viewer->get($url)->getBody();
        self::assertStringNotContainsString('Add to the disavow list', $viewerPage);
        self::assertStringContainsString('Download disavow file', $viewerPage);
        $viewer->get('/account');
        self::assertSame(403, $viewer->post($url, ['action' => 'add', 'typed' => 'x.test'])->getStatusCode());
        $viewer->get('/account');
        self::assertSame(403, $viewer->post($url, ['action' => 'remove', 'domains' => ['cheap-links.test']])->getStatusCode());
        self::assertCount(3, $disavows->domains($project->id));
        self::assertStringContainsString('/backlinks/disavow', (string) $owner->get('/projects/' . $project->publicId . '/backlinks')->getBody());
    }
}
