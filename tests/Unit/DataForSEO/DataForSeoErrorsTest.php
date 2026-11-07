<?php

declare(strict_types=1);

namespace SeoControlCenter\Tests\Unit\DataForSEO;

use PHPUnit\Framework\TestCase;
use SeoControlCenter\Core\Http\Client\HttpResponse;
use SeoControlCenter\Domain\Integrations\IntegrationException;
use SeoControlCenter\Modules\DataForSEO\Api\DataForSeoErrors;

/**
 * Provider failures become readable messages. A failed HTTP response can
 * carry the success text "Ok." at the top level; it is not shown as the reason.
 */
final class DataForSeoErrorsTest extends TestCase
{
    /** @param array<string, mixed> $body */
    private static function message(int $status, array $body): string
    {
        try {
            DataForSeoErrors::check(new HttpResponse($status, (string) json_encode($body)));
        } catch (IntegrationException $e) {
            return $e->getMessage();
        }
        self::fail('Expected an error.');
    }

    public function testMeaninglessOkIsReplacedByTheHttpStatus(): void
    {
        self::assertSame('DataForSEO refused the request for billing reasons (check the account balance): HTTP 402', self::message(402, ['status_code' => 20000, 'status_message' => 'Ok.']));
        self::assertSame('DataForSEO refused the request for billing reasons (check the account balance): Payment Required.', self::message(402, ['status_code' => 40200, 'status_message' => 'Payment Required.']));
        self::assertSame('DataForSEO is temporarily unavailable: HTTP 503', self::message(503, ['status_code' => 20000, 'status_message' => 'ok']));
    }

    /** @param array<string, mixed> $body */
    private static function code(int $status, array $body): ?int
    {
        try {
            DataForSeoErrors::check(new HttpResponse($status, (string) json_encode($body)));
        } catch (IntegrationException $e) {
            return $e->providerStatusCode;
        }
        self::fail('Expected an error.');
    }

    public function testHttpRefusalsCarryARefusalCodeSoNothingIsTreatedAsPossiblyBilled(): void
    {
        // Seen live: HTTP 402 with a top-level "20000 Ok.". The old code 20000 left resumable
        // lookups marked "uncertain outcome", which blocked them with 409 indefinitely.
        self::assertSame(40200, self::code(402, ['status_code' => 20000, 'status_message' => 'Ok.']));
        self::assertSame(40210, self::code(402, ['status_code' => 20000, 'tasks' => [['status_code' => 40210]]]));
        self::assertSame(40100, self::code(401, ['status_code' => 20000]));
        self::assertSame(40104, self::code(401, ['status_code' => 40104]));
        self::assertSame(50000, self::code(500, ['status_code' => 50000]));
    }
}
