<?php

declare(strict_types=1);

namespace SeoControlCenter\Tests\TenantIsolation;

use PHPUnit\Framework\TestCase;
use SeoControlCenter\Tests\Support\Scenario;

final class ReportCustomizationIsolationTest extends TestCase
{
    public function testReportSettingsAndAssetsRequireVisibleProjectAndCsrf(): void
    {
        $s = new Scenario();
        $member = $s->loggedIn($s->member);
        foreach ([$s->acmeOther, $s->rivalProject] as $project) {
            $base = '/projects/' . $project->publicId . '/reports';
            foreach (['/logo', '/branding.css'] as $suffix) {
                self::assertSame(404, $member->get($base . $suffix)->getStatusCode());
            }
            foreach (['/sections', '/branding', '/logo'] as $suffix) {
                $member->get('/');
                self::assertSame(404, $member->post($base . $suffix)->getStatusCode());
            }
        }
        $owner = $s->loggedIn($s->owner);
        foreach (['/sections', '/branding', '/logo'] as $suffix) {
            $owner->get('/');
            self::assertSame(419, $owner->post('/projects/' . $s->acmeProject->publicId . '/reports' . $suffix, withCsrf: false)->getStatusCode());
        }
    }
}
