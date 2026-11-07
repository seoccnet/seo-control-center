<?php

declare(strict_types=1);

namespace SeoControlCenter\Tests\Http;

use PHPUnit\Framework\TestCase;
use SeoControlCenter\Core\Support\Clock;
use SeoControlCenter\Modules\Annotations\AnnotationRepository;
use SeoControlCenter\Modules\ChangeBundles\ChangeBundleService;
use SeoControlCenter\Tests\Support\Fakes\InMemoryChangeBundles;
use SeoControlCenter\Tests\Support\Scenario;

/** Manage → Change bundles (ADR-042, 0.40.0): record, verify and guard; SEOcc never edits the live site. */
final class ChangeBundlesHttpTest extends TestCase
{
    private const string URL = 'https://shop.acme.test/mold';
    private const array PAGE = ['status' => 200, 'title' => 'Mold on pepper', 'description' => 'Old description', 'h1s' => 'Mold on pepper', 'h1_count' => 1, 'canonical' => self::URL, 'noindex' => false, 'html' => true, 'url' => self::URL, 'words' => 1200, 'text' => 'abc'];

    public function testTheGuardBlocksAChangedPageAndTheFlowEndsVerifiedOrRolledBack(): void
    {
        $s = new Scenario();
        $bundles = $s->container->make(InMemoryChangeBundles::class);
        $service = $s->container->make(ChangeBundleService::class);
        $base = '/projects/' . $s->acmeProject->publicId . '/change-bundles';
        $owner = $s->loggedIn($s->owner);
        $html = (string) $owner->get($base)->getBody();
        self::assertSame([], AccessibilityTest::problems($html, true));
        self::assertStringContainsString('SEOcc never edits your site', $html);

        $draft = ['title' => 'Clearer <b>mold</b> title', 'url' => self::URL, 'problem' => 'Low CTR on mold searches', 'evidence' => 'Search Console: 2.1 % CTR', 'edit_field' => ['title', 'description', ''], 'edit_before' => ['Mold on pepper', 'Old description', ''], 'edit_after' => ['Mold on pepper: spot, prevent, treat', 'How to spot and stop mold', ''], 'expected' => 'More clicks', 'validation' => 'The new title shows in Search Console', 'version' => '0'];
        foreach ([['url' => 'https://rival.test/mold'], ['edit_after' => ['', '', '']], ['edit_field' => ['color', '', '']], ['problem' => 'x']] as $change) {
            $owner->get($base);
            self::assertSame(422, $owner->post($base, [...$draft, ...$change])->getStatusCode(), (string) json_encode($change));
        }
        $owner->get($base);
        self::assertSame(303, $owner->post($base, $draft)->getStatusCode());
        $id = array_key_first($bundles->bundles[$s->acmeProject->id]);
        self::assertIsString($id);
        $page = $base . '/' . $id;
        $html = (string) $owner->get($page)->getBody();
        self::assertSame([], AccessibilityTest::problems($html, true));
        self::assertStringContainsString('Clearer &lt;b&gt;mold&lt;/b&gt; title', $html);
        $annotations = $s->container->make(AnnotationRepository::class);
        self::assertInstanceOf(AnnotationRepository::class, $annotations);
        $timeline = static fn(): array => $annotations->list($s->acmeProject, null, null, 10, 0);
        $version = static fn(): string => (string) ($bundles->bundles[$s->acmeProject->id][$id]['version']);
        $step = function (string $path, array $body = []) use ($owner, $page, $version): int {
            $owner->get($page);
            return $owner->post($page . $path, [...$body, 'version' => $version()])->getStatusCode();
        };

        self::assertSame(409, $step('/approve'), 'No baseline yet.');
        self::assertSame(303, $step('/check', ['purpose' => 'baseline']));
        self::assertSame(['purpose' => 'baseline'], array_intersect_key($bundles->queued[0], ['purpose' => 1]));
        $bundles->live[self::URL] = self::PAGE;
        $service->runCheck($s->acmeProject->id, $s->acme->id, $s->acmeProject->publicId, $id, 'baseline');
        self::assertSame(303, $step('/approve'));
        self::assertSame(409, $step('/deploy', ['applied' => ['0', '1']]), 'No guard check yet.');

        // Someone changes the live page after the approval: the guard blocks the deployment.
        $bundles->live[self::URL] = [...self::PAGE, 'title' => 'Edited by someone else'];
        self::assertSame(303, $step('/check', ['purpose' => 'guard']));
        $service->runCheck($s->acmeProject->id, $s->acme->id, $s->acmeProject->publicId, $id, 'guard');
        self::assertSame(409, $step('/deploy', ['applied' => ['0', '1']]));
        $html = (string) $owner->get($page)->getBody();
        self::assertStringContainsString('<strong>Blocked:</strong> The live page changed since the approval (Title).', $html);
        self::assertStringContainsString('Title: Mold on pepper → Edited by someone else', $html);
        self::assertArrayNotHasKey('annotation_id', $bundles->bundles[$s->acmeProject->id][$id]['data'], 'Nothing recorded on the timeline.');

        // A new baseline clears the approval; approved again, a matching guard allows the record.
        self::assertSame(303, $step('/check', ['purpose' => 'baseline']));
        $service->runCheck($s->acmeProject->id, $s->acme->id, $s->acmeProject->publicId, $id, 'baseline');
        self::assertSame('draft', $bundles->bundles[$s->acmeProject->id][$id]['status']);
        self::assertSame(303, $step('/approve'));
        self::assertSame(303, $step('/check', ['purpose' => 'guard']));
        $service->runCheck($s->acmeProject->id, $s->acme->id, $s->acmeProject->publicId, $id, 'guard');
        self::assertSame(422, $step('/deploy', ['applied' => []]), 'Nothing applied: record nothing.');
        self::assertSame(303, $step('/deploy', ['applied' => ['0'], 'note' => 'The CMS rejected the description']));
        $bundle = $bundles->bundles[$s->acmeProject->id][$id];
        self::assertSame('partially_deployed', $bundle['status']);
        $notes = $timeline();
        self::assertSame(['Bundle: Clearer <b>mold</b> title', 'title', self::URL], [$notes[0]->title, $notes[0]->kind, $notes[0]->url]);
        self::assertSame($notes[0]->publicId, $bundle['data']['annotation_id']);
        self::assertSame(409, $step('', $draft), 'A deployed bundle is a record.');
        self::assertSame(409, $step('/delete'), 'A deployed bundle cannot be deleted.');

        // Verification reads the applied edit back.
        $bundles->live[self::URL] = [...self::PAGE, 'title' => 'Mold on pepper: spot, prevent, treat'];
        self::assertSame(303, $step('/check', ['purpose' => 'verify']));
        $service->runCheck($s->acmeProject->id, $s->acme->id, $s->acmeProject->publicId, $id, 'verify');
        $html = (string) $owner->get($page)->getBody();
        self::assertSame([], AccessibilityTest::problems($html, true));
        self::assertStringContainsString('<span class="badge badge-ok">Live</span> Mold on pepper: spot, prevent, treat', $html);
        self::assertStringContainsString('Not applied', $html);
        self::assertSame('partially_deployed', $bundles->bundles[$s->acmeProject->id][$id]['status'], 'A partial deployment stays partial.');

        self::assertSame(422, $step('/rollback', ['note' => '']));
        self::assertSame(303, $step('/rollback', ['note' => 'Title hurt CTR, restored the old one']));
        self::assertSame('rolled_back', $bundles->bundles[$s->acmeProject->id][$id]['status']);
        self::assertContains('change_bundle.rolled_back', $s->audit()->actions());
        self::assertCount(2, $timeline());
    }

