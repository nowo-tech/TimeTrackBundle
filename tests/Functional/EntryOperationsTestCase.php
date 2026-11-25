<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Tests\Functional;

use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Nowo\TimeTrackBundle\Client\ClientSessionRevokerInterface;
use Nowo\TimeTrackBundle\Doctrine\TimeTrackEntityManager;
use Nowo\TimeTrackBundle\Entity\ClientToken;
use Nowo\TimeTrackBundle\Entity\TimeEntry;
use Nowo\TimeTrackBundle\Entry\ManualTimeEntryDraft;
use Nowo\TimeTrackBundle\Entry\TimeEntryCorrection;
use Nowo\TimeTrackBundle\Entry\TimeEntryCorrectionResult;
use Nowo\TimeTrackBundle\Entry\TimeEntryOperationsInterface;
use Nowo\TimeTrackBundle\Enum\ClientSessionRevocation;
use Nowo\TimeTrackBundle\Enum\ClientType;
use Nowo\TimeTrackBundle\Enum\TimeEntrySource;
use Nowo\TimeTrackBundle\Exception\ManualEntryException;
use Nowo\TimeTrackBundle\Exception\TimeEntryCorrectionException;
use Nowo\TimeTrackBundle\Port\Internal\UserReferenceResolverInterface;
use Nowo\TimeTrackBundle\Query\TimeEntryQueryInterface;
use Nowo\TimeTrackBundle\Service\ClientAuthService;
use Nowo\TimeTrackBundle\Tests\Fixtures\FunctionalEntity\AppUser;
use Nowo\TimeTrackBundle\ValueObject\TimeTrackUserRef;
use Symfony\Component\Security\Core\User\UserInterface;

use function array_keys;

/**
 * 1.40.0 (Beacon): the id-based operations through the container — {@see TimeEntryOperationsInterface} and
 * {@see ClientSessionRevokerInterface} — on a standard host ({@see EntryOperationsStandardTest}: relation mapping,
 * integer ids, the shared or the private manager as the suite runs) and on a DDD host ({@see EntryOperationsDddHostTest}:
 * id mapping, XML mapping, private manager). Reads go through {@see TimeEntryQueryInterface}: the host never loads a
 * bundle entity in its own manager.
 */
abstract class EntryOperationsTestCase extends FunctionalTestCase
{
    /**
     * A host user of the variant.
     *
     * @param non-empty-string $name
     */
    abstract protected function person(string $name): UserInterface;

    /**
     * The user id the ports take for that user.
     */
    abstract protected function idOf(UserInterface $user): string;

    /**
     * An id that names no user of the variant.
     */
    abstract protected function unknownId(): string;

    public function testTheOwnerCorrectsAnEntryReadThroughTheQueryPort(): void
    {
        $ana   = $this->person('ana');
        $id    = $this->entry($ana, '-3 days 09:00', 3600, ['note' => 'before']);
        $read  = $this->query()->find($id) ?? self::fail('the entry is readable by id');
        $start = $read->entry->startedAt->modify('-30 minutes');

        $result = $this->operations()->correct($this->idOf($ana), $id, $read->version(), TimeEntryCorrection::times($start, $read->entry->endedAt)->withNote('after'));

        self::assertSame(['started_at', 'duration_seconds', 'metadata'], $result->changedFields);
        self::assertSame($read->version(), $result->before->version(), 'the old values are those that were read');
        $stored = $this->query()->find($id) ?? self::fail();
        self::assertSame([5400, 'after', $start->getTimestamp()], [$stored->entry->durationSeconds, $stored->note, $stored->entry->startedAt->getTimestamp()]);
        self::assertSame($result->after->version(), $stored->version(), 'the version of the stored row is the one returned');

        // The version read before the correction is stale now: refused, never a lost update.
        $this->refused(TimeEntryCorrectionException::CONFLICT, fn (): TimeEntryCorrectionResult => $this->operations()->correct($this->idOf($ana), $id, $read->version(), new TimeEntryCorrection(durationSeconds: 60)));
        // Another user, a look-alike id: one answer.
        $bob = $this->person('bob');
        $this->refused(TimeEntryCorrectionException::NOT_FOUND, fn (): TimeEntryCorrectionResult => $this->operations()->correct($this->idOf($bob), $id, $stored->version(), new TimeEntryCorrection(durationSeconds: 60)));
        $this->refused(TimeEntryCorrectionException::NOT_FOUND, fn (): TimeEntryCorrectionResult => $this->operations()->correct('0' . $this->idOf($ana), $id, $stored->version(), new TimeEntryCorrection(durationSeconds: 60)));
        $this->assertNoBundleEntityInTheHostManager();
    }

