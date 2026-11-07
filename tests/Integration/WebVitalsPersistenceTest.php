<?php

declare(strict_types=1);

namespace SeoControlCenter\Tests\Integration;

use SeoControlCenter\Domain\WebVitals\WebVitalRecord;
use SeoControlCenter\Modules\WebVitals\WebVitalsRepository;

final class WebVitalsPersistenceTest extends FullStackTestCase
{
    public function testKeysAreEncryptedAndRecordsStayScopedToTheirProject(): void
    {
        $owner = $this->createUser('vitals@acme.test', 'Vitals');
        self::assertNotNull($owner['workspace']);
        $publicId = $this->createProject($this->browserFor('vitals@acme.test'), 'Vitals', 'vitals.test');
        $id = (int) $this->db->fetchValue('SELECT id FROM projects WHERE public_id = ?', [$publicId]);
        $data = $this->app->make(WebVitalsRepository::class);
        $key = str_repeat('K', 39);
        $data->saveKey($owner['workspace']->id, $key);
        self::assertNotSame($key, $this->db->fetchValue('SELECT api_key FROM crux_settings WHERE workspace_id = ?', [$owner['workspace']->id]));
        self::assertSame($key, $data->apiKey($owner['workspace']->id));
        self::assertNull($data->apiKey($owner['workspace']->id + 100));
        $record = new WebVitalRecord('https://vitals.test/Page?a=1', 3200, 220, 0.12, '2026-09-01', '2026-09-28', '2026-10-01 12:00:00');
        $data->save($id, $record);
        $data->save($id, $record);
        self::assertCount(1, $data->list($id));
        self::assertEquals($record, $data->find($id, $record->url));
        self::assertCount(1, $data->history($id, $record->url));
        $desktop = new WebVitalRecord($record->url, 1700, 90, 0.01, '2026-09-01', '2026-09-28', '2026-10-02 12:00:00', formFactor: 'DESKTOP');
        $data->save($id, $desktop);
        self::assertEquals($desktop, $data->find($id, $record->url, 'DESKTOP'));
        self::assertEquals($record, $data->find($id, $record->url));
        self::assertCount(1, $data->list($id, formFactor: 'DESKTOP'));
        $data->watch($id, $record->url, 'DESKTOP', true);
        self::assertTrue($data->watching($id, $record->url, 'DESKTOP'));
        self::assertCount(1, $data->due('2026-10-01 00:00:00'));
        $data->attempted($id, $record->url, 'DESKTOP', '2026-10-07 12:00:00');
        self::assertSame([], $data->due('2026-10-01 00:00:00'));
        $data->watch($id, $record->url, 'DESKTOP', false);
        self::assertSame(0, $data->watchCount($id));
        self::assertNull($data->find($id + 100, $record->url));
        self::assertNull($data->find($id, 'https://vitals.test/page?a=1'));
        $data->deleteKey($owner['workspace']->id);
        self::assertFalse($data->hasKey($owner['workspace']->id));
        self::assertEquals($record, $data->find($id, $record->url), 'Disconnect keeps imported field data.');
        for ($day = 1; $day <= 54; ++$day) {
            $date = (new \DateTimeImmutable('2026-08-01 UTC'))->modify('+' . $day . ' days')->format('Y-m-d H:i:s');
            $data->save($id, new WebVitalRecord($record->url, 1800, 90, 0.02, null, null, $date, formFactor: 'DESKTOP'));
        }
        self::assertCount(52, $data->history($id, $record->url, 'DESKTOP'));
        self::assertSame([], $data->history($id + 100, $record->url, 'DESKTOP'));
        for ($watch = 1; $watch <= 50; ++$watch) {
            $data->watch($id, 'https://vitals.test/' . $watch, 'PHONE', true);
        }
        try {
            $data->watch($id, 'https://vitals.test/over-limit', 'PHONE', true);
            self::fail('The persisted watch limit must be enforced.');
        } catch (\SeoControlCenter\Core\Http\Exception\HttpException $e) {
            self::assertSame(50, $data->watchCount($id));
        }
    }
}
