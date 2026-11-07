<?php

declare(strict_types=1);

namespace SeoControlCenter\Core\Container;

use Closure;
use Psr\Container\ContainerInterface;
use Throwable;

/**
 * Small PSR-11 container with explicit factory registration (ADR-016).
 *
 * No reflection autowiring: every service is registered by a module or the
 * bootstrap with a factory closure that receives the container.
 */
final class Container implements ContainerInterface
{
    /** @var array<string, Closure(Container): mixed> */
    private array $factories = [];

    /** @var array<string, bool> */
    private array $shared = [];

    /** @var array<string, mixed> */
    private array $instances = [];

    /** @var array<string, true> */
    private array $resolving = [];

    /**
     * Registers a shared service (created once, then reused).
     *
     * @param Closure(Container): mixed $factory
     */
    public function singleton(string $id, Closure $factory): void
    {
        $this->register($id, $factory, true);
    }

    /**
     * Registers a factory that creates a new instance on every get().
     *
     * @param Closure(Container): mixed $factory
     */
    public function factory(string $id, Closure $factory): void
    {
        $this->register($id, $factory, false);
    }

    /**
     * Registers an already-built instance.
     */
    public function instance(string $id, mixed $value): void
    {
        unset($this->factories[$id], $this->shared[$id]);
        $this->instances[$id] = $value;
    }

    public function has(string $id): bool
    {
        return array_key_exists($id, $this->instances) || isset($this->factories[$id]);
    }

    public function get(string $id): mixed
    {
        if (array_key_exists($id, $this->instances)) {
            return $this->instances[$id];
        }
        if (!isset($this->factories[$id])) {
            throw new NotFoundException(sprintf('Service "%s" is not registered.', $id));
        }
        if (isset($this->resolving[$id])) {
            throw new ContainerException(sprintf(
                'Circular dependency while resolving "%s" (%s).',
                $id,
                implode(' -> ', array_keys($this->resolving)),
            ));
        }

        $this->resolving[$id] = true;
        try {
            $value = ($this->factories[$id])($this);
        } catch (NotFoundException $e) {
            // PSR-11: a missing *dependency* is a container error, not "not found" for $id.
            throw new ContainerException(sprintf('Service "%s" depends on a missing service.', $id), 0, $e);
        } catch (ContainerException $e) {
            throw $e;
        } catch (Throwable $e) {
            throw new ContainerException(sprintf('Failed to create service "%s".', $id), 0, $e);
        } finally {
            unset($this->resolving[$id]);
        }

        if ($this->shared[$id]) {
            $this->instances[$id] = $value;
        }

        return $value;
    }

    /**
     * Typed accessor: returns the service and asserts its class.
     *
     * @template T of object
     * @param class-string<T> $class
     * @return T
     */
    public function make(string $class, ?string $id = null): object
    {
        $service = $this->get($id ?? $class);
        if (!$service instanceof $class) {
            throw new ContainerException(sprintf('Service "%s" is not an instance of %s.', $id ?? $class, $class));
        }

        return $service;
    }

    /**
     * @param Closure(Container): mixed $factory
     */
    private function register(string $id, Closure $factory, bool $shared): void
    {
        if (array_key_exists($id, $this->instances) || isset($this->factories[$id])) {
            throw new ContainerException(sprintf('Service "%s" is already registered.', $id));
        }
        $this->factories[$id] = $factory;
        $this->shared[$id] = $shared;
    }
}
