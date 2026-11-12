<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Doctrine;

use Doctrine\DBAL\Driver;
use Doctrine\DBAL\Driver\Connection;
use Doctrine\DBAL\Driver\Middleware\AbstractDriverMiddleware;

/**
 * Wraps the driver connections of the private entity manager's connection ({@see PrivateEntityManagerMiddleware}).
 *
 * @internal
 */
final class PrivateEntityManagerDriver extends AbstractDriverMiddleware
{
    /**
     * Wraps the driver.
     */
    public function __construct(
        Driver $driver,
        private readonly TimeTrackEntityManager $entityManager,
    ) {
        parent::__construct($driver);
    }

    /**
     * Connects and wraps the driver connection.
     */
    public function connect(array $params): Connection
    {
        return new PrivateEntityManagerConnection(parent::connect($params), $this->entityManager);
    }
}
