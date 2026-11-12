<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Doctrine;

use Doctrine\DBAL\Driver;
use Doctrine\DBAL\Driver\Middleware;
use Nowo\TimeTrackBundle\DependencyInjection\Compiler\PrivateEntityManagerPass;

/**
 * DBAL middleware on the connection of the private entity manager (1.23.0): tells {@see TimeTrackEntityManager} when a
 * transaction or a savepoint is rolled back, so a private identity map that may describe rolled-back rows is replaced
 * before the next write (otherwise a second identical save would compute no change and the row would stay lost).
 *
 * Registered only with doctrine/dbal 4 and only on that connection ({@see PrivateEntityManagerPass}).
 *
 * @internal
 */
final readonly class PrivateEntityManagerMiddleware implements Middleware
{
    /**
     * Reports to the private manager.
     */
    public function __construct(
        private TimeTrackEntityManager $entityManager,
    ) {
    }

    /**
     * Wraps the driver of the source manager's connection.
     */
    public function wrap(Driver $driver): Driver
    {
        return new PrivateEntityManagerDriver($driver, $this->entityManager);
    }
}
