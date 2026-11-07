<?php

declare(strict_types=1);

namespace SeoControlCenter\Tests\Http;

use Dom\HTMLDocument;
use PHPUnit\Framework\TestCase;
use SeoControlCenter\Core\Support\ClientInfo;
use SeoControlCenter\Domain\Integrations\ApiBudget;
use SeoControlCenter\Domain\Keywords\KeywordLocale;
use SeoControlCenter\Domain\Projects\ProjectService;
use SeoControlCenter\Domain\Quests\SetupStatusReader;
use SeoControlCenter\Domain\Rankings\RankingDay;
use SeoControlCenter\Domain\Rankings\RankingSettings;
use SeoControlCenter\Domain\Rankings\SerpDevice;
use SeoControlCenter\Modules\DataForSEO\DataForSeoCredentials;
use SeoControlCenter\Modules\Quests\Setup\FoundationsChecks;
use SeoControlCenter\Tests\Support\Fakes\FakeHttpClient;
use SeoControlCenter\Tests\Support\Fakes\InMemoryDataForSeo;
use SeoControlCenter\Tests\Support\Fakes\InMemoryIntegrations;
use SeoControlCenter\Tests\Support\Fakes\InMemoryKeywords;
use SeoControlCenter\Tests\Support\Fakes\InMemoryRankings;
use SeoControlCenter\Tests\Support\Fakes\InMemorySearchPerformance;
use SeoControlCenter\Tests\Support\Scenario;

/**
 * Quest Log Chapter 1 (Foundations): every setup status, hint, permission and
 * link case of the former Get started guide (GettingStartedHttpTest), now
 * served at /quests?chapter=foundations.
 */
final class QuestLogFoundationsHttpTest extends TestCase
{
    private const string PAGE = '/quests?chapter=foundations';

    public function testFoundationsAreReadOnlyAndDoNotStartPaidWork(): void
    {
        $s = new Scenario();
        $browser = $s->loggedIn($s->owner);
        $response = $browser->get(self::PAGE);
        self::assertSame(200, $response->getStatusCode());
        $html = (string) $response->getBody();
        self::assertStringContainsString('Let’s get your website tracking', $html);
        self::assertStringContainsString('Google Search Console is optional', $html);
        self::assertStringContainsString('Adding keywords can already start paid metric lookups', $html);
        $doc = HTMLDocument::createFromString($html, LIBXML_NOERROR);
        self::assertCount(7, $doc->querySelectorAll('.onboarding-steps > li'));
        self::assertCount(0, $doc->querySelectorAll('.onboarding-steps form'));
        foreach ($doc->querySelectorAll('main form[method="post"]') as $form) {
            self::assertSame('/quests/visibility', $form->getAttribute('action'));
        }
        self::assertSame([], $s->container->make(InMemoryKeywords::class)->requests);
        self::assertSame([], $s->container->make(InMemoryRankings::class)->requests);
        self::assertSame([], $s->container->make(FakeHttpClient::class)->requests);
    }

    public function testViewerGetsGuidanceWithoutManagementActions(): void
    {
        $s = new Scenario();
        $html = (string) $s->loggedIn($s->viewer)->get(self::PAGE)->getBody();
        $doc = HTMLDocument::createFromString($html, LIBXML_NOERROR);
        self::assertCount(0, $doc->querySelectorAll('.onboarding-steps a[href="/integrations/dataforseo"]'));
        self::assertCount(0, $doc->querySelectorAll('.onboarding-steps a[href$="/keywords"]'));
        self::assertCount(0, $doc->querySelectorAll('a[href^="/system/"]'));
        self::assertStringContainsString('Ask your workspace owner or installation administrator', $html);
        // Owner/administrator steps stay visible with their live status, outside the viewer's own progress.
        self::assertSame('Handled by your administrator', $this->step($html, 'Connect DataForSEO and set a budget')->querySelector('.quest-chip')?->textContent);
        self::assertSame('Needs setup', $this->step($html, 'Connect DataForSEO and set a budget')->querySelector('.onboarding-status')?->textContent);
    }

