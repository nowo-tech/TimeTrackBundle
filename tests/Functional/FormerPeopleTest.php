<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Tests\Functional;

use DateTimeImmutable;
use Doctrine\DBAL\Exception as DbalException;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\ORM\Event\OnFlushEventArgs;
use Doctrine\ORM\Event\PostFlushEventArgs;
use LogicException;
use Nowo\TimeTrackBundle\Client\ClientUserProfileProviderInterface;
use Nowo\TimeTrackBundle\Doctrine\FormerPersonSnapshotListener;
use Nowo\TimeTrackBundle\Entity\ActiveTimer;
use Nowo\TimeTrackBundle\Entity\TimeEntry;
use Nowo\TimeTrackBundle\Enum\ClientType;
use Nowo\TimeTrackBundle\Enum\TimeEntrySource;
use Nowo\TimeTrackBundle\Event\TimeEntriesRemovedEvent;
use Nowo\TimeTrackBundle\Event\TimeEntryListQueryEvent;
use Nowo\TimeTrackBundle\Event\TimeTrackEvents;
use Nowo\TimeTrackBundle\Import\ImportedEntryOwnerMismatchException;
use Nowo\TimeTrackBundle\Import\ImportedTimeEntryClaim;
use Nowo\TimeTrackBundle\Import\ImportedTimeEntryData;
use Nowo\TimeTrackBundle\Import\ImportedTimeEntryOutcome;
use Nowo\TimeTrackBundle\Import\ImportedTimeEntryPerson;
use Nowo\TimeTrackBundle\Import\ImportedTimeEntryWriter;
use Nowo\TimeTrackBundle\Import\InvalidImportedTimeEntryException;
use Nowo\TimeTrackBundle\Repository\DoctrineOrmTimeEntryRepository;
use Nowo\TimeTrackBundle\Repository\TimeEntryRepositoryInterface;
use Nowo\TimeTrackBundle\Retention\FormerPersonTimeEntries;
use Nowo\TimeTrackBundle\Service\TimerService;
use Nowo\TimeTrackBundle\Tests\Fixtures\FunctionalEntity\AppUser;
use Nowo\TimeTrackBundle\Tests\Support\InMemoryTimeEntryRepository;
use Nowo\TimeTrackBundle\Tests\Support\RacingImportedTimeEntryRepository;
use Nowo\TimeTrackBundle\Tests\Support\RecordingLogger;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

use function array_column;
use function array_map;
use function count;
use function max;
use function sprintf;
use function str_repeat;

/**
 * Since 1.15: entries with no user. Owner rule 2026-11-06: tracked time is not lost, including when a user is deleted.
 * Runs on SQLite, MySQL and PostgreSQL (`TIME_TRACK_TEST_DATABASE_URL`).
 */
final class FormerPeopleTest extends FunctionalTestCase
{
    private const SOURCE = 'clickup:dir-1';

    public function testAPersonWithNoUserKeepsHoursUnderTheirName(): void
    {
        $result = $this->writer()->upsertForPerson(new ImportedTimeEntryPerson('p-1', 'Ana Ruiz', 'org-a'), $this->data('e-1'));
        self::assertSame(ImportedTimeEntryOutcome::Created, $result->outcome);

        $entry = $this->reload('e-1');
        self::assertNull($entry->getUser());
        self::assertTrue($entry->isOwnerless());
        self::assertSame('Ana Ruiz', $entry->getPersonName());
        self::assertSame('p-1', $entry->getPersonRef());
        self::assertSame('org-a', $entry->getScopeKey());
        self::assertSame(TimeEntrySource::Import, $entry->getSource());
        self::assertTrue($entry->isReadOnly());
        self::assertNull($entry->getFormerUserId());
        self::assertFalse($entry->isAnonymised());
        self::assertSame(['name' => 'Ana Ruiz', 'anonymised' => false], $entry->toArray()['person'] ?? null);
        self::assertArrayNotHasKey('personRef', $entry->toArray());
    }

    public function testAnEntryWithAUserHasNoPersonKey(): void
    {
        $this->writer()->upsert($this->user, $this->data('e-1'));
        $entry = $this->reload('e-1');

        self::assertFalse($entry->isOwnerless());
        self::assertNull($entry->getPersonName());
        self::assertArrayNotHasKey('person', $entry->toArray());
    }

    public function testASecondUpsertIsIdempotentAndFollowsARename(): void
    {
        $writer = $this->writer();
        $person = new ImportedTimeEntryPerson('p-1', 'Ana Ruiz');
        $writer->upsertForPerson($person, $this->data('e-1'));
        $this->entityManager()->clear();

        self::assertSame(ImportedTimeEntryOutcome::Unchanged, $writer->upsertForPerson($person, $this->data('e-1'))->outcome);
        self::assertSame(ImportedTimeEntryOutcome::Updated, $writer->upsertForPerson(new ImportedTimeEntryPerson('p-1', 'Ana R. Ruiz'), $this->data('e-1'))->outcome);
        self::assertSame('Ana R. Ruiz', $this->reload('e-1')->getPersonName());
        self::assertSame(ImportedTimeEntryOutcome::Updated, $writer->upsertForPerson(new ImportedTimeEntryPerson('p-1', 'Ana R. Ruiz'), $this->data('e-1', seconds: 1800))->outcome);
        self::assertSame(1800, $this->reload('e-1')->getDurationSeconds());
    }

    public function testABatchMixesUsersAndPeople(): void
    {
        $batch = $this->writer()->upsertMany([
            [$this->user, $this->data('e-1')],
            [new ImportedTimeEntryPerson('p-1', 'Ana'), $this->data('e-2')],
        ]);

        self::assertSame(2, $batch->created);
        self::assertSame($this->user->getId(), $this->userIdOf($this->reload('e-1')));
        self::assertNull($this->reload('e-2')->getUser());
    }

    /**
     * @return iterable<string, array{0: ImportedTimeEntryPerson, 1: string, 2: string}>
     */
    public static function providerBadPeople(): iterable
    {
        yield 'empty reference' => [new ImportedTimeEntryPerson(''), 'person.ref', 'pattern'];
        yield 'reference with a space' => [new ImportedTimeEntryPerson('a b'), 'person.ref', 'pattern'];
        yield 'reference too long' => [new ImportedTimeEntryPerson(str_repeat('a', 65)), 'person.ref', 'pattern'];
        yield 'reserved user reference' => [new ImportedTimeEntryPerson('user:42'), 'person.ref', 'pattern'];
        yield 'name not UTF-8' => [new ImportedTimeEntryPerson('p-1', "\xFF"), 'person.name', 'encoding'];
        yield 'scope too long' => [new ImportedTimeEntryPerson('p-1', 'Ana', str_repeat('s', 65)), 'person.scopeKey', 'length'];
    }

    #[DataProvider('providerBadPeople')]
    public function testABadPersonIsRefusedAndNothingIsWritten(ImportedTimeEntryPerson $person, string $field, string $rule): void
    {
        try {
            $this->writer()->upsertForPerson($person, $this->data('e-1'));
            self::fail('expected a refusal');
        } catch (InvalidImportedTimeEntryException $exception) {
            self::assertSame([$field, $rule], [$exception->field, $exception->rule]);
        }
        self::assertNull($this->repository()->findByExternal(self::SOURCE, 'e-1'));
    }