    /**
     * The correction is a savepoint of a transaction the host opened on the same connection: the host's rollback undoes
     * it, the host's commit keeps it (GDPR art. 16 correction and the host's own revision row commit together).
     */
    public function testACorrectionJoinsTheTransactionTheHostOpened(): void
    {
        $ana        = $this->person('ana');
        $id         = $this->entry($ana, '-2 days 09:00', 3600);
        $connection = $this->entityManager()->getConnection();

        $connection->beginTransaction();
        try {
            $this->operations()->correct($this->idOf($ana), $id, $this->version($id), new TimeEntryCorrection(durationSeconds: 600));
            self::assertSame(600, $this->durationOf($id), 'visible inside the host transaction');
        } finally {
            $connection->rollBack(); // @phpstan-ignore frankenphp.worker.noRollbackAfterNestedBegin (the test plays the host's transaction)
        }
        self::assertSame(3600, $this->durationOf($id), 'the host rollback undid it');

        $connection->beginTransaction();
        $this->operations()->correct($this->idOf($ana), $id, $this->version($id), new TimeEntryCorrection(durationSeconds: 900));
        $connection->commit();
        self::assertSame(900, $this->durationOf($id));
        self::assertFalse($connection->isTransactionActive());
    }

    public function testAManualEntryForAUserGivenByIdOnly(): void
    {
        $ana    = $this->person('ana');
        $start  = (new DateTimeImmutable('today -1 day'))->setTime(14, 0);
        $record = $this->operations()->createManual($this->idOf($ana), new ManualTimeEntryDraft('task-1', $start, $start->modify('+45 minutes'), 'store review'));

        self::assertSame([$this->idOf($ana), 'task-1', 2700, 'store review', TimeEntrySource::Manual], [$record->entry->userId, $record->entry->taskId, $record->entry->durationSeconds, $record->note, $record->entry->source]);
        self::assertSame($record->version(), $this->query()->find($record->entry->id)?->version());

        foreach ([$this->unknownId(), '', ' ' . $this->idOf($ana)] as $owner) {
            try {
                $this->operations()->createManual($owner, new ManualTimeEntryDraft('task-1', $start->modify('+2 hours'), $start->modify('+3 hours')));
                self::fail('created for ' . $owner);
            } catch (ManualEntryException $exception) {
                self::assertSame(ManualEntryException::UNKNOWN_USER, $exception->errorCode);
            }
        }
        $this->assertNoBundleEntityInTheHostManager();
    }

    public function testASessionIsRevokedByIdsWithoutTheHostManager(): void
    {
        $ana  = $this->person('ana');
        $auth = self::getContainer()->get(ClientAuthService::class);
        self::assertInstanceOf(ClientAuthService::class, $auth);
        $auth->issueToken($ana, ClientType::Desktop, 'app-password-1');
        $sessionId = (string) $this->bundleManager()->getConnection()->fetchOne('SELECT id FROM time_track_client_tokens');
        $revoker   = self::getContainer()->get(ClientSessionRevokerInterface::class);
        self::assertInstanceOf(ClientSessionRevokerInterface::class, $revoker);

        self::assertSame(ClientSessionRevocation::NotFound, $revoker->revokeSession($sessionId, $this->idOf($this->person('bob'))));
        self::assertSame(ClientSessionRevocation::Revoked, $revoker->revokeSession($sessionId, $this->idOf($ana)));
        self::assertSame(ClientSessionRevocation::NotFound, $revoker->revokeSession($sessionId, $this->idOf($ana)), 'idempotent');
        self::assertSame(0, (int) $this->bundleManager()->getConnection()->fetchOne('SELECT COUNT(*) FROM time_track_client_tokens'));
        $this->assertNoBundleEntityInTheHostManager(ClientToken::class);
    }

