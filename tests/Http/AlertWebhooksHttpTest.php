<?php

declare(strict_types=1);

namespace SeoControlCenter\Tests\Http;

use PHPUnit\Framework\TestCase;
use SeoControlCenter\Core\Http\Client\HttpResponse;
use SeoControlCenter\Modules\Alerts\AlertType;
use SeoControlCenter\Modules\Alerts\WebhookService;
use SeoControlCenter\Tests\Support\Fakes\FakeHostResolver;
use SeoControlCenter\Tests\Support\Fakes\FakeHttpClient;
use SeoControlCenter\Tests\Support\Fakes\InMemoryAlerts;
use SeoControlCenter\Tests\Support\Fakes\InMemoryWebhooks;
use SeoControlCenter\Tests\Support\Scenario;

final class AlertWebhooksHttpTest extends TestCase
{
    public function testRevokedAccessAndUncertainTransfersDoNotLeakOrRetry(): void
    {
        $redactor = new \SeoControlCenter\Core\Logging\Redactor();
        self::assertSame(['webhook_url' => '[REDACTED]', 'webhook_token' => '[REDACTED]'], $redactor->redactArray(['webhook_url' => 'https://notify.example.test/secret?x=1&auth=secret', 'webhook_token' => 'SECRET-BEARER']));
        self::assertStringNotContainsString('private-hook', $redactor->redactString('https://hooks.slack.com/services/T/B/private-hook'));
        self::assertStringNotContainsString('auth=secret', $redactor->redactString('webhook_url=https://notify.example.test/secret?x=1&auth=secret'));
        $s = new Scenario();
        $owner = $s->loggedIn($s->owner);
        $owner->get('/account/notifications');
        $path = '/account/notifications/projects/' . $s->acmeProject->publicId . '/webhook';
        $owner->post($path, ['webhook_url' => 'https://notify.example.test/secret-url', 'webhook_format' => 'json', 'webhook_enabled' => '1']);
        $hooks = $s->container->make(InMemoryWebhooks::class);
        $hook = $hooks->find($s->acmeProject->id);
        self::assertNotNull($hook);
        $alerts = $s->container->make(InMemoryAlerts::class);
        $first = $alerts->record($s->acme->id, $s->acmeProject->id, AlertType::AuditErrors, 'revoked', 'New errors', [], '/projects/x');
        self::assertNotNull($first);
        $service = $s->container->make(WebhookService::class);
        $s->dir->setActive($s->owner->id, false);
        $service->deliver($first->id, $hook->publicId);
        self::assertSame('skipped', $hooks->history($s->acmeProject->id)[0]['status']);
        $http = $s->container->make(FakeHttpClient::class);
        self::assertSame([], $http->requests);
        $s->dir->setActive($s->owner->id, true);
        $second = $alerts->record($s->acme->id, $s->acmeProject->id, AlertType::AuditErrors, 'uncertain', 'New errors', [], '/projects/x');
        self::assertNotNull($second);
        $http->on('POST', 'https://notify.example.test/', static fn(): HttpResponse => throw new \RuntimeException('SECRET-BEARER at secret-url'));
        $service->deliver($second->id, $hook->publicId);
        $service->deliver($second->id, $hook->publicId);
        self::assertCount(1, $http->requests);
        $history = json_encode($hooks->history($s->acmeProject->id), JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString('SECRET-BEARER', $history);
        self::assertStringNotContainsString('secret-url', $history);
        self::assertSame('failed', $hooks->history($s->acmeProject->id)[0]['status']);
        $owner->get('/account/notifications');
        $owner->post($path, ['webhook_format' => 'json']);
        $service->queue($second);
        self::assertSame([], $alerts->queuedWebhooks, 'Disabled destinations do not queue deliveries.');
    }

    public function testSecretSettingsPermissionScopeAndExactlyOneDelivery(): void
    {
        $s = new Scenario();
        $owner = $s->loggedIn($s->owner);
        $owner->get('/account/notifications');
        $path = '/account/notifications/projects/' . $s->acmeProject->publicId . '/webhook';
        $input = ['webhook_url' => 'https://notify.example.test/private-secret', 'webhook_format' => 'json', 'webhook_token' => 'secret-token', 'webhook_enabled' => '1'];
        self::assertSame(303, $owner->post($path, $input)->getStatusCode());
        $hooks = $s->container->make(InMemoryWebhooks::class);
        $hook = $hooks->find($s->acmeProject->id);
        self::assertNotNull($hook);
        $html = (string) $owner->get('/account/notifications')->getBody();
        self::assertStringContainsString('notify.example.test', $html);
        self::assertStringNotContainsString('private-secret', $html);
        self::assertStringNotContainsString('secret-token', $html);
        $viewer = $s->loggedIn($s->viewer);
        $viewer->get('/account/notifications');
        self::assertSame(403, $viewer->post($path, $input)->getStatusCode());
        self::assertSame(404, $owner->post('/account/notifications/projects/' . $s->rivalProject->publicId . '/webhook', $input)->getStatusCode());
        $unassigned = $s->loggedIn($s->unassigned);
        $unassigned->get('/account/notifications');
        self::assertSame(404, $unassigned->post($path, $input)->getStatusCode());
        $alerts = $s->container->make(InMemoryAlerts::class);
        $alert = $alerts->record($s->acme->id, $s->acmeProject->id, AlertType::TrafficDrop, 'demo:1', 'Clicks dropped', ['40% fewer clicks'], '/projects/' . $s->acmeProject->publicId);
        self::assertNotNull($alert);
        $http = $s->container->make(FakeHttpClient::class);
        $http->on('POST', 'https://notify.example.test/', static fn(): HttpResponse => new HttpResponse(204, ''));
        $service = $s->container->make(WebhookService::class);
        $service->queue($alert);
        self::assertCount(1, $alerts->queuedWebhooks);
        $service->deliver($alert->id, $hook->publicId);
        $service->deliver($alert->id, $hook->publicId);
        self::assertCount(1, $http->requestsTo('https://notify.example.test/'));
        self::assertSame('Bearer secret-token', $http->requestsTo('https://notify.example.test/')[0]['headers']['Authorization']);
        self::assertStringContainsString('scc.alert', $http->requestsTo('https://notify.example.test/')[0]['body'] ?? '');
        self::assertSame('delivered', $hooks->history($s->acmeProject->id)[0]['status']);
        $owner->get('/account/notifications');
        self::assertSame(303, $owner->post($path, ['webhook_format' => 'json', 'webhook_enabled' => '1'])->getStatusCode());
        self::assertSame($hook->destination(), $hooks->find($s->acmeProject->id)?->destination());
        self::assertNotSame($hook->publicId, $hooks->find($s->acmeProject->id)?->publicId);
    }

    public function testPrivateDnsChangedConfigurationAndUnsafeUrlsNeverSend(): void
    {
        $s = new Scenario();
        $owner = $s->loggedIn($s->owner);
        $owner->get('/account/notifications');
        $path = '/account/notifications/projects/' . $s->acmeProject->publicId . '/webhook';
        $input = ['webhook_url' => 'https://notify.example.test/hook', 'webhook_format' => 'json', 'webhook_enabled' => '1'];
        $owner->post($path, $input);
        $hooks = $s->container->make(InMemoryWebhooks::class);
        $hook = $hooks->find($s->acmeProject->id);
        self::assertNotNull($hook);
        $alerts = $s->container->make(InMemoryAlerts::class);
        $alert = $alerts->record($s->acme->id, $s->acmeProject->id, AlertType::AuditErrors, 'demo:2', 'Audit errors', [], '/projects/x');
        self::assertNotNull($alert);
        $dns = $s->container->make(FakeHostResolver::class);
        $dns->map['notify.example.test'] = ['93.184.216.34', '127.0.0.1'];
        $service = $s->container->make(WebhookService::class);
        $service->deliver($alert->id, $hook->publicId);
        self::assertSame('failed', $hooks->history($s->acmeProject->id)[0]['status']);
        self::assertSame([], $s->container->make(FakeHttpClient::class)->requests);
        $owner->post($path, $input);
        $service->deliver($alert->id, $hook->publicId);
        self::assertCount(1, $hooks->history($s->acmeProject->id));
        foreach (['http://notify.example.test/hook', 'https://127.0.0.1/hook', 'https://user:pass@notify.example.test/', 'https://notify.example.test:443/', 'https://notify.example.test/#secret'] as $url) {
            $owner->post($path, [...$input, 'webhook_url' => $url]);
            self::assertNotSame($url, $hooks->find($s->acmeProject->id)?->destination());
        }
        $owner->post($path, [...$input, 'webhook_url' => 'https://hooks.slack.com/services/T/B/secret', 'webhook_format' => 'slack']);
        $slack = $hooks->find($s->acmeProject->id);
        self::assertNotNull($slack);
        $next = $alerts->record($s->acme->id, $s->acmeProject->id, AlertType::AuditErrors, 'demo:3', 'Safe plain text', ['<@channel>'], '/projects/x');
        self::assertNotNull($next);
        $http = $s->container->make(FakeHttpClient::class);
        $http->on('POST', 'https://hooks.slack.com/', static fn(): HttpResponse => new HttpResponse(200, 'ok'));
        $service->deliver($next->id, $slack->publicId);
        self::assertSame('delivered', $hooks->history($s->acmeProject->id)[0]['status']);
        self::assertStringContainsString('plain_text', $http->requests[0]['body'] ?? '');
        self::assertArrayNotHasKey('Authorization', $http->requests[0]['headers']);
    }
}