    public function testTheNameIsCleanedToOneLineAndCut(): void
    {
        $writer = $this->writer();
        $writer->upsertForPerson(new ImportedTimeEntryPerson('p-1', "\u{202E}Ana\r\nRuiz "), $this->data('e-1'));
        $writer->upsertForPerson(new ImportedTimeEntryPerson('p-2', str_repeat('ñ', 300)), $this->data('e-2'));
        $writer->upsertForPerson(new ImportedTimeEntryPerson('p-3', "  \u{200B} "), $this->data('e-3'));

        self::assertSame('Ana Ruiz', $this->reload('e-1')->getPersonName());
        self::assertSame(str_repeat('ñ', 255), $this->reload('e-2')->getPersonName());
        self::assertNull($this->reload('e-3')->getPersonName());
    }

    public function testDeletingAUserThroughTheEntityManagerKeepsEveryEntry(): void
    {
        $this->writer()->upsert($this->user, $this->data('e-1'));
        $live = new TimeEntry($this->user, 'task-2', 'Live task', 'board-1', new DateTimeImmutable('2026-01-02 10:00:00'), new DateTimeImmutable('2026-01-02 11:00:00'), 3600, TimeEntrySource::Web);
        $this->entityManager()->persist($live);
        $this->entityManager()->flush();
        $liveId = $live->getId();
        $userId = (string) $this->user->getId();

        $this->enforceForeignKeys();
        $this->entityManager()->remove($this->user);
        $this->entityManager()->flush();
        $this->entityManager()->clear();

        $imported = $this->reload('e-1');
        self::assertNull($imported->getUser());
        self::assertSame('user:' . $userId, $imported->getPersonRef());
        self::assertSame($userId, $imported->getFormerUserId());
        $reloaded = $this->entityManager()->find(TimeEntry::class, $liveId);
        self::assertInstanceOf(TimeEntry::class, $reloaded);
        self::assertNull($reloaded->getUser());
        self::assertSame(3600, $reloaded->getDurationSeconds());
        self::assertFalse($reloaded->isReadOnly());
    }

    public function testASqlDeleteOfTheUserKeepsTheEntriesThroughTheForeignKey(): void
    {
        $this->writer()->upsert($this->user, $this->data('e-1'));
        $this->enforceForeignKeys();
        $this->entityManager()->getConnection()->executeStatement('DELETE FROM app_user WHERE id = ?', [$this->user->getId()]);
        $this->entityManager()->clear();

        $entry = $this->reload('e-1');
        self::assertNull($entry->getUser());
        self::assertNull($entry->getPersonName());
        self::assertNull($entry->getPersonRef());
        self::assertSame(['name' => null, 'anonymised' => false], $entry->toArray()['person'] ?? null);
    }

    public function testTheListenerIgnoresOtherEntitiesAndSnapshotsTheConfiguredUser(): void
    {
        self::assertInstanceOf(FormerPersonSnapshotListener::class, self::getContainer()->get(FormerPersonSnapshotListener::class));
        $this->writer()->upsert($this->user, $this->data('e-1'));
        $entry = $this->reload('e-1');

        // Removing an entry is not removing a user: nothing is snapshotted on the others.
        $this->writer()->upsert($this->user, $this->data('e-2'));
        $this->entityManager()->remove($entry);
        $this->entityManager()->flush();
        $this->entityManager()->clear();

        self::assertNull($this->reload('e-2')->getPersonRef());
    }

    public function testReleaseKeepsTheEntriesUnderTheName(): void
    {
        $this->writer()->upsert($this->user, $this->data('e-1'));
        $userId = (string) $this->user->getId();

        self::assertSame(1, $this->people()->release($this->user, "Ana\nRuiz"));
        $this->entityManager()->clear();

        $entry = $this->reload('e-1');
        self::assertNull($entry->getUser());
        self::assertSame('Ana Ruiz', $entry->getPersonName());
        self::assertSame($userId, $entry->getFormerUserId());
        self::assertCount(1, $this->people()->export(FormerPersonTimeEntries::personRefOf($this->user)));
        // The user can be deleted afterwards, and the name stays.
        $user = $this->entityManager()->find(AppUser::class, (int) $userId);
        self::assertInstanceOf(AppUser::class, $user);
        $this->entityManager()->remove($user);
        $this->entityManager()->flush();
        $this->entityManager()->clear();
        self::assertSame('Ana Ruiz', $this->reload('e-1')->getPersonName());
    }

    public function testAnonymisingAUserClearsThePersonAndKeepsTheTime(): void
    {
        $this->writer()->upsert($this->user, $this->data('e-1', description: 'Client call with Ana'));
        $live = new TimeEntry($this->user, 'task-2', 'Live task', null, new DateTimeImmutable('2026-01-02 10:00:00'), new DateTimeImmutable('2026-01-02 11:00:00'), 3600, TimeEntrySource::Web, ['note' => 'Ana was sick']);
        $this->entityManager()->persist($live);
        $this->entityManager()->flush();
        $liveId = $live->getId();

        self::assertSame(2, $this->people()->anonymiseUser($this->user));
        $this->entityManager()->clear();

        $entry = $this->reload('e-1');
        self::assertNull($entry->getUser());
        self::assertNull($entry->getPersonName());
        self::assertNull($entry->getPersonRef());
        self::assertNull($entry->getDescription());
        self::assertTrue($entry->isAnonymised());
        self::assertInstanceOf(DateTimeImmutable::class, $entry->getAnonymisedAt());
        self::assertSame(3600, $entry->getDurationSeconds());
        self::assertSame('', $entry->getTaskTitleSnapshot(), 'a task title can name the person');
        self::assertSame('task-1', $entry->getTaskId(), 'totals still count');
        self::assertSame(TimeEntry::anonymisedExternalId(self::SOURCE, 'e-1'), $entry->getExternalId());
        self::assertSame(['name' => null, 'anonymised' => true], $entry->toArray()['person'] ?? null);
        $reloaded = $this->entityManager()->find(TimeEntry::class, $liveId);
        self::assertInstanceOf(TimeEntry::class, $reloaded);
        self::assertNull($reloaded->getMetadata());
    }

    public function testAnonymisingAPersonClearsTheNameAndOnlyThatPerson(): void
    {
        $writer = $this->writer();
        $writer->upsertForPerson(new ImportedTimeEntryPerson('p-1', 'Ana'), $this->data('e-1'));
        $writer->upsertForPerson(new ImportedTimeEntryPerson('p-2', 'Luis'), $this->data('e-2'));
        $writer->upsert($this->user, $this->data('e-3'));

        self::assertCount(1, $this->people()->export('p-1'));
        self::assertSame('Ana', $this->people()->export('p-1')[0]['personName']);
        self::assertSame(1, $this->people()->anonymisePerson('p-1'));
        $this->entityManager()->clear();

        self::assertNull($this->reload('e-1')->getPersonName());
        self::assertSame(3600, $this->reload('e-1')->getDurationSeconds());
        self::assertSame('Luis', $this->reload('e-2')->getPersonName());
        self::assertSame($this->user->getId(), $this->userIdOf($this->reload('e-3')));
        self::assertSame([], $this->people()->export('p-1'));
        self::assertSame(0, $this->people()->anonymisePerson('p-1'));
    }

