<?php

declare(strict_types=1);

namespace SeoControlCenter\Tests\Http;

use PHPUnit\Framework\TestCase;
use SeoControlCenter\Domain\LabTests\LabResult;
use SeoControlCenter\Modules\LabTests\LabTestService;
use SeoControlCenter\Tests\Support\Fakes\FakeLabProviders;
use SeoControlCenter\Tests\Support\Fakes\InMemoryLabTests;
use SeoControlCenter\Tests\Support\Fakes\InMemoryWebVitals;
use SeoControlCenter\Tests\Support\Scenario;
use SeoControlCenter\Tests\Unit\LabTests\LabDiagnosticsTest;

/** Improve → Speed tests: why a run scored as it did, and comparisons only between compatible runs (ADR-042, 0.38.0). */
final class LabDiagnosticsHttpTest extends TestCase
{
    public function testTheLatestRunExplainsItselfAndADifferentSetupIsNotCompared(): void
    {
        $s = new Scenario();
        $vitals = $s->container->get(InMemoryWebVitals::class);
        self::assertInstanceOf(InMemoryWebVitals::class, $vitals);
        $vitals->saveKey($s->acme->id, 'AIzaSyTestKey0123456789abcdef');
        $providers = $s->container->get(FakeLabProviders::class);
        self::assertInstanceOf(FakeLabProviders::class, $providers);
        $tests = $s->container->get(InMemoryLabTests::class);
        self::assertInstanceOf(InMemoryLabTests::class, $tests);
        $service = $s->container->make(LabTestService::class);
        $url = '/projects/' . $s->acmeProject->publicId . '/lab-tests';

        $old = new LabResult(0.8, 2600.0, 0.05, 200.0, 1200.0, 2800.0, '11.4.0');
        $providers->pageSpeed[] = $old;
        $owner = $s->loggedIn($s->owner);
        $owner->get($url);
        $owner->post($url, ['url' => 'https://shop.acme.test/seeds', 'device' => 'mobile', 'source' => 'pagespeed']);
        $service->execute($tests->queued[0]['run_id']);
        $html = (string) $owner->get($url)->getBody();
        self::assertStringContainsString('This run has no diagnostics', $html, 'A run without diagnostics says so.');

        $report = LabDiagnosticsTest::report();
        $report['audits']['render-blocking-resources']['details']['items'][0]['url'] = 'https://shop.test/<script>x</script>.css';
        $providers->pageSpeed[] = LabResult::fromLighthouse($report, 'https://shop.test/seeds', 'mobile');
        $profile = array_values($tests->profiles)[0];
        $owner->post($url . '/' . $profile->publicId . '/run');
        $service->execute($tests->queued[1]['run_id']);
        $html = (string) $owner->get($url)->getBody();
        self::assertSame([], AccessibilityTest::problems($html, true));
        self::assertStringContainsString('Why the latest run scored 71', $html);
        self::assertStringContainsString('Hero photo of a tomato plant', $html);
        self::assertStringContainsString('&lt;img class=&quot;hero&quot; src=&quot;/media/hero.webp&quot;', $html, 'The snippet is shown as text.');
        self::assertStringContainsString('Eliminate render-blocking resources', $html);
        self::assertStringContainsString('&lt;script&gt;x&lt;/script&gt;.css', $html);
        self::assertStringNotContainsString('<script>x</script>', $html);
        self::assertStringContainsString('412×823 @1.75x', $html);
        self::assertStringContainsString('The full report is not stored', $html);
        self::assertStringContainsString('Score 71 (not comparable with the previous run: Lighthouse 12.3.0 and 11.4.0 score differently)', $html, 'No score change across Lighthouse versions.');
        self::assertStringContainsString('Different setup', $html);
        self::assertStringNotContainsString('since the previous run', $html);

        // Lighthouse 13 from PageSpeed Insights: the LCP comes from its insights, and the trimmed settings are explained (0.38.1).
        $providers->pageSpeed[] = LabResult::fromLighthouse(LabDiagnosticsTest::lighthouse13(), 'https://shop.test/seeds', 'mobile');
        $owner->post($url . '/' . $profile->publicId . '/run');
        $service->execute($tests->queued[2]['run_id']);
        $html = (string) $owner->get($url)->getBody();
        self::assertSame([], AccessibilityTest::problems($html, true));
        self::assertStringContainsString('Resource load delay', $html);
        self::assertStringContainsString('<td class="num">54 %</td><td class="num">4,100 ms</td>', $html, 'Phases are shown as shares of the observed load.');
        self::assertStringContainsString("They total 7,535 ms, so they don't add up to the run's simulated LCP of 2,412 ms.", html_entity_decode($html, ENT_QUOTES | ENT_HTML5));
        self::assertStringContainsString('To fix</span> fetchpriority=high should be applied', $html);
        self::assertStringContainsString('Also flagged</strong> (no estimated saving): Network dependency tree.', $html);
        self::assertStringContainsString('PageSpeed Insights uses its fixed mobile (a mid-range phone on a slow 4G connection) settings and does not report them.', $html);
        self::assertStringContainsString('Lighthouse 13.5.0 and 12.3.0 score differently', $html);
    }
}
