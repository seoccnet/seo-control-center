<?php

declare(strict_types=1);

namespace SeoControlCenter\Tests\Http;

use Dom\Element;
use Dom\HTMLDocument;
use PHPUnit\Framework\TestCase;
use SeoControlCenter\Domain\Accounts\SystemRole;
use SeoControlCenter\Tests\Support\Browser;
use SeoControlCenter\Tests\Support\Scenario;

/**
 * Accessibility baseline (0.8): every page has a language, a title, one h1,
 * a main landmark and a skip link; every form control has an accessible
 * name; buttons and links have text; data tables have header cells; ids are
 * unique. Automated checks do not replace manual keyboard and screen-reader
 * testing, but they keep regressions out.
 */
final class AccessibilityTest extends TestCase
{
    public function testSignedInPagesMeetTheBaseline(): void
    {
        $s = new Scenario();
        $project = $s->acmeProject->publicId;
        $pages = [
            '/', '/quests', '/quests?chapter=impact', '/quests/foundations.connect-dataforseo', '/quests/impact.did-it-work', '/quests/guide/19', '/projects', '/projects/new', '/projects/' . $project, '/projects/' . $project . '/edit',
            '/projects/' . $project . '/keywords', '/projects/' . $project . '/rankings', '/projects/' . $project . '/competitors',
            '/projects/' . $project . '/pages',
            '/projects/' . $project . '/indexing',
            '/projects/' . $project . '/opportunities/tasks',
            '/projects/' . $project . '/annotations',
            '/projects/' . $project . '/content-briefs', '/projects/' . $project . '/content-briefs/new',
            '/projects/' . $project . '/analytics', '/projects/' . $project . '/local/locations', '/integrations/ga4', '/integrations/web-vitals',
            '/projects/' . $project . '/opportunities', '/projects/' . $project . '/search-console', '/projects/' . $project . '/performance',
            '/integrations', '/integrations/dataforseo', '/integrations/mcp', '/integrations/bing', '/integrations/assistant',
            '/projects/' . $project . '/indexnow', '/projects/' . $project . '/bing',
            '/workspace/members', '/workspace/settings', '/audit', '/account', '/account/security',
        ];
        $this->assertPages($s->loggedIn($s->owner), $pages);

        $root = $s->addUser('root@acme.test', SystemRole::Admin);
        $this->assertPages($s->loggedIn($root), ['/workspaces/new', '/system/email', '/system/users', '/system/jobs', '/system/backups', '/system/updates', '/system/integrations/google']);
    }