    public function testAReSyncNeverBringsAnAnonymisedPersonBack(): void
    {
        $writer = $this->writer();
        $person = new ImportedTimeEntryPerson('p-1', 'Ana');
        $writer->upsertForPerson($person, $this->data('e-1', description: 'Secret'));
        $this->people()->anonymisePerson('p-1');
        $this->entityManager()->clear();

        self::assertSame(ImportedTimeEntryOutcome::Unchanged, $writer->upsertForPerson($person, $this->data('e-1', description: 'Secret'))->outcome);
        self::assertSame(ImportedTimeEntryOutcome::Unchanged, $writer->upsert($this->managedUser(), $this->data('e-1', seconds: 60))->outcome, 'neither a user can claim it');
        $this->entityManager()->clear();

        $entry = $this->reload('e-1');
        self::assertNull($entry->getUser());
        self::assertNull($entry->getPersonName());
        self::assertNull($entry->getDescription());
        self::assertSame(3600, $entry->getDurationSeconds());
    }

    public function testAClaimNamingTheReferenceTakesAFormerPersonsEntry(): void
    {
        $writer = $this->writer();
        $writer->upsertForPerson(new ImportedTimeEntryPerson('p-1', 'Ana'), $this->data('e-1'));
        $this->entityManager()->clear();
        $claim = new ImportedTimeEntryClaim($this->managedUser(), 'p-1');

        self::assertSame(ImportedTimeEntryOutcome::Updated, $writer->upsert($claim, $this->data('e-1'))->outcome);
        $entry = $this->reload('e-1');
        self::assertSame($this->user->getId(), $this->userIdOf($entry));
        self::assertNull($entry->getPersonName());
        self::assertNull($entry->getPersonRef());
        self::assertSame(ImportedTimeEntryOutcome::Unchanged, $writer->upsert($claim, $this->data('e-1'))->outcome, 'on its own entry a claim is a plain user row');
        self::assertSame(ImportedTimeEntryOutcome::Created, $writer->upsert($claim, $this->data('e-2'))->outcome);
        self::assertSame($this->user->getId(), $this->userIdOf($this->reload('e-2')));
    }

    public function testAClaimFromAnotherTenantOrForAnotherPersonIsRefused(): void
    {
        $writer = $this->writer();
        $writer->upsertForPerson(new ImportedTimeEntryPerson('p-1', 'Ana', 'org-a'), $this->data('e-1'));
        $this->entityManager()->clear();
        $user = $this->managedUser();

        $this->assertMismatch(fn (): mixed => $writer->upsert($user, $this->data('e-1', seconds: 60)), 'a plain user row never takes it');
        $this->assertMismatch(fn (): mixed => $writer->upsert(new ImportedTimeEntryClaim($user, 'p-2', 'org-a'), $this->data('e-1')), 'another reference');
        $this->assertMismatch(fn (): mixed => $writer->upsert(new ImportedTimeEntryClaim($user, 'p-1', 'org-b'), $this->data('e-1')), 'another tenant');
        $this->assertMismatch(fn (): mixed => $writer->upsert(new ImportedTimeEntryClaim($user, 'p-1'), $this->data('e-1')), 'no tenant stated');
        $this->assertMismatch(fn (): mixed => $writer->upsert(new ImportedTimeEntryClaim($user, 'P-1', 'org-a'), $this->data('e-1')), 'references are case-sensitive');
        $this->assertMismatch(fn (): mixed => $writer->upsertForPerson(new ImportedTimeEntryPerson('p-1', 'Ana', 'org-b'), $this->data('e-1')), 'the same person in another tenant');
        $this->assertMismatch(fn (): mixed => $writer->upsertForPerson(new ImportedTimeEntryPerson('p-1', 'Ana'), $this->data('e-1')), 'the same person with no tenant');
        $this->entityManager()->clear();
        self::assertSame(3600, $this->reload('e-1')->getDurationSeconds());

        self::assertSame(ImportedTimeEntryOutcome::Updated, $writer->upsert(new ImportedTimeEntryClaim($this->managedUser(), 'p-1', 'org-a'), $this->data('e-1'))->outcome);
        self::assertSame('org-a', $this->reload('e-1')->getScopeKey());
    }

    public function testAClaimSurvivesTheRetryAfterAConcurrentInsert(): void
    {
        $writer = $this->writer();
        $writer->upsertForPerson(new ImportedTimeEntryPerson('p-1', 'Ana'), $this->data('e-1'));
        $this->entityManager()->clear();

        $racing = new RacingImportedTimeEntryRepository($this->repository());
        $racing->staleReads(2);
        $result = (new ImportedTimeEntryWriter($racing))->upsert(new ImportedTimeEntryClaim($this->managedUser(), 'p-1'), $this->data('e-1'));

        self::assertSame(ImportedTimeEntryOutcome::Updated, $result->outcome);
        self::assertSame(2, $racing->saveAllCalls);
        $this->entityManager()->clear();
        self::assertSame($this->user->getId(), $this->userIdOf($this->reload('e-1')));
    }

    public function testAClaimWithAScopeIsRefusedOnARowWithNone(): void
    {
        $writer = $this->writer();
        $writer->upsertForPerson(new ImportedTimeEntryPerson('p-1', 'Ana'), $this->data('e-1'));
        $this->entityManager()->clear();
        $user = $this->managedUser();

        $this->assertMismatch(fn (): mixed => $writer->upsert(new ImportedTimeEntryClaim($user, 'p-1', 'org-b'), $this->data('e-1')), 'a claim cannot cross tenants through a row with no scope');
        self::assertTrue($this->reload('e-1')->isOwnerless());
        self::assertSame(ImportedTimeEntryOutcome::Updated, $writer->upsert(new ImportedTimeEntryClaim($user, 'p-1'), $this->data('e-1'))->outcome, 'no scope on either side');
    }

    public function testAMismatchNamesTheRowThatStoppedTheBatch(): void
    {
        $writer = $this->writer();
        $writer->upsertForPerson(new ImportedTimeEntryPerson('p-1', 'Ana', 'org-a'), $this->data('e-1'));
        $writer->upsert($this->user, $this->data('e-2'));
        $other = $this->createUser('other@example.com');
        $this->entityManager()->clear();
        $rows = [
            [$this->managedUser(), $this->data('e-new')],
            [$this->managedUser(), $this->data('e-1')],
        ];

        foreach ([
            ['e-1', static fn (): mixed => $writer->upsertMany($rows)],
            ['e-1', fn (): mixed => $writer->upsertForPerson(new ImportedTimeEntryPerson('p-2', 'Luis', 'org-a'), $this->data('e-1'))],
            ['e-2', fn (): mixed => $writer->upsertForPerson(new ImportedTimeEntryPerson('p-2', 'Luis'), $this->data('e-2'))],
        ] as [$externalId, $call]) {
            try {
                $call();
                self::fail('expected a mismatch');
            } catch (ImportedEntryOwnerMismatchException $exception) {
                self::assertSame($externalId, $exception->externalId);
                self::assertStringNotContainsString($externalId, $exception->getMessage(), 'the message names no entry');
            }
        }
        self::assertNull($this->repository()->findByExternal(self::SOURCE, 'e-new'), 'nothing of the batch was written');
        self::assertNotNull($other->getId());
    }

