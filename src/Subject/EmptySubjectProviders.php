<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Subject;

use LogicException;
use Psr\Container\ContainerInterface;
use Psr\Container\NotFoundExceptionInterface;

/**
 * Since 2.1.0: the provider locator of a registry with no subject provider. Stateless.
 *
 * @internal
 */
final readonly class EmptySubjectProviders implements ContainerInterface
{
    /**
     * Never: there is no provider.
     *
     * @throws NotFoundExceptionInterface always
     */
    public function get(string $id): never
    {
        throw new class('No TimeTrack subject provider is registered.') extends LogicException implements NotFoundExceptionInterface {};
    }

    /**
     * Always false.
     */
    public function has(string $id): bool
    {
        return false;
    }
}
