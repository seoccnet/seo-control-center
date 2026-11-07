<?php

declare(strict_types=1);

namespace SeoControlCenter\Tests\Unit\Local;

use PHPUnit\Framework\TestCase;
use SeoControlCenter\Domain\Local\LocalArea;
use SeoControlCenter\Domain\Local\LocalResult;
use SeoControlCenter\Domain\Shared\ValidationException;

final class LocalAreaTest extends TestCase
{
    public function testParsesCoordinatesAsGoogleMapsCopiesThem(): void
    {
        $area = LocalArea::fromInput(' 43.85634, 18.41312 ', '14');
        self::assertSame('43.85634,18.41312,14z', $area->coordinate());
        self::assertSame('43.85634,18.41312,5000', $area->radiusCoordinate());
        self::assertSame('43.85634, 18.41312', $area->label());

        self::assertSame('-33.8688,151.2093,13z', LocalArea::fromInput('-33.8688 151.2093', 13)->coordinate());
        self::assertSame('52,13,11z', LocalArea::fromInput('52,13', '11')->coordinate());
    }

    public function testRejectsInvalidInput(): void
    {
        foreach (['', 'Springfield', '91, 10', '45, 181', '45'] as $input) {
            try {
                LocalArea::fromInput($input, '13');
                self::fail('Accepted ' . $input);
            } catch (ValidationException $e) {
                self::assertArrayHasKey('coordinates', $e->errors());
            }
        }
        try {
            LocalArea::fromInput('45, 10', '30');
            self::fail('Accepted zoom 30');
        } catch (ValidationException $e) {
            self::assertSame(['zoom'], array_keys($e->errors()));
        }
    }

    public function testRecognisesTheBusiness(): void
    {
        $result = new LocalResult(2, 'Acme Seeds', 'www.acme.test', cid: '123');
        self::assertTrue($result->isBusiness('123', 'other.test', 'Other'), 'the Google ID decides when both are known');
        self::assertFalse($result->isBusiness('999', 'acme.test', 'Acme Seeds'));
        $noCid = new LocalResult(2, 'Acme Seeds', 'shop.acme.test');
        self::assertTrue($noCid->isBusiness(null, 'acme.test', 'x'), 'subdomain of the project domain');
        self::assertTrue((new LocalResult(3, ' acme seeds '))->isBusiness(null, 'acme.test', 'Acme Seeds'), 'same name');
        self::assertFalse((new LocalResult(3, 'Acme Seeds Outlet'))->isBusiness(null, 'acme.test', 'Acme Seeds'));
    }
}