    public function testAnEntryNeverMovesBetweenOwners(): void
    {
        $writer = $this->writer();
        $writer->upsert($this->user, $this->data('owned'));
        $writer->upsertForPerson(new ImportedTimeEntryPerson('p-1', 'Ana'), $this->data('person'));
        $writer->upsert($this->createUser('leaver@example.com'), $this->data('released'));

        $this->assertMismatch(fn (): mixed => $writer->upsertForPerson(new ImportedTimeEntryPerson('p-1', 'Ana'), $this->data('owned')), 'a person cannot take a user\'s entry');
        $this->assertMismatch(fn (): mixed => $writer->upsertForPerson(new ImportedTimeEntryPerson('p-2', 'Luis'), $this->data('person')), 'another person cannot take it');

        $leaver = $this->reload('released')->getUser();
        self::assertInstanceOf(AppUser::class, $leaver);
        $this->people()->release($leaver, 'Leaver');
        $this->entityManager()->clear();
        $this->assertMismatch(fn (): mixed => $writer->upsert($this->managedUser(), $this->data('released')), 'another user cannot take a deleted user\'s entry');
    }

    public function testAPersonRowNeverRewritesADeletedUsersHours(): void
    {
        $writer = $this->writer();
        $writer->upsert($this->user, $this->data('e-1'));
        $this->people()->release($this->user, 'Ana');
        $this->entityManager()->clear();
        $person = new ImportedTimeEntryPerson('p-evil', 'Other');

        self::assertSame(ImportedTimeEntryOutcome::Unchanged, $writer->upsertForPerson($person, $this->data('e-1', seconds: 60))->outcome);
        $this->entityManager()->clear();
        $entry = $this->reload('e-1');
        self::assertSame(3600, $entry->getDurationSeconds());
        self::assertSame('Ana', $entry->getPersonName());
        self::assertSame('user:' . $this->user->getId(), $entry->getPersonRef());
    }

    public function testOnlyAClaimBringsAReleasedUserBack(): void
    {
        $writer = $this->writer();
        $writer->upsert($this->user, $this->data('e-1'));
        $this->people()->release($this->user, 'Ana');
        $this->entityManager()->clear();
        $user = $this->managedUser();

        $this->assertMismatch(fn (): mixed => $writer->upsert($user, $this->data('e-1')), 'a re-created user with the same id takes nothing');
        self::assertSame(ImportedTimeEntryOutcome::Updated, $writer->upsert(new ImportedTimeEntryClaim($user, FormerPersonTimeEntries::personRefOf($user)), $this->data('e-1'))->outcome);
        self::assertSame($user->getId(), $this->userIdOf($this->reload('e-1')));
    }

    public function testAnEntryThatLostItsUserInSqlIsFrozenForASync(): void
    {
        $writer = $this->writer();
        $conn   = $this->entityManager()->getConnection();
        $other  = $this->createUser('other@example.com');
        $writer->upsert($other, $this->data('e-1'));
        $conn->executeStatement('UPDATE time_track_entries SET user_id = NULL');
        $this->entityManager()->clear();

        self::assertSame(ImportedTimeEntryOutcome::Unchanged, $writer->upsertForPerson(new ImportedTimeEntryPerson('p-1', 'Ana'), $this->data('e-1', seconds: 60))->outcome);
        self::assertSame(ImportedTimeEntryOutcome::Unchanged, $writer->upsert($this->managedUser(), $this->data('e-1', seconds: 60))->outcome);
        $this->entityManager()->clear();
        $entry = $this->reload('e-1');
        self::assertNull($entry->getPersonName());
        self::assertSame(3600, $entry->getDurationSeconds());
    }

    public function testACaseVariantOfTheUserPrefixIsRefused(): void
    {
        $this->expectExceptionObject(new InvalidImportedTimeEntryException('person.ref', 'pattern'));
        $this->writer()->upsertForPerson(new ImportedTimeEntryPerson('USER:1'), $this->data('e-1'));
    }

    public function testReferencesThatDifferOnlyByCaseStayApartOnEveryCollation(): void
    {
        $writer = $this->writer();
        $writer->upsertForPerson(new ImportedTimeEntryPerson('ana-1', 'Ana'), $this->data('e-1'));
        $writer->upsertForPerson(new ImportedTimeEntryPerson('ANA-1', 'Other'), $this->data('e-2'));

        self::assertSame(['Ana'], array_column($this->people()->export('ana-1'), 'personName'));
        self::assertSame(1, $this->people()->anonymisePerson('ana-1'));
        $this->entityManager()->clear();
        self::assertSame('Other', $this->reload('e-2')->getPersonName());
    }

    public function testAnEmailOrInvisibleCharactersNeverBecomeAName(): void
    {
        foreach (['ana@example.com', 'Ana <ana@example.com>', 'mailto:ana@example.com', 'ana@localhost', 'añá@example.com', 'a@example.com, b@example.com', 'ana＠example.com'] as $address) {
            self::assertNull(ImportedTimeEntryPerson::cleanName($address), $address);
        }
        self::assertSame('Ana', ImportedTimeEntryPerson::cleanName("A\u{2060}n\u{00AD}a\u{200D}"));
        self::assertNull(ImportedTimeEntryPerson::cleanName(null));
    }

    public function testAnonymiseUserReachesEntriesAlreadyReleased(): void
    {
        $this->writer()->upsert($this->user, $this->data('e-1', description: 'Secret'));
        $this->people()->release($this->user, 'Ana');
        $this->entityManager()->clear();

        self::assertSame(1, $this->people()->anonymiseUser($this->user));
        $this->entityManager()->clear();
        $entry = $this->reload('e-1');
        self::assertNull($entry->getPersonName());
        self::assertNull($entry->getPersonRef());
        self::assertNull($entry->getDescription());
        self::assertTrue($entry->isAnonymised());
    }

    public function testAnErasureThenADeleteWithATimerDoesNotBringThePersonBack(): void
    {
        $timers = $this->service();
        $timers->start($this->user, 'task-1', ClientType::Web, ['note' => 'In progress for Ana']);
        $this->writer()->upsert($this->user, $this->data('e-1'));

        self::assertSame(2, $this->people()->anonymiseUser($this->user));
        self::assertNull($timers->getActive($this->user));
        $this->enforceForeignKeys();
        $this->entityManager()->remove($this->user);
        $this->entityManager()->flush();
        $this->entityManager()->clear();

        $entries = $this->entityManager()->getRepository(TimeEntry::class)->findAll();
        self::assertCount(2, $entries);
        foreach ($entries as $entry) {
            self::assertTrue($entry->isAnonymised());
            self::assertNull($entry->getPersonName());
            self::assertNull($entry->getPersonRef());
            self::assertSame(3600, max(3600, $entry->getDurationSeconds()));
            self::assertArrayNotHasKey('note', $entry->getMetadata() ?? []);
        }
    }