    public function testEditingAnApprovedBundleClearsItsApprovalAndViewersOnlyRead(): void
    {
        $s = new Scenario();
        $bundles = $s->container->make(InMemoryChangeBundles::class);
        $now = $s->container->make(Clock::class)->now()->format('Y-m-d H:i:s');
        $data = ['problem' => 'P', 'evidence' => '', 'edits' => [['field' => 'title', 'before' => 'a', 'after' => 'b']], 'expected' => 'E', 'validation' => 'V', 'baseline' => self::PAGE, 'baseline_at' => $now];
        $bundles->create($s->acmeProject, '0192f0a0-0000-7000-8000-000000000b51', 'Bundle', self::URL, 'draft', $data, null, $now);
        $page = '/projects/' . $s->acmeProject->publicId . '/change-bundles/0192f0a0-0000-7000-8000-000000000b51';
        $owner = $s->loggedIn($s->owner);
        $owner->get($page);
        self::assertSame(303, $owner->post($page . '/approve', ['version' => '1'])->getStatusCode());
        $owner->get($page);
        self::assertSame(303, $owner->post($page, ['title' => 'Bundle', 'url' => self::URL, 'problem' => 'Problem two', 'evidence' => '', 'edit_field' => ['title'], 'edit_before' => ['a'], 'edit_after' => ['c'], 'expected' => 'Expected', 'validation' => 'Validate', 'version' => '2'])->getStatusCode());
        $bundle = $bundles->bundles[$s->acmeProject->id]['0192f0a0-0000-7000-8000-000000000b51'];
        self::assertSame(['draft', null], [$bundle['status'], $bundle['data']['approved_hash'] ?? null]);
        self::assertSame('Content edited; the approval was cleared.', $bundles->events['0192f0a0-0000-7000-8000-000000000b51'][0]['detail']);

        $viewer = $s->loggedIn($s->viewer);
        $html = (string) $viewer->get($page)->getBody();
        self::assertStringNotContainsString('Capture again', $html);
        self::assertStringNotContainsString('New bundle', (string) $viewer->get('/projects/' . $s->acmeProject->publicId . '/change-bundles')->getBody());
        $viewer->get($page);
        self::assertSame(403, $viewer->post($page . '/approve', ['version' => '3'])->getStatusCode());
        $viewer->get($page);
        self::assertSame(403, $viewer->post($page . '/delete', ['version' => '3'])->getStatusCode());

        // An undeployed bundle can be deleted with its history (0.42.0).
        self::assertStringContainsString('Delete bundle', (string) $owner->get($page)->getBody());
        self::assertSame(409, $owner->post($page . '/delete', ['version' => '2'])->getStatusCode(), 'A stale version is refused.');
        $owner->get($page);
        self::assertSame(303, $owner->post($page . '/delete', ['version' => '3'])->getStatusCode());
        self::assertArrayNotHasKey('0192f0a0-0000-7000-8000-000000000b51', $bundles->bundles[$s->acmeProject->id]);
        self::assertArrayNotHasKey('0192f0a0-0000-7000-8000-000000000b51', $bundles->events);
        self::assertContains('change_bundle.deleted', $s->audit()->actions());
    }
}
