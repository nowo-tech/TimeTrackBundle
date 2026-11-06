<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Tests\Functional;

use Nowo\TimeTrackBundle\Repository\DoctrineOrmTimeEntryRepository;
use Nowo\TimeTrackBundle\Tests\Stub\TestUser;
use Nowo\TimeTrackBundle\Tests\Support\RecordingLogger;
use stdClass;

/**
 * Since the delta GG-D-2 fix: the per-user write lock also holds for a user the entity manager does not manage (locked
 * by id through the mapped user class); when no row can be locked, a warning says the write is not serialised.
 */
final class EntryLockDatabaseTest extends FunctionalTestCase
{
    public function testUsersAreLockedByIdWhenNotManaged(): void
    {
        $logger = new RecordingLogger();
        $repo   = new DoctrineOrmTimeEntryRepository($this->entityManager(), null, $logger);

        self::assertSame('managed', $repo->inUserTransaction($this->user, static fn (): string => 'managed'));
        $this->entityManager()->detach($this->user);
        self::assertSame('by id', $repo->inUserTransaction($this->user, static fn (): string => 'by id'));
        self::assertSame([], $logger->records);

        self::assertSame('unknown', $repo->inUserTransaction(new TestUser('999999', 'ghost@example.com'), static fn (): string => 'unknown'));
        self::assertSame('other', $repo->inUserTransaction(new stdClass(), static fn (): string => 'other'));
        self::assertSame(['TimeTrack could not lock the user row; this write is not serialised.', 'TimeTrack could not lock the user row; this write is not serialised.'], $logger->messages());
    }
}