    /**
     * 1.40.1 (audit ZAN-2): the default allowed age of a new start is `manual_entries.max_age_days` (31 by default).
     */
    public function testANewStartOlderThanTheConfiguredAgeIsRefused(): void
    {
        $ana  = $this->person('ana');
        $id   = $this->entry($ana, '-3 days 09:00', 3600);
        $back = new DateTimeImmutable('today -40 days 09:00');

        $this->refused(TimeEntryCorrectionException::TOO_OLD, fn (): TimeEntryCorrectionResult => $this->operations()->correct($this->idOf($ana), $id, $this->version($id), TimeEntryCorrection::times($back, $back->modify('+1 hour'))));
        self::assertTrue($this->operations()->correct($this->idOf($ana), $id, $this->version($id), new TimeEntryCorrection($back, $back->modify('+1 hour'), maxAgeDays: 60))->changed);
    }

    /**
     * 1.40.1 (audit ZAN-5): a reference of a class that is not a user class of the bundle revokes nothing.
     */
    public function testASessionIsNotRevokedForAnotherPrincipalClass(): void
    {
        $ana  = $this->person('ana');
        $auth = self::getContainer()->get(ClientAuthService::class);
        self::assertInstanceOf(ClientAuthService::class, $auth);
        $auth->issueToken($ana, ClientType::Desktop, 'app-password-1');
        $sessionId = (string) $this->bundleManager()->getConnection()->fetchOne('SELECT id FROM time_track_client_tokens');
        $revoker   = self::getContainer()->get(ClientSessionRevokerInterface::class);
        self::assertInstanceOf(ClientSessionRevokerInterface::class, $revoker);

        self::assertSame(ClientSessionRevocation::NotFound, $revoker->revokeSession($sessionId, TimeTrackUserRef::ofId('App\\ApiClient', $this->idOf($ana))));
        self::assertTrue($revoker->revokeSession($sessionId, TimeTrackUserRef::ofId(AppUser::class, $this->idOf($ana)))->isRevoked(), 'user_class or an accepted class');
    }

    protected function operations(): TimeEntryOperationsInterface
    {
        $operations = self::getContainer()->get(TimeEntryOperationsInterface::class);
        self::assertInstanceOf(TimeEntryOperationsInterface::class, $operations);

        return $operations;
    }

    protected function query(): TimeEntryQueryInterface
    {
        $query = self::getContainer()->get(TimeEntryQueryInterface::class);
        self::assertInstanceOf(TimeEntryQueryInterface::class, $query);

        return $query;
    }

    protected function durationOf(string $entryId): int
    {
        return ($this->query()->find($entryId) ?? self::fail('no entry ' . $entryId))->entry->durationSeconds;
    }

    protected function version(string $entryId): string
    {
        return ($this->query()->find($entryId) ?? self::fail('no entry ' . $entryId))->version();
    }

    /**
     * A closed live entry of the user in scope `org-a` (start relative to today); returns its id.
     *
     * @param array<string, mixed>|null $metadata
     */
    protected function entry(UserInterface $user, string $start, int $seconds, ?array $metadata = null): string
    {
        $resolver = self::getContainer()->get(UserReferenceResolverInterface::class);
        self::assertInstanceOf(UserReferenceResolverInterface::class, $resolver);
        $startedAt = new DateTimeImmutable('today ' . $start);
        $entry     = new TimeEntry($resolver->reference($user), 'task-1', 'Task task-1', 'board-1', $startedAt, $startedAt->modify('+' . $seconds . ' seconds'), $seconds, TimeEntrySource::Web, $metadata);
        $entry->assignScopeKey('org-a');
        $this->bundleManager()->persist($entry);
        $this->bundleManager()->flush();
        $this->bundleManager()->detach($entry);

        return $entry->getId();
    }

    protected function refused(string $code, callable $call): void
    {
        try {
            $call();
            self::fail('not refused: ' . $code);
        } catch (TimeEntryCorrectionException $exception) {
            self::assertSame($code, $exception->errorCode);
        }
    }

    protected function bundleManager(): EntityManagerInterface
    {
        $container = self::getContainer();
        $manager   = $container->has(TimeTrackEntityManager::class) ? $container->get(TimeTrackEntityManager::class) : $this->entityManager();
        self::assertInstanceOf(EntityManagerInterface::class, $manager);

        return $manager;
    }

    /**
     * With the private manager, no bundle row enters the host's manager.
     *
     * @param class-string $class
     */
    private function assertNoBundleEntityInTheHostManager(string $class = TimeEntry::class): void
    {
        if (self::getContainer()->has(TimeTrackEntityManager::class)) {
            self::assertNotContains($class, array_keys($this->entityManager()->getUnitOfWork()->getIdentityMap()));
        }
    }
}