    public function testFoundationsRejectOtherTenantAndUnassignedProjects(): void
    {
        $s = new Scenario();
        self::assertSame(404, $s->loggedIn($s->owner)->get('/quests?project=' . $s->rivalProject->publicId)->getStatusCode());
        self::assertSame(404, $s->loggedIn($s->unassigned)->get('/quests?project=' . $s->acmeProject->publicId)->getStatusCode());
        self::assertSame(404, $s->loggedIn($s->owner)->get('/get-started?project=' . $s->rivalProject->publicId)->getStatusCode());
        self::assertSame(404, $s->loggedIn($s->unassigned)->get('/get-started?project=' . $s->acmeProject->publicId)->getStatusCode());
    }

    public function testTrackingWaitsForActualResultsAndDoesNotRequireGoogle(): void
    {
        $s = new Scenario();
        $this->cron($s, 'Ready');
        $id = $s->acmeProject->id;
        $provider = $s->container->make(InMemoryDataForSeo::class);
        $provider->save($s->acme->id, new DataForSeoCredentials('api@example.test', 'secret'), 20, $s->owner->id);
        $provider->saveBudget($s->acme->id, 'dataforseo', new ApiBudget(monthlyLimit: 10), $s->owner->id);
        $s->container->make(InMemoryKeywords::class)->add($id, ['seo software'], new KeywordLocale(2840, 'en'), $s->owner->id);
        $rankings = $s->container->make(InMemoryRankings::class);
        $rankings->saveSettings($id, new RankingSettings(true, SerpDevice::Desktop, RankingSettings::WEEKLY), $s->owner->id);
        $browser = $s->loggedIn($s->owner);
        $waiting = (string) $browser->get(self::PAGE)->getBody();
        self::assertStringContainsString('Waiting for first results', $waiting);
        self::assertStringNotContainsString('Your first results are available', $waiting);
        $rankings->days[$id . ':desktop:2026-10-08'] = new RankingDay('2026-10-08', 1, 1, 0, 1, 5, 10);
        $ready = (string) $browser->get(self::PAGE)->getBody();
        self::assertStringContainsString('Your first results are available', $ready);
        $rankings->setSyncState($id, 'error', 'Provider unavailable');
        $error = (string) $browser->get(self::PAGE)->getBody();
        self::assertStringContainsString('A background import or rank check needs attention', $error);
        self::assertSame([], $rankings->requests);
    }

    public function testRevokedGoogleConnectionNeedsAttentionEvenWithStoredData(): void
    {
        $s = new Scenario();
        $google = $s->container->make(InMemoryIntegrations::class);
        $google->save('google', new \SeoControlCenter\Domain\Integrations\OAuthClientCredentials('client', 'secret'), $s->owner->id);
        $connection = $google->upsert($s->acme->id, 'google', 'owner@example.test', 'read', $s->owner->id);
        $google->link($s->acmeProject->id, $connection->id, 'sc-domain:acme.test', $s->owner->id);
        $google->setStatus($connection->id, 'error', 'Access revoked');
        $s->container->make(InMemorySearchPerformance::class)->daily[$s->acmeProject->id]['2026-10-08'] = new \SeoControlCenter\Domain\SearchConsole\Metrics(5, 100, 8);
        $html = (string) $s->loggedIn($s->owner)->get(self::PAGE)->getBody();
        self::assertStringContainsString('Your Google connection needs attention', $html);
        self::assertStringContainsString('Review Google connection', $html);
    }

    public function testUserWithoutWorkspaceSeesUsefulGuidance(): void
    {
        $s = new Scenario();
        $user = $s->addUser('new@example.test');
        $browser = $s->loggedIn($user);
        $redirect = $browser->get('/get-started');
        self::assertSame(302, $redirect->getStatusCode());
        self::assertSame(self::PAGE, $redirect->getHeaderLine('Location'));
        $response = $browser->get(self::PAGE);
        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('You need a workspace first', (string) $response->getBody());
    }

