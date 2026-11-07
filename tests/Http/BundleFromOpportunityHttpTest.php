<?php

declare(strict_types=1);

namespace SeoControlCenter\Tests\Http;

use PHPUnit\Framework\TestCase;
use SeoControlCenter\Domain\Opportunities\Opportunity;
use SeoControlCenter\Domain\Opportunities\OpportunityType;
use SeoControlCenter\Modules\Annotations\AnnotationRepository;
use SeoControlCenter\Modules\ChangeBundles\ChangeBundleService;
use SeoControlCenter\Tests\Support\Fakes\InMemoryChangeBundles;
use SeoControlCenter\Tests\Support\Fakes\InMemoryMeasurementPlans;
use SeoControlCenter\Tests\Support\Fakes\InMemoryOpportunities;
use SeoControlCenter\Tests\Support\Scenario;

/** An opportunity becomes a change bundle, and its deployment gets a measurement plan (0.41.0). */
final class BundleFromOpportunityHttpTest extends TestCase
{
    private const string URL = 'https://shop.acme.test/red';

    public function testTheFindingFillsTheBundleAndTheDeploymentDraftsAPlan(): void
    {
        $s = new Scenario();
        $opportunities = $s->container->get(InMemoryOpportunities::class);
        self::assertInstanceOf(InMemoryOpportunities::class, $opportunities);
        $opportunities->sync($s->acmeProject->id, [new Opportunity(OpportunityType::LowCtr, 'query:red shoes', 'Improve the CTR of “red shoes”', 'The page ranks but few searchers click.', [['label' => 'Query', 'value' => 'red shoes'], ['label' => 'Page', 'value' => self::URL], ['label' => 'CTR', 'value' => '1.2 %']], 55, 60, 1)]);
        $id = $opportunities->list($s->acmeProject->id, 'open', null, 10, 0)[0]->publicId;
        $owner = $s->loggedIn($s->owner);

        $detail = (string) $owner->get('/projects/' . $s->acmeProject->publicId . '/opportunities/' . $id)->getBody();
        self::assertStringContainsString('Tested repeatedly</span>', $detail, 'The plan says how well tested its kind of change is.');
        self::assertStringContainsString('/change-bundles?opportunity=' . $id, $detail);
        self::assertSame([], AccessibilityTest::problems($detail, true));
        self::assertStringNotContainsString('Start a change bundle', (string) $s->loggedIn($s->viewer)->get('/projects/' . $s->acmeProject->publicId . '/opportunities/' . $id)->getBody());

        $base = '/projects/' . $s->acmeProject->publicId . '/change-bundles';
        $form = (string) $owner->get($base . '?opportunity=' . $id)->getBody();
        self::assertSame([], AccessibilityTest::problems($form, true));
        self::assertStringContainsString('Started from an opportunity', $form);
        self::assertStringContainsString('value="Improve the CTR of “red shoes”"', $form);
        self::assertStringContainsString('value="' . self::URL . '"', $form);
        self::assertStringContainsString('Query: red shoes', $form);
        self::assertMatchesRegularExpression('/name="edit_field\[\]"><option value="">—<\/option><option value="title" selected>/', $form);
        self::assertStringContainsString('<input type="hidden" name="opportunity" value="' . $id . '">', $form);
        self::assertStringNotContainsString('Started from an opportunity', (string) $owner->get($base . '?opportunity=0192f0a0-0000-7000-8000-000000000c99')->getBody(), 'An unknown finding fills nothing.');

        $owner->get($base);
        self::assertSame(303, $owner->post($base, ['title' => 'Improve the CTR of red shoes', 'url' => self::URL, 'problem' => 'Low CTR', 'evidence' => '', 'edit_field' => ['title', 'description'], 'edit_before' => ['Red shoes', ''], 'edit_after' => ['Red shoes: 40 styles, free returns', 'Find your red shoes'], 'expected' => 'More clicks', 'validation' => 'Revert if CTR falls', 'opportunity' => $id, 'version' => '0'])->getStatusCode());
        $bundles = $s->container->make(InMemoryChangeBundles::class);
        $bundleId = (string) array_key_first($bundles->bundles[$s->acmeProject->id]);
        self::assertSame(['opportunity_id' => $id, 'playbook' => 'low_ctr', 'metric' => 'ctr', 'days' => 28, 'query' => 'red shoes'], array_intersect_key($bundles->bundles[$s->acmeProject->id][$bundleId]['data']['source'] ?? [], ['opportunity_id' => 1, 'playbook' => 1, 'metric' => 1, 'days' => 1, 'query' => 1]));

        // Baseline, approval, a matching guard, then the deployment record.
        $service = $s->container->make(ChangeBundleService::class);
        $page = $base . '/' . $bundleId;
        $step = function (string $path, array $body = []) use ($owner, $page, $bundles, $s, $bundleId): int {
            $owner->get($page);
            return $owner->post($page . $path, [...$body, 'version' => (string) $bundles->bundles[$s->acmeProject->id][$bundleId]['version']])->getStatusCode();
        };
        $bundles->live[self::URL] = ['status' => 200, 'title' => 'Red shoes', 'description' => 'Old', 'h1s' => 'Red shoes', 'canonical' => self::URL, 'noindex' => false, 'html' => true, 'url' => self::URL, 'words' => 300, 'text' => 'x'];
        $step('/check', ['purpose' => 'baseline']);
        $service->runCheck($s->acmeProject->id, $s->acme->id, $s->acmeProject->publicId, $bundleId, 'baseline');
        $step('/approve');
        $step('/check', ['purpose' => 'guard']);
        $service->runCheck($s->acmeProject->id, $s->acmeProject->workspaceId, $s->acmeProject->publicId, $bundleId, 'guard');
        self::assertSame(303, $step('/deploy', ['applied' => ['0', '1']]));

        $annotation = $s->container->make(AnnotationRepository::class)->list($s->acmeProject, null, null, 10, 0)[0];
        $plan = $s->container->make(InMemoryMeasurementPlans::class)->find($s->acmeProject, $annotation->publicId);
        self::assertNotNull($plan, 'A measurement plan is drafted on the recorded change.');
        self::assertSame(['ctr', [self::URL], ['red shoes'], 28, null], [$plan['metric'], $plan['target_pages'], $plan['target_queries'], $plan['days'], $plan['baseline']]);
        self::assertStringContainsString('“red shoes”', $plan['objective']);
        self::assertSame('plan_drafted', $bundles->events[$bundleId][0]['kind']);
    }
}