    public function testTheClosedByMarkOutlivesAnonymising(): void
    {
        $this->service()->start($this->user, 'task-1', ClientType::Web, ['note' => 'Secret']);
        $this->people()->anonymiseUser($this->user);
        $this->entityManager()->clear();

        $entry = $this->entityManager()->getRepository(TimeEntry::class)->findOneBy(['taskId' => 'task-1']);
        self::assertInstanceOf(TimeEntry::class, $entry);
        self::assertTrue($entry->isClosedByUserDeletion());
        self::assertSame(['closed_by' => TimeEntry::CLOSED_BY_USER_DELETION], $entry->getMetadata());
    }

    public function testACallerCannotForgeTheClosedByMark(): void
    {
        $this->service()->start($this->user, 'task-1', ClientType::Web, ['closed_by' => TimeEntry::CLOSED_BY_USER_DELETION]);
        $entry = $this->service()->stop($this->user);

        self::assertInstanceOf(TimeEntry::class, $entry);
        self::assertFalse($entry->isClosedByUserDeletion());
    }

    public function testEntriesLeftByARawDeleteCanBeSweptAndOldNamesExpired(): void
    {
        $writer = $this->writer();
        $writer->upsert($this->createUser('raw@example.com'), $this->data('raw', description: 'Secret'));
        $this->entityManager()->getConnection()->executeStatement('UPDATE time_track_entries SET user_id = NULL');
        $writer->upsertForPerson(new ImportedTimeEntryPerson('p-1', 'Ana'), $this->data('old'));
        $writer->upsertForPerson(new ImportedTimeEntryPerson('p-2', 'Luis'), $this->data('new', start: new DateTimeImmutable('2026-03-01 10:00:00')));
        $this->entityManager()->clear();

        self::assertSame(1, $this->people()->anonymiseUnidentified());
        self::assertSame(1, $this->people()->anonymiseOwnerlessEndedBefore(new DateTimeImmutable('2026-02-01')));
        $this->entityManager()->clear();
        self::assertNull($this->reload('raw')->getDescription());
        self::assertNull($this->reload('old')->getPersonName());
        self::assertSame('Luis', $this->reload('new')->getPersonName());
    }

    public function testADeleteKeepsThePendingWorkOfLoadedEntriesAndNamesNewOnes(): void
    {
        $this->writer()->upsert($this->user, $this->data('loaded'));
        $loaded = $this->reload('loaded');
        $loaded->assignScopeKey('org-pending');
        $inserted = new TimeEntry($this->user, 'task-2', 'Inserted', null, new DateTimeImmutable('2026-01-02 10:00:00'), new DateTimeImmutable('2026-01-02 11:00:00'), 3600, TimeEntrySource::Web);
        $this->entityManager()->persist($inserted);
        $insertedId = $inserted->getId();
        $userId     = (string) $this->user->getId();

        $this->enforceForeignKeys();
        $this->entityManager()->remove($this->user);
        $this->entityManager()->flush();
        self::assertNull($loaded->getUser(), 'no reference to a removed user stays in memory');
        $this->entityManager()->flush();
        $this->entityManager()->clear();

        self::assertSame('org-pending', $this->reload('loaded')->getScopeKey());
        self::assertSame('user:' . $userId, $this->reload('loaded')->getPersonRef());
        $reloaded = $this->entityManager()->find(TimeEntry::class, $insertedId);
        self::assertInstanceOf(TimeEntry::class, $reloaded);
        self::assertSame('user:' . $userId, $reloaded->getPersonRef());
    }

    public function testAFailedDeleteLeavesNoSnapshotOnALivingUsersRows(): void
    {
        $this->writer()->upsert($this->user, $this->data('e-1', description: 'Kept'));
        $this->entityManager()->clear();
        $conn = $this->entityManager()->getConnection();
        $this->enforceForeignKeys();
        $conn->executeStatement('CREATE TABLE block_user_delete (user_id INT NOT NULL, CONSTRAINT fk_block_user_delete FOREIGN KEY (user_id) REFERENCES app_user (id))');
        $conn->executeStatement('INSERT INTO block_user_delete (user_id) VALUES (?)', [$this->user->getId()]);
        $people = $this->people();
        self::assertTrue($people->isAvailable());

        try {
            $this->entityManager()->remove($this->managedUser());
            $this->entityManager()->flush();
            self::fail('the delete should fail');
        } catch (DbalException) {
            $row = $conn->fetchAssociative('SELECT user_id, person_ref, person_name_snapshot, anonymised_at, description FROM time_track_entries');
            self::assertIsArray($row);
            self::assertSame((string) $this->user->getId(), (string) $row['user_id']);
            self::assertNull($row['person_ref'], 'no stale reference');
            self::assertNull($row['person_name_snapshot'], 'no stale name');
            self::assertNull($row['anonymised_at']);
            self::assertSame('Kept', $row['description']);
        } finally {
            $conn->executeStatement('DROP TABLE block_user_delete');
        }
    }

    public function testReleasingAUserWithNoEntriesChangesNothing(): void
    {
        self::assertSame(0, $this->people()->release($this->user, 'Ana'));
    }

    public function testResetForgetsAFailedFlush(): void
    {
        $this->writer()->upsert($this->user, $this->data('e-1'));
        $listener = self::getContainer()->get(FormerPersonSnapshotListener::class);
        self::assertInstanceOf(FormerPersonSnapshotListener::class, $listener);
        $this->entityManager()->getUnitOfWork()->scheduleForDelete($this->user);
        $listener->onFlush(new OnFlushEventArgs($this->entityManager()));
        $listener->reset();
        $listener->postFlush(new PostFlushEventArgs($this->entityManager()));
        $this->entityManager()->clear();

        self::assertNull($this->reload('e-1')->getPersonRef(), 'what a failed flush prepared is never written');
    }

    public function testRemoveWithNoFlushWritesNoSnapshot(): void
    {
        $this->writer()->upsert($this->user, $this->data('e-1'));

        $this->entityManager()->remove($this->user);
        $this->entityManager()->clear();

        self::assertNull($this->reload('e-1')->getPersonRef());
    }

    public function testTheProfileProviderGivesTheNameAndAFailureCostsOnlyTheName(): void
    {
        $this->writer()->upsert($this->user, $this->data('e-1'));
        $profiles = $this->createStub(ClientUserProfileProviderInterface::class);
        $profiles->method('getDisplayName')->willReturn('Ana Ruiz');
        $people = new FormerPersonTimeEntries($this->repository(), $profiles);
        self::assertSame('Ana Ruiz', $people->nameBeforeDelete($this->user));

        $failing = $this->createStub(ClientUserProfileProviderInterface::class);
        $failing->method('getDisplayName')->willThrowException(new RuntimeException('down'));
        $logger = new RecordingLogger();
        self::assertNull((new FormerPersonTimeEntries($this->repository(), $failing, logger: $logger))->nameBeforeDelete($this->user));
        self::assertCount(1, $logger->records);
    }