    public function testSelectedProjectSurvivesLeavingTheQuestLog(): void
    {
        $s = new Scenario();
        $second = $s->container->make(ProjectService::class)->create($s->owner, $s->acme, ['name' => 'Second website', 'primary_domain' => 'second.example', 'timezone' => 'Europe/Berlin'], new ClientInfo('203.0.113.10', 'test', null));
        $browser = $s->loggedIn($s->owner);
        self::assertSame(200, $browser->get(self::PAGE . '&project=' . $second->publicId)->getStatusCode());
        $doc = HTMLDocument::createFromString((string) $browser->get(self::PAGE)->getBody(), LIBXML_NOERROR);
        self::assertSame($second->publicId, $doc->querySelector('#setup-project option[selected]')?->getAttribute('value'));
    }

    public function testResultsLinkUsesTheSourceThatActuallyHasResults(): void
    {
        $s = new Scenario();
        $google = $s->container->make(InMemoryIntegrations::class);
        $google->save('google', new \SeoControlCenter\Domain\Integrations\OAuthClientCredentials('client', 'secret'), $s->owner->id);
        $connection = $google->upsert($s->acme->id, 'google', 'owner@example.test', 'read', $s->owner->id);
        $google->replaceProperties($connection->id, [['site_url' => 'sc-domain:acme.test', 'permission_level' => 'siteOwner']]);
        $google->link($s->acmeProject->id, $connection->id, 'sc-domain:acme.test', $s->owner->id);
        $rankings = $s->container->make(InMemoryRankings::class);
        $s->container->make(InMemoryKeywords::class)->add($s->acmeProject->id, ['seo software'], new KeywordLocale(2840, 'en'), $s->owner->id);
        $rankings->saveSettings($s->acmeProject->id, new RankingSettings(true, SerpDevice::Desktop, RankingSettings::WEEKLY), $s->owner->id);
        $rankings->days[$s->acmeProject->id . ':desktop:2026-10-08'] = new RankingDay('2026-10-08', 1, 1, 0, 1, 5, 10);
        $html = (string) $s->loggedIn($s->owner)->get(self::PAGE)->getBody();
        self::assertSame('/projects/' . $s->acmeProject->publicId . '/rankings', $this->step($html, 'See your first results')->querySelector('a')?->getAttribute('href'));
    }

    public function testEnabledTrackingWithoutKeywordsOrProviderIsNotReady(): void
    {
        $s = new Scenario();
        $s->container->make(InMemoryRankings::class)->saveSettings($s->acmeProject->id, new RankingSettings(true, SerpDevice::Desktop, RankingSettings::WEEKLY), $s->owner->id);
        $html = (string) $s->loggedIn($s->owner)->get(self::PAGE)->getBody();
        self::assertSame('Needs attention', $this->step($html, 'Switch on rank tracking')->querySelector('.onboarding-status')?->textContent);
        self::assertStringContainsString('Add at least one keyword', $html);
    }

    public function testZeroBudgetIsNotReadyForPaidTracking(): void
    {
        $s = new Scenario();
        $provider = $s->container->make(InMemoryDataForSeo::class);
        $provider->save($s->acme->id, new DataForSeoCredentials('api@example.test', 'secret'), 20, $s->owner->id);
        $provider->saveBudget($s->acme->id, 'dataforseo', new ApiBudget(monthlyLimit: 0), $s->owner->id);
        $html = (string) $s->loggedIn($s->owner)->get(self::PAGE)->getBody();
        self::assertSame('Needs attention', $this->step($html, 'Connect DataForSEO and set a budget')->querySelector('.onboarding-status')?->textContent);
        self::assertStringContainsString('budget is zero or used up', $html);
    }

