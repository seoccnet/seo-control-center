<?php

declare(strict_types=1);

namespace SeoControlCenter\Tests\Unit\DataForSEO;

use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use SeoControlCenter\Core\Support\FrozenClock;
use SeoControlCenter\Domain\Integrations\IntegrationException;
use SeoControlCenter\Domain\Local\LocalArea;
use SeoControlCenter\Modules\DataForSEO\Adapters\DataForSeoReviews;
use SeoControlCenter\Modules\DataForSEO\Api\DataForSeoClient;
use SeoControlCenter\Modules\DataForSEO\DataForSeoCredentials;
use SeoControlCenter\Tests\Support\Fakes\FakeHttpClient;
use SeoControlCenter\Tests\Support\Fakes\InMemoryDataForSeo;

final class DataForSeoReviewsTest extends TestCase
{
    public function testMalformedRowsCannotBecomeAnEmptyOrPartialReviewSample(): void
    {
        $clock = new FrozenClock(new \DateTimeImmutable('2026-10-04'));
        $http = new FakeHttpClient();
        $store = new InMemoryDataForSeo($clock);
        $store->save(3, new DataForSeoCredentials('test@acme.test', 'test'), 10, 1);
        $provider = new DataForSeoReviews(new DataForSeoClient($http, $store, $store, $store, $clock, new NullLogger(), false, 7));
        $id = '00000000-0000-4000-8000-000000000001';
        $url = 'https://api.dataforseo.com/v3/business_data/google/reviews/task_get/' . $id;
        foreach ([['invalid'], [[]], [['review_id' => '']], [['review_id' => 'valid'], null]] as $items) {
            $http->json('GET', $url, ['status_code' => 20000, 'tasks' => [['status_code' => 20000, 'result' => [['items' => $items]]]]]);
            try {
                $provider->result(3, $id);
                self::fail('Malformed reviews must not replace the saved sample.');
            } catch (IntegrationException $e) {
                self::assertTrue($e->retryable, 'Free polling may retry without purchasing another task.');
            }
        }
        self::assertSame([], $store->usage);
        foreach ([null, []] as $items) {
            $http->json('GET', $url, ['status_code' => 20000, 'tasks' => [['status_code' => 20000, 'result' => [['items' => $items]]]]]);
            self::assertSame([], $provider->result(3, $id), 'A valid empty review result remains supported.');
        }
    }

    public function testInvalidReviewRatingsDoNotReplaceSavedMetadata(): void
    {
        $clock = new FrozenClock(new \DateTimeImmutable('2026-10-04'));
        $http = new FakeHttpClient();
        $store = new InMemoryDataForSeo($clock);
        $store->save(3, new DataForSeoCredentials('test@acme.test', 'test'), 10, 1);
        $provider = new DataForSeoReviews(new DataForSeoClient($http, $store, $store, $store, $clock, new NullLogger(), false, 7));
        $id = '00000000-0000-4000-8000-000000000001';
        $url = 'https://api.dataforseo.com/v3/business_data/google/reviews/task_get/' . $id;
        foreach ([-1, 6, false, [], 'invalid', '1e400'] as $rating) {
            $http->json('GET', $url, ['status_code' => 20000, 'tasks' => [['status_code' => 20000, 'result' => [['items' => [['review_id' => 'valid', 'rating' => ['value' => $rating]]]]]]]]);
            try {
                $provider->result(3, $id);
                self::fail('Invalid review ratings must not silently become unavailable.');
            } catch (IntegrationException $e) {
                self::assertTrue($e->retryable, 'Only the free retrieval may be repeated.');
            }
        }
        foreach ([null, 0, 5, '4.5'] as $rating) {
            $http->json('GET', $url, ['status_code' => 20000, 'tasks' => [['status_code' => 20000, 'result' => [['items' => [['review_id' => 'valid', 'rating' => ['value' => $rating]]]]]]]]);
            $rows = $provider->result(3, $id);
            self::assertNotNull($rows);
            self::assertSame($rating === null ? null : (float) $rating, $rows[0]['rating']);
        }
        self::assertSame([], $store->usage);
    }

    public function testOnlySubmissionIsPaidAndFreePollingNormalizesOriginalReviewText(): void
    {
        $clock = new FrozenClock(new \DateTimeImmutable('2026-10-04'));
        $http = new FakeHttpClient();
        $store = new InMemoryDataForSeo($clock);
        $store->save(3, new DataForSeoCredentials('test@acme.test', 'test'), 10, 1);
        $provider = new DataForSeoReviews(new DataForSeoClient($http, $store, $store, $store, $clock, new NullLogger(), false, 7));
        $id = '00000000-0000-4000-8000-000000000001';
        $http->json('POST', 'https://api.dataforseo.com/v3/business_data/google/reviews/task_post', ['status_code' => 20000, 'cost' => 0.0075, 'tasks' => [['status_code' => 20100, 'id' => $id]]]);
        self::assertSame($id, $provider->submit(3, 7, '222', new LocalArea(1, 1), 'en'));
        self::assertSame('222', json_decode((string) $http->requests[0]['body'], true)[0]['cid']);
        self::assertEqualsWithDelta(0.0075, $store->usage[1]['estimated'], 0.000001);
        $url = 'https://api.dataforseo.com/v3/business_data/google/reviews/task_get/' . $id;
        $http->json('GET', $url, ['status_code' => 20000, 'tasks' => [['status_code' => 40602]]]);
        self::assertNull($provider->result(3, $id));
        $review = ['review_id' => 'r', 'profile_name' => 'Customer', 'review_text' => 'Translated', 'original_review_text' => 'Original', 'rating' => ['value' => 5], 'timestamp' => '2026-10-04 00:00:00 +00:00', 'owner_answer' => 'Thanks'];
        $http->json('GET', $url, ['status_code' => 20000, 'tasks' => [['status_code' => 20000, 'result' => [['items' => [$review]]]]]]);
        $rows = $provider->result(3, $id);
        self::assertNotNull($rows);
        self::assertSame('Original', $rows[0]['text']);
        self::assertSame('Thanks', $rows[0]['reply']);
        self::assertCount(1, $store->usage);
    }
}