    public function testTheClientApiNeverListsOwnerlessEntries(): void
    {
        $this->writer()->upsertForPerson(new ImportedTimeEntryPerson('p-1', 'Ana'), $this->data('e-1'));
        $this->dispatcher()->addListener(TimeTrackEvents::TIME_ENTRY_LIST_QUERY, static function (TimeEntryListQueryEvent $event): void {
            $event->includeOwnerless(null);
        });

        self::assertSame(0, $this->service()->listEntriesPage($this->user, ...[...$this->period(), 1, 10, null, false])->total);
        self::assertSame([], $this->service()->listEntries($this->user, ...[...$this->period(), null, false]));
        self::assertSame(1, $this->service()->listEntriesPage($this->user, ...[...$this->period(), 1, 10])->total);
    }

    public function testBulkRemovalsHandleOwnerlessEntriesAndReportOnlyRealOwners(): void
    {
        $writer = $this->writer();
        $writer->upsert($this->user, $this->data('e-1'));
        $writer->upsertForPerson(new ImportedTimeEntryPerson('p-1', 'Ana'), $this->data('e-2'));

        self::assertSame(2, $writer->removeForSource(self::SOURCE));
        self::assertNull($this->repository()->findByExternal(self::SOURCE, 'e-2'));
    }

    public function testRemoveForUserNeverTouchesAFormerPerson(): void
    {
        $writer = $this->writer();
        $writer->upsert($this->user, $this->data('e-1'));
        $writer->upsertForPerson(new ImportedTimeEntryPerson('p-1', 'Ana'), $this->data('e-2'));

        self::assertSame(1, $writer->removeForUser(self::SOURCE, $this->user));
        self::assertNotNull($this->repository()->findByExternal(self::SOURCE, 'e-2'));
    }

    public function testARunningTimerIsSavedWhenTheUserIsDeleted(): void
    {
        $timers = self::getContainer()->get(TimerService::class);
        self::assertInstanceOf(TimerService::class, $timers);
        $started = $timers->start($this->user, 'task-1', ClientType::Desktop, ['note' => 'In progress']);
        $userId  = (string) $this->user->getId();
        $timerId = $started->getId();

        $this->enforceForeignKeys();
        $this->entityManager()->remove($this->user);
        $this->entityManager()->flush();
        $this->entityManager()->clear();

        $entries = $this->entityManager()->getRepository(TimeEntry::class)->findBy(['taskId' => 'task-1']);
        self::assertCount(1, $entries);
        $entry = $entries[0];
        self::assertNull($entry->getUser());
        self::assertTrue($entry->isClosedByUserDeletion());
        self::assertSame('In progress', ($entry->getMetadata() ?? [])['note'] ?? null);
        self::assertSame(TimeEntrySource::Desktop, $entry->getSource());
        self::assertSame('user:' . $userId, $entry->getPersonRef());
        self::assertSame($userId, $entry->getFormerUserId());
        self::assertNull($this->entityManager()->find(ActiveTimer::class, $timerId));
        self::assertTrue($this->people()->export('user:' . $userId)[0]['closedByUserDeletion']);
    }

    public function testAStoppedTimerIsNotMarkedAndNoTimerMeansNoEntry(): void
    {
        $timers = self::getContainer()->get(TimerService::class);
        self::assertInstanceOf(TimerService::class, $timers);
        self::assertNull($timers->stopForUserDeletion($this->user));
        $timers->start($this->user, 'task-1', ClientType::Web);
        $entry = $timers->stop($this->user);

        self::assertInstanceOf(TimeEntry::class, $entry);
        self::assertFalse($entry->isClosedByUserDeletion());
    }

    public function testATimerThatOverlapsAnEntryStillClosesAndNobodysChecksSeeIt(): void
    {
        $timers = self::getContainer()->get(TimerService::class);
        self::assertInstanceOf(TimerService::class, $timers);
        $repository = $this->repository();
        $timers->start($this->user, 'task-1', ClientType::Web);
        $now = new DateTimeImmutable();
        $this->entityManager()->persist(new TimeEntry($this->user, 'task-2', 'Overlapping', null, $now->modify('-1 hour'), $now->modify('+1 hour'), 7200, TimeEntrySource::Manual));
        $this->entityManager()->flush();
        $other = $this->createUser('other@example.com');
        $id    = (string) $this->user->getId();

        $this->enforceForeignKeys();
        $this->entityManager()->remove($this->user);
        $this->entityManager()->flush();
        $this->entityManager()->clear();

        self::assertCount(2, $this->entityManager()->getRepository(TimeEntry::class)->findAll());
        self::assertSame([], $repository->findLiveOverlapping((string) $other->getId(), $now->modify('-2 hours'), $now->modify('+2 hours')));
        self::assertSame([], $repository->findLiveOverlapping($id, $now->modify('-2 hours'), $now->modify('+2 hours')));
    }

    public function testARawDeleteLosesTheTimerUnlessTheHostStopsItFirst(): void
    {
        $timers = self::getContainer()->get(TimerService::class);
        self::assertInstanceOf(TimerService::class, $timers);
        $careless = $this->createUser('careless@example.com');
        $timers->start($careless, 'task-1', ClientType::Web);
        $timers->start($this->user, 'task-2', ClientType::Web);
        $conn = $this->entityManager()->getConnection();
        $this->enforceForeignKeys();

        $conn->executeStatement('DELETE FROM app_user WHERE id = ?', [$careless->getId()]);
        self::assertSame(0, (int) $conn->fetchOne('SELECT COUNT(*) FROM time_track_entries'), 'documented: the foreign key takes the timer');
        self::assertSame(1, (int) $conn->fetchOne('SELECT COUNT(*) FROM time_track_active_timers'));

        $timers->stopForUserDeletion($this->user);
        $this->people()->release($this->user, 'Ana');
        $conn->executeStatement('DELETE FROM app_user WHERE id = ?', [$this->user->getId()]);
        $this->entityManager()->clear();
        $entry = $this->entityManager()->getRepository(TimeEntry::class)->findOneBy(['taskId' => 'task-2']);
        self::assertInstanceOf(TimeEntry::class, $entry);
        self::assertSame('Ana', $entry->getPersonName());
        self::assertTrue($entry->isClosedByUserDeletion());
    }

    public function testRemoveForUserCarriesTheActor(): void
    {
        $actors     = [];
        $dispatcher = new EventDispatcher();
        $dispatcher->addListener(TimeTrackEvents::TIME_ENTRIES_REMOVED, static function (TimeEntriesRemovedEvent $event) use (&$actors): void {
            $actors[] = $event->getActorId();
        });
        $writer = new ImportedTimeEntryWriter($this->repository(), $dispatcher);
        $writer->upsert($this->user, $this->data('e-1'));
        $writer->removeForUser(self::SOURCE, $this->user, 'manager-3');

        self::assertSame(['manager-3'], $actors);
    }