    public function testValidationErrorsAreLinkedToTheirFields(): void
    {
        $s = new Scenario();
        $browser = $s->loggedIn($s->owner);
        $project = '/projects/' . $s->acmeProject->publicId;
        $pages = [
            ['/projects/new', '/projects', ['name' => '', 'primary_domain' => 'not a domain', 'timezone' => 'Mars/Base']],
            ['/integrations/mcp', '/integrations/mcp', ['name' => '', 'project_access' => 'selected', 'expires' => '13']],
            [$project . '/competitors', $project . '/competitors', ['domain' => 'not a domain']],
            ['/workspace/members', '/workspace/members', ['email' => 'not an address', 'role' => 'owner']],
            ['/account', '/account/password', ['current_password' => 'wrong', 'new_password' => 'x', 'new_password_confirmation' => 'y']],
        ];
        $all = [];
        foreach ($pages as [$form, $action, $data]) {
            $browser->get($form);
            $response = $browser->post($action, $data);
            $html = (string) $response->getBody();
            self::assertStringContainsString('field-error', $html, $action . ' shows errors (status ' . $response->getStatusCode() . ')');
            foreach (self::problems($html, true) as $problem) {
                $all[] = $action . ': ' . $problem;
            }
        }
        self::assertSame([], $all, implode('
', $all));
    }

    public function testSignInPageMeetsTheBaseline(): void
    {
        $this->assertPages((new Scenario())->browser(), ['/login', '/forgot-password'], signedIn: false);
    }

    public function testInvitationPagesMeetTheBaseline(): void
    {
        $s = new Scenario();
        $owner = $s->loggedIn($s->owner);
        $owner->get('/workspace/members');
        $created = (string) $owner->post('/workspace/members', ['email' => 'guest@example.test', 'role' => 'member'])->getBody();
        self::assertSame(1, preg_match('~(/invite/[A-Za-z0-9_-]{43})~', $created, $m));
        $this->assertPages($s->browser(), [$m[1]], signedIn: false);

        $guest = $s->browser();
        $guest->get($m[1]);
        $html = (string) $guest->post($m[1], ['name' => '', 'password' => 'short', 'password_confirmation' => 'short'])->getBody();
        self::assertStringContainsString('field-error', $html);
        self::assertSame([], self::problems($html, false));

        $invalid = $s->browser()->get('/invite/' . str_repeat('x', 43));
        self::assertSame(404, $invalid->getStatusCode());
        self::assertSame([], self::problems((string) $invalid->getBody(), false));
    }

    /**
     * @param list<string> $paths
     */
    private function assertPages(Browser $browser, array $paths, bool $signedIn = true): void
    {
        $all = [];
        foreach ($paths as $path) {
            $response = $browser->get($path);
            self::assertSame(200, $response->getStatusCode(), $path);
            foreach (self::problems((string) $response->getBody(), $signedIn) as $problem) {
                $all[] = $path . ': ' . $problem;
            }
        }
        self::assertSame([], $all, implode("\n", $all));
    }

    /**
     * @return list<string>
     */
    public static function problems(string $html, bool $signedIn): array
    {
        $doc = HTMLDocument::createFromString($html, LIBXML_NOERROR);
        $problems = [];

        if (($doc->documentElement?->getAttribute('lang') ?? '') === '') {
            $problems[] = '<html> has no lang';
        }
        if (trim($doc->title) === '') {
            $problems[] = 'empty <title>';
        }
        if (count($doc->querySelectorAll('h1')) !== 1) {
            $problems[] = sprintf('%d h1 elements (expected 1)', count($doc->querySelectorAll('h1')));
        }
        $main = $doc->querySelector('main');
        if ($main === null) {
            $problems[] = 'no <main>';
        } elseif ($signedIn && ($main->getAttribute('id') === null || $doc->querySelector('a.skip-link[href="#' . $main->getAttribute('id') . '"]') === null)) {
            $problems[] = 'no skip link to <main>';
        }

        $ids = [];
        foreach ($doc->querySelectorAll('[id]') as $element) {
            $id = (string) $element->getAttribute('id');
            if (isset($ids[$id])) {
                $problems[] = 'duplicate id "' . $id . '"';
            }
            $ids[$id] = true;
        }

        foreach ($doc->querySelectorAll('input, select, textarea') as $control) {
            $type = strtolower((string) $control->getAttribute('type'));
            if (in_array($type, ['hidden', 'submit', 'button'], true)) {
                continue;
            }
            if (!self::hasLabel($doc, $control)) {
                $problems[] = sprintf('<%s name="%s"> has no label', strtolower($control->tagName), (string) $control->getAttribute('name'));
            }
        }

        foreach ($doc->querySelectorAll('button, a[href]') as $element) {
            if (self::accessibleText($element) === '') {
                $problems[] = sprintf('<%s> without text: %s', strtolower($element->tagName), mb_substr($doc->saveHtml($element), 0, 120));
            }
        }

        foreach ($doc->querySelectorAll('table') as $table) {
            if ($table->querySelector('th') === null) {
                $problems[] = 'table without header cells';
            }
            foreach ($table->querySelectorAll('thead th') as $th) {
                if ($th->getAttribute('scope') === null) {
                    $problems[] = 'column header without scope: ' . mb_substr(trim((string) $th->textContent), 0, 40);
                }
            }
        }

        foreach ($doc->querySelectorAll('.field-error') as $message) {
            $id = (string) $message->getAttribute('id');
            if ($id === '' || $doc->querySelector('[aria-describedby~="' . $id . '"]') === null) {
                $problems[] = 'field error not referenced by its control: ' . trim((string) $message->textContent);
            }
        }

        foreach ($doc->querySelectorAll('img') as $img) {
            if ($img->getAttribute('alt') === null) {
                $problems[] = 'img without alt';
            }
        }

        return $problems;
    }

    private static function hasLabel(HTMLDocument $doc, Element $control): bool
    {
        if (trim((string) $control->getAttribute('aria-label')) !== '' || $control->getAttribute('aria-labelledby') !== null) {
            return true;
        }
        $id = $control->getAttribute('id');
        if ($id !== null && $id !== '') {
            foreach ($doc->querySelectorAll('label') as $label) {
                if ($label->getAttribute('for') === $id && trim((string) $label->textContent) !== '') {
                    return true;
                }
            }
        }
        for ($parent = $control->parentElement; $parent !== null; $parent = $parent->parentElement) {
            if (strtolower($parent->tagName) === 'label') {
                return true;
            }
        }

        return trim((string) $control->getAttribute('title')) !== '';
    }

    private static function accessibleText(Element $element): string
    {
        $text = trim((string) $element->getAttribute('aria-label'));
        if ($text === '') {
            $text = trim((string) preg_replace('/\s+/u', ' ', (string) $element->textContent));
        }
        if ($text === '') {
            foreach ($element->querySelectorAll('img[alt]') as $img) {
                $text .= trim((string) $img->getAttribute('alt'));
            }
        }

        return $text;
    }
}
