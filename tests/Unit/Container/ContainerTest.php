<?php

declare(strict_types=1);

namespace SeoControlCenter\Tests\Unit\Container;

use PHPUnit\Framework\TestCase;
use Psr\Container\NotFoundExceptionInterface;
use SeoControlCenter\Core\Container\Container;
use SeoControlCenter\Core\Container\ContainerException;
use SeoControlCenter\Core\Container\NotFoundException;
use stdClass;

final class ContainerTest extends TestCase
{
    public function testSingletonReturnsSameInstance(): void
    {
        $c = new Container();
        $c->singleton('svc', static fn(): stdClass => new stdClass());

        self::assertSame($c->get('svc'), $c->get('svc'));
    }

    public function testFactoryReturnsNewInstances(): void
    {
        $c = new Container();
        $c->factory('svc', static fn(): stdClass => new stdClass());

        self::assertNotSame($c->get('svc'), $c->get('svc'));
    }

    public function testUnknownServiceThrowsNotFound(): void
    {
        $this->expectException(NotFoundExceptionInterface::class);
        (new Container())->get('missing');
    }

    public function testMissingDependencyIsContainerErrorNotNotFound(): void
    {
        $c = new Container();
        $c->singleton('a', static fn(Container $c): mixed => $c->get('missing'));

        try {
            $c->get('a');
            self::fail('Expected exception');
        } catch (ContainerException $e) {
            self::assertNotInstanceOf(NotFoundException::class, $e);
        }
    }

    public function testCircularDependencyIsDetected(): void
    {
        $c = new Container();
        $c->singleton('a', static fn(Container $c): mixed => $c->get('b'));
        $c->singleton('b', static fn(Container $c): mixed => $c->get('a'));

        $this->expectException(ContainerException::class);
        $this->expectExceptionMessage('Circular dependency');
        $c->get('a');
    }

    public function testDuplicateRegistrationIsRejected(): void
    {
        $c = new Container();
        $c->singleton('a', static fn(): int => 1);

        $this->expectException(ContainerException::class);
        $c->singleton('a', static fn(): int => 2);
    }

    public function testInstanceOverridesFactory(): void
    {
        $c = new Container();
        $c->singleton('a', static fn(): int => 1);
        $c->instance('a', 2);

        self::assertSame(2, $c->get('a'));
    }

    public function testMakeAssertsType(): void
    {
        $c = new Container();
        $c->instance(stdClass::class, new \ArrayObject());

        $this->expectException(ContainerException::class);
        $c->make(stdClass::class);
    }
}