    public function testGoogleStartsAtAccountConnectionWhenNoneIsConnected(): void
    {
        $s = new Scenario();
        $s->container->make(InMemoryIntegrations::class)->save('google', new \SeoControlCenter\Domain\Integrations\OAuthClientCredentials('client', 'secret'), $s->owner->id);
        $html = (string) $s->loggedIn($s->owner)->get(self::PAGE)->getBody();
        self::assertSame('/integrations', $this->step($html, 'Connect Google Search Console')->querySelector('a')?->getAttribute('href'));
    }

    public function testExhaustedBudgetAndEmptyCreditNeedAttention(): void
    {
        $s = new Scenario();
        $provider = $s->container->make(InMemoryDataForSeo::class);
        $provider->save($s->acme->id, new DataForSeoCredentials('api@example.test', 'secret'), 20, $s->owner->id);
        $provider->reserve($s->acme->id, $s->acmeProject->id, 'dataforseo', 'fixture', 10);
        $provider->saveBudget($s->acme->id, 'dataforseo', new ApiBudget(monthlyLimit: 10), $s->owner->id);
        $browser = $s->loggedIn($s->owner);
        $html = (string) $browser->get(self::PAGE)->getBody();
        self::assertSame('Needs attention', $this->step($html, 'Connect DataForSEO and set a budget')->querySelector('.onboarding-status')?->textContent);
        self::assertStringContainsString('budget is zero or used up', $html);
        $provider->saveBudget($s->acme->id, 'dataforseo', new ApiBudget(monthlyLimit: 20), $s->owner->id);
        $provider->recordCheck($s->acme->id, 0);
        $emptyCredit = (string) $browser->get(self::PAGE)->getBody();
        self::assertSame('Needs attention', $this->step($emptyCredit, 'Connect DataForSEO and set a budget')->querySelector('.onboarding-status')?->textContent);
        self::assertStringContainsString('last account check reported no credit', $emptyCredit);
    }

    public function testOldSearchDataDoesNotCompleteANewPropertyImport(): void
    {
        $s = new Scenario();
        $this->cron($s, 'Ready');
        $google = $s->container->make(InMemoryIntegrations::class);
        $google->save('google', new \SeoControlCenter\Domain\Integrations\OAuthClientCredentials('client', 'secret'), $s->owner->id);
        $connection = $google->upsert($s->acme->id, 'google', 'owner@example.test', 'read', $s->owner->id);
        $google->replaceProperties($connection->id, [['site_url' => 'sc-domain:new.example', 'permission_level' => 'siteOwner']]);
        $google->link($s->acmeProject->id, $connection->id, 'sc-domain:new.example', $s->owner->id);
        $performance = $s->container->make(InMemorySearchPerformance::class);
        $performance->daily[$s->acmeProject->id]['2026-10-08'] = new \SeoControlCenter\Domain\SearchConsole\Metrics(5, 100, 8);
        $performance->saveSyncState(\SeoControlCenter\Domain\SearchConsole\SyncState::fresh($s->acmeProject->id, 'sc-domain:old.example')->with(status: 'error'));
        $html = (string) $s->loggedIn($s->owner)->get(self::PAGE)->getBody();
        self::assertSame('Waiting for first results', $this->step($html, 'See your first results')->querySelector('.onboarding-status')?->textContent);
        self::assertStringNotContainsString('Your first results are available', $html);
        self::assertStringNotContainsString('latest import failed', $html);
    }

    public function testOldRankingAggregateWithoutKeywordsDoesNotCompleteSetup(): void
    {
        $s = new Scenario();
        $rankings = $s->container->make(InMemoryRankings::class);
        $rankings->saveSettings($s->acmeProject->id, new RankingSettings(true, SerpDevice::Desktop, RankingSettings::WEEKLY), $s->owner->id);
        $rankings->days[$s->acmeProject->id . ':desktop:2026-10-08'] = new RankingDay('2026-10-08', 1, 1, 0, 1, 5, 10);
        $html = (string) $s->loggedIn($s->owner)->get(self::PAGE)->getBody();
        self::assertStringNotContainsString('Your first results are available', $html);
        self::assertStringContainsString('Complete the setup checks above', $html);
    }

