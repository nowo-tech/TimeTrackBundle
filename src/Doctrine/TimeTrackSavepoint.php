<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Doctrine;

use Closure;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\AbstractQuery;
use Doctrine\ORM\EntityManagerInterface;
use Throwable;

use function bin2hex;
use function random_bytes;

/**
 * Runs one TimeTrack DBAL / bulk DQL write inside a savepoint when a transaction is already open on the connection
 * (1.23.0, private entity manager only, REQ-ARCH-001 rule 6): a failing TimeTrack statement then rolls back to the
 * savepoint only, so it never leaves the application's transaction aborted (PostgreSQL) and the application's own COMMIT keeps its rows
 * (audit NC2-1 class). Outside a transaction, on a platform without savepoints, or when the statement goes through the
 * application's manager (default mode), the write runs exactly as before.
 *
 * @internal
 */
final class TimeTrackSavepoint
{
    /**
     * @template T
     *
     * @param Closure(): T $write
     * @param object|null $manager the manager the statement belongs to: only the private manager gets a savepoint, and it
     *                             records the transaction level the write now waits in (audit NJV-1)
     *
     * @return T
     */
    public static function write(Connection $connection, Closure $write, ?object $manager = null): mixed
    {
        // Default mode (the application's manager): exactly the 1.22 statement, no savepoint.
        if (!$manager instanceof TimeTrackEntityManager || !$connection->isTransactionActive()) {
            return $write();
        }

        $result = self::inSavepoint($connection, $write);
        $manager->noteWrite();

        return $result;
    }

    /**
     * One bulk DQL statement of a TimeTrack repository through {@see self::write()} (a savepoint around it only for the
     * private manager inside a transaction); returns what `execute()` returns.
     */
    public static function execute(AbstractQuery $query, EntityManagerInterface $manager): mixed
    {
        return self::write($manager->getConnection(), static fn (): mixed => $query->execute(), $manager);
    }

    /**
     * @template T
     *
     * @param Closure(): T $write
     *
     * @return T
     */
    private static function inSavepoint(Connection $connection, Closure $write): mixed
    {
        $platform = $connection->getDatabasePlatform();
        if (!$platform->supportsSavepoints()) {
            return $write(); // @codeCoverageIgnore
        }

        $name = 'nowo_time_track_' . bin2hex(random_bytes(6));
        $connection->createSavepoint($name);
        try {
            $result = $write();
        } catch (Throwable $exception) {
            try {
                $connection->rollbackSavepoint($name);
            } catch (Throwable) {
                // The original failure is what the caller must see (the rollback failure is a consequence of it, e.g. a
                // dropped connection); the outer transaction is then the host's to roll back.
            }

            throw $exception;
        }
        if ($platform->supportsReleaseSavepoints()) {
            $connection->releaseSavepoint($name);
        }

        return $result;
    }
}