    public function testAManagerRemovalOfOwnerlessEntriesIsRecorded(): void
    {
        /** @var list<TimeEntriesRemovedEvent> $events */
        $events     = [];
        $dispatcher = new EventDispatcher();
        $dispatcher->addListener(TimeTrackEvents::TIME_ENTRIES_REMOVED, static function (TimeEntriesRemovedEvent $event) use (&$events): void {
            $events[] = $event;
        });
        $writer = new ImportedTimeEntryWriter($this->repository(), $dispatcher);
        $writer->upsert($this->user, $this->data('e-1'));
        $writer->upsertForPerson(new ImportedTimeEntryPerson('p-1', 'Ana'), $this->data('e-2', seconds: 1800));
        $writer->upsertForPerson(new ImportedTimeEntryPerson('p-1', 'Ana'), $this->data('e-3', seconds: 1800));

        self::assertSame(3, $writer->removeForBoard(self::SOURCE, 'board-1', 'manager-7'));

        self::assertCount(1, $events);
        self::assertSame('manager-7', $events[0]->getActorId());
        self::assertSame(3, $events[0]->getCount());
        self::assertSame(['task-1' => 7200], $events[0]->getSecondsByTask());
        self::assertSame([(string) $this->user->getId()], $events[0]->getUserIds());
        self::assertSame(['p-1'], $events[0]->getPersonRefs());
    }

    public function testTheOtherBulkRemovalsCarryTheActor(): void
    {
        /** @var list<string|null> $actors */
        $actors     = [];
        $dispatcher = new EventDispatcher();
        $dispatcher->addListener(TimeTrackEvents::TIME_ENTRIES_REMOVED, static function (TimeEntriesRemovedEvent $event) use (&$actors): void {
            $actors[] = $event->getActorId();
        });
        $writer = new ImportedTimeEntryWriter($this->repository(), $dispatcher);
        $person = new ImportedTimeEntryPerson('p-1', 'Ana');
        $writer->upsertForPerson($person, $this->data('e-1'));
        $writer->removeForTasks(self::SOURCE, ['task-1'], 'manager-1');
        $writer->upsertForPerson($person, $this->data('e-2'));
        $writer->removeForSource(self::SOURCE, 'manager-2');

        self::assertSame(['manager-1', 'manager-2'], $actors);
    }

    public function testNoOneListsOwnerlessEntriesByDefault(): void
    {
        $this->writer()->upsertForPerson(new ImportedTimeEntryPerson('p-1', 'Ana'), $this->data('e-1'));

        self::assertSame(0, $this->service()->listEntriesPage($this->user, ...[...$this->period(), 1, 10])->total);
        self::assertSame([], $this->service()->listEntries($this->user, ...$this->period()));
    }

    public function testAListenerLetsAViewerSeeTheOwnerlessEntriesOfTheirScopes(): void
    {
        $writer = $this->writer();
        $writer->upsert($this->user, $this->data('own', start: new DateTimeImmutable('2026-01-01 09:00:00'), end: new DateTimeImmutable('2026-01-01 10:00:00')));
        $writer->upsertForPerson(new ImportedTimeEntryPerson('p-1', 'Ana', 'org-a'), $this->data('a'));
        $writer->upsertForPerson(new ImportedTimeEntryPerson('p-2', 'Luis', 'org-b'), $this->data('b'));
        $writer->upsertForPerson(new ImportedTimeEntryPerson('p-3', 'Noscope'), $this->data('none'));
        $scopes = ['org-a'];
        $this->dispatcher()->addListener(TimeTrackEvents::TIME_ENTRY_LIST_QUERY, static function (TimeEntryListQueryEvent $event) use (&$scopes): void {
            $event->includeOwnerless($scopes);
        });

        $page = $this->service()->listEntriesPage($this->user, ...[...$this->period(), 1, 10]);
        self::assertSame(2, $page->total);
        self::assertSame(['a', 'own'], array_map(static fn (TimeEntry $entry): ?string => $entry->getExternalId(), $page->entries));
        self::assertCount(2, $this->service()->listEntries($this->user, ...$this->period()));

        $scopes = null;
        self::assertSame(4, $this->service()->listEntriesPage($this->user, ...[...$this->period(), 1, 10])->total);

        $scopes = [];
        self::assertSame(1, $this->service()->listEntriesPage($this->user, ...[...$this->period(), 1, 10])->total, 'no scope, no ownerless entry');
        self::assertCount(1, $this->service()->listEntries($this->user, ...$this->period()));
    }

    public function testTheStoreCanListOnlyOwnerlessEntries(): void
    {
        $this->writer()->upsert($this->user, $this->data('own'));
        $this->writer()->upsertForPerson(new ImportedTimeEntryPerson('p-1', 'Ana'), $this->data('person'));
        [$from, $to] = $this->period();

        self::assertSame(1, $this->repository()->countWithOwnerless([], null, $from, $to, true));
        self::assertSame(['person'], array_map(static fn (TimeEntry $entry): ?string => $entry->getExternalId(), $this->repository()->findPageWithOwnerless([], null, $from, $to, true, 0, 10)));
    }

    public function testALiveOnlyViewLeavesImportedOwnerlessEntriesOut(): void
    {
        $this->writer()->upsertForPerson(new ImportedTimeEntryPerson('p-1', 'Ana'), $this->data('e-1'));
        $this->dispatcher()->addListener(TimeTrackEvents::TIME_ENTRY_LIST_QUERY, static function (TimeEntryListQueryEvent $event): void {
            $event->includeOwnerless(null);
            $event->setIncludeImported(false);
        });

        self::assertSame(0, $this->service()->listEntriesPage($this->user, ...[...$this->period(), 1, 10])->total);
        self::assertSame([], $this->service()->listEntries($this->user, ...$this->period()));
    }

    public function testAStoreWithoutTheCapabilityFailsOnlyWhenUsed(): void
    {
        $people = new FormerPersonTimeEntries(new InMemoryTimeEntryRepository());
        self::assertFalse($people->isAvailable());

        $this->expectException(LogicException::class);
        $people->anonymisePerson('p-1');
    }

    public function testOwnerlessTaskTitlesFollowTheViewer(): void
    {
        $this->writer()->upsertForPerson(new ImportedTimeEntryPerson('p-1', 'Ana'), $this->data('e-1'));
        $entry = $this->reload('e-1');

        self::assertSame([$entry->getId() => true], $this->service()->hiddenTaskEntryIds([$entry]), 'without a viewer it is masked');
        self::assertSame([], $this->service()->hiddenTaskEntryIds([$entry], $this->user), 'a viewer who may track the task sees it');
        $this->writer()->upsertForPerson(new ImportedTimeEntryPerson('p-1', 'Ana'), new ImportedTimeEntryData(self::SOURCE, 'e-2', 'gone', 'Secret task', null, new DateTimeImmutable('2026-01-01 10:00:00'), new DateTimeImmutable('2026-01-01 11:00:00'), 3600));
        $hiddenEntry = $this->reload('e-2');
        self::assertSame([$hiddenEntry->getId() => true], $this->service()->hiddenTaskEntryIds([$hiddenEntry], $this->user), 'one who may not, does not');
    }