    public function testFeatureTurnedOffIsLeftOut(): void
    {
        $s = new Scenario();
        $s->container->make(\SeoControlCenter\Tests\Support\Fakes\FixedFeatures::class)->off = ['rank_tracking'];
        $html = (string) $s->loggedIn($s->owner)->get(self::PAGE)->getBody();
        $doc = HTMLDocument::createFromString($html, LIBXML_NOERROR);
        self::assertCount(6, $doc->querySelectorAll('.onboarding-steps > li'));
        self::assertNull($this->find($html, 'Switch on rank tracking'));
        foreach (['Not included', 'license', 'upgrade', 'Upgrade'] as $upsell) {
            self::assertStringNotContainsString($upsell, $html);
        }
    }

    public function testStoppedCronDoesNotPretendWorkIsRunning(): void
    {
        $s = new Scenario();
        $this->cron($s, 'Needs attention');
        $provider = $s->container->make(InMemoryDataForSeo::class);
        $provider->save($s->acme->id, new DataForSeoCredentials('api@example.test', 'secret'), 20, $s->owner->id);
        $provider->saveBudget($s->acme->id, 'dataforseo', new ApiBudget(monthlyLimit: 10), $s->owner->id);
        $s->container->make(InMemoryKeywords::class)->add($s->acmeProject->id, ['seo software'], new KeywordLocale(2840, 'en'), $s->owner->id);
        $s->container->make(InMemoryRankings::class)->saveSettings($s->acmeProject->id, new RankingSettings(true, SerpDevice::Desktop, RankingSettings::WEEKLY), $s->owner->id);
        $html = (string) $s->loggedIn($s->owner)->get(self::PAGE)->getBody();
        self::assertStringContainsString('Next: Keep background tasks running', $html);
        self::assertStringNotContainsString('Waiting for first results', $html);
        self::assertStringContainsString('Complete the setup checks above', $html);
    }

    public function testAdministratorSeesCronInstructionsAsATip(): void
    {
        $s = new Scenario();
        $root = $s->addUser('root@acme.test', \SeoControlCenter\Domain\Accounts\SystemRole::Admin);
        $s->dir->joinWorkspace($s->acme, $root, 'owner');
        $html = (string) $s->loggedIn($root)->get(self::PAGE)->getBody();
        self::assertStringContainsString('How to set up the cron job', $this->step($html, 'Keep background tasks running')->textContent ?? '');
        self::assertStringNotContainsString('How to set up the cron job', (string) $s->loggedIn($s->owner)->get(self::PAGE)->getBody());
    }

    private function step(string $html, string $title): \Dom\Element
    {
        return $this->find($html, $title) ?? self::fail('Foundations step missing: ' . $title);
    }

    private function find(string $html, string $title): ?\Dom\Element
    {
        $doc = HTMLDocument::createFromString($html, LIBXML_NOERROR);
        foreach ($doc->querySelectorAll('.onboarding-steps > li') as $step) {
            if ($step->querySelector('h3')?->textContent === $title) {
                return $step;
            }
        }

        return null;
    }

    private function cron(Scenario $s, string $status): void
    {
        $c = $s->container;
        $checks = new FoundationsChecks(
            $c->make(ProjectService::class),
            $c->make(\SeoControlCenter\Modules\SearchConsole\SearchConsoleService::class),
            $c->make(\SeoControlCenter\Modules\DataForSEO\DataForSeoService::class),
            $c->make(\SeoControlCenter\Domain\Keywords\KeywordRepository::class),
            $c->make(\SeoControlCenter\Domain\Rankings\RankingRepository::class),
            $c->make(\SeoControlCenter\Domain\SearchConsole\SearchPerformanceReader::class),
            $c->make(\SeoControlCenter\Domain\SearchConsole\SearchPerformanceStore::class),
            $c->make(\SeoControlCenter\Core\Features\FeatureManager::class),
            static fn(): string => $status,
        );
        $c->instance(FoundationsChecks::class, $checks);
        $c->instance(SetupStatusReader::class, $checks);
    }
}