    public function testTheReportsPageShowsTheNameEscapedAndTheGenericLabel(): void
    {
        $writer = $this->writer();
        $writer->upsertForPerson(new ImportedTimeEntryPerson('p-1', '<b>Ana</b>'), $this->data('e-1'));
        $writer->upsertForPerson(new ImportedTimeEntryPerson('p-2'), $this->data('e-2'));
        $this->dispatcher()->addListener(TimeTrackEvents::TIME_ENTRY_LIST_QUERY, static function (TimeEntryListQueryEvent $event): void {
            $event->includeOwnerless(null);
        });

        $this->client->loginUser($this->user, 'main');
        $this->client->request('GET', '/tools/time-track/reports?from=2026-01-01&to=2026-01-02');
        self::assertResponseIsSuccessful();
        $html = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('&lt;b&gt;Ana&lt;/b&gt;', $html);
        self::assertStringContainsString('Former person', $html);
        self::assertStringNotContainsString('<b>Ana</b>', $html);
    }

    public function testTheReportsPageIsUnchangedWithoutOwnerlessRows(): void
    {
        $this->writer()->upsert($this->user, $this->data('e-1'));

        $this->client->loginUser($this->user, 'main');
        $this->client->request('GET', '/tools/time-track/reports?from=2026-01-01&to=2026-01-02');
        self::assertResponseIsSuccessful();
        self::assertStringNotContainsString('time-track-former-person', (string) $this->client->getResponse()->getContent());
    }

    public function testTheTaskTotalCountsAFormerPersonsHours(): void
    {
        $writer = $this->writer();
        $writer->upsert($this->user, $this->data('e-1'));
        $writer->upsertForPerson(new ImportedTimeEntryPerson('p-1', 'Ana'), $this->data('e-2', seconds: 1800));
        $repository = self::getContainer()->get(TimeEntryRepositoryInterface::class);
        self::assertInstanceOf(DoctrineOrmTimeEntryRepository::class, $repository);

        self::assertSame(['task-1' => 5400], $repository->loggedSecondsByTask(['task-1']));
        self::assertSame(['task-1' => 3600], $repository->loggedSecondsByTask(['task-1'], (string) $this->user->getId()));
    }

    public function testOwnerQueriesSkipOwnerlessEntries(): void
    {
        $this->writer()->upsertForPerson(new ImportedTimeEntryPerson('p-1', 'Ana', 'org-a'), $this->data('scoped'));
        $this->writer()->upsertForPerson(new ImportedTimeEntryPerson('p-2', 'Luis'), $this->data('scopeless'));
        $repository = self::getContainer()->get(TimeEntryRepositoryInterface::class);
        self::assertInstanceOf(DoctrineOrmTimeEntryRepository::class, $repository);

        self::assertSame([], $repository->ownersWithEntries(...$this->period()), 'no timesheet is created for nobody');
        self::assertSame([], $repository->findScopelessAfter('', 10), 'the back-fill asks by owner');
        self::assertSame([], $repository->findByUserAndPeriod((string) $this->user->getId(), ...$this->period()));
    }

    public function testManyEntriesAreChangedInBatches(): void
    {
        $writer = $this->writer();
        $rows   = [];
        for ($i = 0; $i < DoctrineOrmTimeEntryRepository::FORMER_PERSON_BATCH + 2; ++$i) {
            $rows[] = [new ImportedTimeEntryPerson('p-1', 'Ana'), $this->data(sprintf('e-%d', $i))];
            if (count($rows) === ImportedTimeEntryWriter::MAX_BATCH) {
                $writer->upsertMany($rows);
                $rows = [];
            }
        }
        $writer->upsertMany($rows);
        $this->entityManager()->clear();

        self::assertCount(DoctrineOrmTimeEntryRepository::FORMER_PERSON_BATCH + 2, $this->people()->export('p-1'));
        self::assertSame(DoctrineOrmTimeEntryRepository::FORMER_PERSON_BATCH + 2, $this->people()->anonymisePerson('p-1'));
    }

    /** SQLite checks foreign keys only when asked (per connection). */
    private function enforceForeignKeys(): void
    {
        $conn = $this->entityManager()->getConnection();
        if ($conn->getDatabasePlatform() instanceof SQLitePlatform) {
            $conn->executeStatement('PRAGMA foreign_keys = ON');
        }
    }

    private function managedUser(): AppUser
    {
        $user = $this->entityManager()->find(AppUser::class, $this->user->getId());
        self::assertInstanceOf(AppUser::class, $user);

        return $user;
    }

    private function assertMismatch(callable $call, string $message): void
    {
        try {
            $call();
            self::fail($message);
        } catch (ImportedEntryOwnerMismatchException) {
            $this->addToAssertionCount(1);
        }
    }

    /**
     * @return array{DateTimeImmutable, DateTimeImmutable}
     */
    private function period(): array
    {
        return [new DateTimeImmutable('2026-01-01 00:00:00'), new DateTimeImmutable('2026-01-02 00:00:00')];
    }

    private function userIdOf(TimeEntry $entry): ?int
    {
        $user = $entry->getUser();

        return $user instanceof AppUser ? $user->getId() : null;
    }

    private function reload(string $externalId): TimeEntry
    {
        $entry = $this->repository()->findByExternal(self::SOURCE, $externalId)
            ?? $this->repository()->findByExternal(self::SOURCE, TimeEntry::anonymisedExternalId(self::SOURCE, $externalId));
        self::assertInstanceOf(TimeEntry::class, $entry);

        return $entry;
    }

    private function writer(): ImportedTimeEntryWriter
    {
        return new ImportedTimeEntryWriter($this->repository());
    }

    private function people(): FormerPersonTimeEntries
    {
        $people = self::getContainer()->get(FormerPersonTimeEntries::class);
        self::assertInstanceOf(FormerPersonTimeEntries::class, $people);

        return $people;
    }

    private function repository(): DoctrineOrmTimeEntryRepository
    {
        $repository = self::getContainer()->get(TimeEntryRepositoryInterface::class);
        self::assertInstanceOf(DoctrineOrmTimeEntryRepository::class, $repository);

        return $repository;
    }

    private function service(): TimerService
    {
        $service = self::getContainer()->get(TimerService::class);
        self::assertInstanceOf(TimerService::class, $service);

        return $service;
    }

    private function dispatcher(): EventDispatcherInterface
    {
        $dispatcher = self::getContainer()->get('event_dispatcher');
        self::assertInstanceOf(EventDispatcherInterface::class, $dispatcher);

        return $dispatcher;
    }

    private function data(
        string $id,
        int $seconds = 3600,
        ?string $description = 'Imported from ClickUp',
        ?DateTimeImmutable $start = null,
        ?DateTimeImmutable $end = null,
    ): ImportedTimeEntryData {
        $start ??= new DateTimeImmutable('2026-01-01 10:00:00');
        $end ??= $start->modify('+1 hour');

        return new ImportedTimeEntryData(self::SOURCE, $id, 'task-1', 'Implement login API', 'board-1', $start, $end, $seconds, true, $description);
    }
}
