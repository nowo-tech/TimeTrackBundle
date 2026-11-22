<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Tests\Unit\Access;

use DateTimeImmutable;
use InvalidArgumentException;
use Nowo\TimeTrackBundle\Access\ExactTimeEntryLookup;
use Nowo\TimeTrackBundle\Access\IdActor;
use Nowo\TimeTrackBundle\Access\TimeEntryAccess;
use Nowo\TimeTrackBundle\Entity\TimeEntry;
use Nowo\TimeTrackBundle\Enum\TimeEntrySource;
use Nowo\TimeTrackBundle\Event\TimeEntryAccessCheckEvent;
use Nowo\TimeTrackBundle\Event\TimeEntryIdAccessCheckEvent;
use Nowo\TimeTrackBundle\Event\TimeTrackEvents;
use Nowo\TimeTrackBundle\Import\ImportedTimeEntryData;
use Nowo\TimeTrackBundle\Integration\TeamDirectoryInterface;
use Nowo\TimeTrackBundle\Port\TeamDirectoryGate;
use Nowo\TimeTrackBundle\Port\TimeTrackUserRefFactory;
use Nowo\TimeTrackBundle\Repository\FindableTimeEntryRepositoryInterface;
use Nowo\TimeTrackBundle\Repository\TimeEntryRepositoryInterface;
use Nowo\TimeTrackBundle\Service\TeamAccessGuard;
use Nowo\TimeTrackBundle\Tests\Stub\StubTeamContextProvider;
use Nowo\TimeTrackBundle\Tests\Stub\TestUser;
use Nowo\TimeTrackBundle\Tests\Support\FindableInMemoryTimeEntryRepository;
use Nowo\TimeTrackBundle\Tests\Support\FixedScopeProvider;
use Nowo\TimeTrackBundle\Tests\Support\InMemoryTimeEntryRepository;
use Nowo\TimeTrackBundle\Tests\Support\InMemoryTimesheetRepository;
use Nowo\TimeTrackBundle\Tests\Support\RecordingLogger;
use Nowo\TimeTrackBundle\Tests\Support\TimesheetFixtures;
use Nowo\TimeTrackBundle\Timesheet\PeriodLockGuard;
use Nowo\TimeTrackBundle\ValueObject\TimeTrackUserRef;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\EventDispatcher\EventDispatcher;

use function str_repeat;
use function strtoupper;

/**
 * 1.37.0 (DDD-host checklist B1/B8): entry access by user id and entry id, on the rules of {@see TeamAccessGuard}.
 */
final class TimeEntryAccessTest extends TestCase
{
    private FindableInMemoryTimeEntryRepository $entries;

    private EventDispatcher $dispatcher;

    private TestUser $ana;

    protected function setUp(): void
    {
        $this->entries    = new FindableInMemoryTimeEntryRepository();
        $this->dispatcher = new EventDispatcher();
        $this->ana        = new TestUser('7', 'ana@example.com');
    }

    public function testTheOwnerSeesAndEditsByIdAndEveryRefusalLooksTheSame(): void
    {
        $entry  = $this->entry($this->ana, 'task-1');
        $access = $this->access();

        foreach (['7', 7, TimeTrackUserRef::ofId(TestUser::class, '7'), TimeTrackUserRefFactory::build($this->ana)] as $user) {
            self::assertTrue($access->canViewEntry($user, $entry->getId()));
            self::assertTrue($access->canEditEntry($user, $entry->getId()));
            self::assertSame([$entry->getId(), '7', 'task-1'], [$access->viewableEntry($user, $entry->getId())?->id, $access->viewableEntry($user, $entry->getId())?->userId, $access->viewableEntry($user, $entry->getId())?->taskId]);
        }

        // Another user, an unknown id, a look-alike id, unusable user ids: all the same false / null.
        $refusals = [['8', $entry->getId()], ['7', 'missing'], ['7', strtoupper($entry->getId())], ['7', ' ' . $entry->getId()], ['7', ''], ['7', str_repeat('a', 37)], ['', $entry->getId()], [' 7', $entry->getId()], ['7 ', $entry->getId()], ["7\u{200B}", $entry->getId()], [str_repeat('7', 129), $entry->getId()], [new TimeTrackUserRef(TestUser::class, null), $entry->getId()]];
        foreach ($refusals as [$user, $id]) {
            self::assertFalse($access->canViewEntry($user, $id));
            self::assertFalse($access->canEditEntry($user, $id));
            self::assertNull($access->viewableEntry($user, $id));
        }
    }

    public function testManagersAndAdminRolesFollowTheGuardAndABareIdHasNoRoles(): void
    {
        $entry = $this->entry($this->ana, 'task-1');

        $managers = $this->access(new TeamAccessGuard(new StubTeamContextProvider(['7'], true), $this->dispatcher, ['ROLE_ADMIN'], true, false));
        self::assertTrue($managers->canViewEntry('9', $entry->getId()), 'a manager of the owner sees it');
        self::assertFalse($managers->canEditEntry('9', $entry->getId()), 'manager_can_edit_entries is false');

        $nobody = $this->access();
        self::assertFalse($nobody->canViewEntry('admin', $entry->getId()), 'a bare id carries no role');
        $admin = TimeTrackUserRef::ofId(TestUser::class, 'admin', 'admin@example.com', ['ROLE_ADMIN']);
        self::assertTrue($nobody->canViewEntry($admin, $entry->getId()));
        self::assertTrue($nobody->canEditEntry($admin, $entry->getId()));
        self::assertTrue($nobody->canEditEntry(TimeTrackUserRef::of(new TestUser('boss', 'boss@example.com', ['ROLE_ADMIN']), TestUser::class, 'boss', 'boss@example.com'), $entry->getId()), 'a reference with the object is checked as that object (its roles)');

        // An ownerless entry: admin roles only; never editable.
        $ownerless = $this->entry(null, 'task-1');
        self::assertFalse($nobody->canViewEntry('7', $ownerless->getId()));
        self::assertTrue($nobody->canViewEntry($admin, $ownerless->getId()));
        self::assertFalse($nobody->canEditEntry($admin, $ownerless->getId()));
    }

    public function testTheFrameworkFreeTeamDirectoryReceivesTheReference(): void
    {
        $entry     = $this->entry($this->ana, 'task-1');
        $directory = new class implements TeamDirectoryInterface {
            /** @var list<TimeTrackUserRef> */
            public array $asked = [];

            public function teamIdsOf(TimeTrackUserRef $user): array
            {
                return [];
            }

            public function manages(TimeTrackUserRef $manager, TimeTrackUserRef $member): bool
            {
                return false;
            }

            public function managedUserIdsOf(TimeTrackUserRef $manager): array
            {
                $this->asked[] = $manager;

                return $manager->id === 'lead' ? ['7'] : [];
            }
        };
        $access = $this->access(new TeamAccessGuard(new TeamDirectoryGate($directory), $this->dispatcher, ['ROLE_ADMIN'], true, true));

        self::assertTrue($access->canViewEntry(TimeTrackUserRef::ofId('App\\User', 'lead', 'lead@example.com'), $entry->getId()));
        self::assertFalse($access->canViewEntry('other', $entry->getId()));
        self::assertSame([['App\\User', 'lead', 'lead@example.com', null], [TestUser::class, 'other', '', null]], [[$directory->asked[0]->userClass, $directory->asked[0]->id, $directory->asked[0]->identifier, $directory->asked[0]->user()], [$directory->asked[1]->userClass, $directory->asked[1]->id, $directory->asked[1]->identifier, $directory->asked[1]->user()]]);
    }

    public function testIdsOnlyListenersNarrowAndNeverWidenAcrossTenants(): void
    {
        $scopes = new FixedScopeProvider(['7' => ['org-a'], 'admin-a' => ['org-a'], 'admin-b' => ['org-b']]);
        $guard  = new TeamAccessGuard(new StubTeamContextProvider(), $this->dispatcher, ['ROLE_ADMIN'], true, true, scopeProvider: $scopes);
        $access = $this->access($guard);
        $inA    = $this->entry($this->ana, null, 'org-a');
        $task   = $this->entry($this->ana, 'task-1', 'org-a');
        $adminA = TimeTrackUserRef::ofId(TestUser::class, 'admin-a', '', ['ROLE_ADMIN']);
        $adminB = TimeTrackUserRef::ofId(TestUser::class, 'admin-b', '', ['ROLE_ADMIN']);

        self::assertTrue($access->canViewEntry($adminA, $inA->getId()));
        self::assertFalse($access->canViewEntry($adminB, $inA->getId()), 'an entry without a task stays in its tenant');
        self::assertTrue($access->canViewEntry($adminB, $task->getId()), 'entries with a task are unchanged');

        /** @var list<array{string, string, ?string, ?string, ?string, string, bool}> $seen */
        $seen = [];
        $this->dispatcher->addListener(TimeTrackEvents::TIME_ENTRY_ID_ACCESS_CHECK, static function (TimeEntryIdAccessCheckEvent $event) use (&$seen): void {
            $seen[] = [$event->getUserId(), $event->getEntryId(), $event->getOwnerId(), $event->getTaskId(), $event->getScopeKey(), $event->getAction(), $event->isGranted()];
            $event->getUserId() === 'stranger' ? $event->grant() : $event->deny();
        });
        self::assertFalse($access->canViewEntry('stranger', $inA->getId()), 'a granting listener never crosses tenants');
        self::assertTrue($access->canViewEntry('stranger', $task->getId()), 'a listener may grant an entry with a task');
        self::assertFalse($access->canViewEntry('7', $inA->getId()), 'a listener may narrow, even the owner');
        self::assertFalse($access->canEditEntry('7', $task->getId()));
        self::assertSame([
            ['stranger', $inA->getId(), '7', null, 'org-a', 'view', false],
            ['stranger', $task->getId(), '7', 'task-1', 'org-a', 'view', false],
            ['7', $inA->getId(), '7', null, 'org-a', 'view', true],
            ['7', $task->getId(), '7', 'task-1', 'org-a', 'edit', true],
        ], $seen);
    }

    public function testTheLegacyListenerDecidesFirstAndTheIdsEventStartsFromIt(): void
    {
        $entry = $this->entry($this->ana, 'task-1');
        $this->dispatcher->addListener(TimeTrackEvents::TIME_ENTRY_ACCESS_CHECK, static function (TimeEntryAccessCheckEvent $event): void {
            $event->getAction() === TeamAccessGuard::ACTION_VIEW ? $event->grant() : $event->deny();
        });
        $initial = [];
        $this->dispatcher->addListener(TimeTrackEvents::TIME_ENTRY_ID_ACCESS_CHECK, static function (TimeEntryIdAccessCheckEvent $event) use (&$initial): void {
            $initial[] = $event->isGranted();
        });

        self::assertTrue($this->access()->canViewEntry('stranger', $entry->getId()));
        self::assertFalse($this->access()->canEditEntry('7', $entry->getId()));
        self::assertSame([true, false], $initial);
    }

    public function testImportedEntriesAndLockedPeriodsAreViewableButNotEditable(): void
    {
        $imported = TimeEntry::imported($this->ana, new ImportedTimeEntryData('clickup:1', 'e-1', 'task-1', 'T', null, new DateTimeImmutable('2026-11-10 09:00:00Z'), new DateTimeImmutable('2026-11-10 10:00:00Z'), 3600, true));
        $this->entries->save($imported);
        self::assertTrue($this->access()->canViewEntry('7', $imported->getId()));
        self::assertFalse($this->access()->canEditEntry('7', $imported->getId()));

        $timesheets = new InMemoryTimesheetRepository();
        $locked     = $this->entry($this->ana, 'task-1', 'org-a');
        $timesheets->save(TimesheetFixtures::locked($this->ana, 'org-a', '2026-W46'));
        $guard = new TeamAccessGuard(new StubTeamContextProvider(), $this->dispatcher, ['ROLE_ADMIN'], true, true, periodLockGuard: new PeriodLockGuard($timesheets));
        self::assertTrue($this->access($guard)->canViewEntry('7', $locked->getId()));
        self::assertFalse($this->access($guard)->canEditEntry('7', $locked->getId()));
    }

    public function testExactLookupAndAStoreThatCannotFind(): void
    {
        $entry = $this->entry($this->ana, 'task-1');
        self::assertSame($entry, ExactTimeEntryLookup::find($this->entries, $entry->getId()));
        self::assertNull(ExactTimeEntryLookup::find(new InMemoryTimeEntryRepository(), $entry->getId()));

        // A store whose collation folds case answers the stored row for a look-alike id: refused in PHP.
        $folding = new class($entry) implements TimeEntryRepositoryInterface, FindableTimeEntryRepositoryInterface {
            public function __construct(private readonly TimeEntry $row)
            {
            }

            public function save(TimeEntry $entry): void
            {
            }

            public function findByUserAndPeriod(string $userId, DateTimeImmutable $from, DateTimeImmutable $to): array
            {
                return [];
            }

            public function findById(string $id): TimeEntry
            {
                return $this->row;
            }
        };
        self::assertSame($entry, ExactTimeEntryLookup::find($folding, $entry->getId()));
        self::assertNull(ExactTimeEntryLookup::find($folding, strtoupper($entry->getId())));
        self::assertFalse((new TimeEntryAccess($folding, $this->guard(), TestUser::class))->canViewEntry('7', strtoupper($entry->getId())));
    }

    /** Audit YO-L1: a reference whose object has another id is refused, whatever the object's kind. */
    public function testAReferenceWhoseObjectHasAnotherIdIsRefused(): void
    {
        $entry  = $this->entry($this->ana, 'task-1');
        $access = $this->access();
        $plain  = static fn (string $id): object => new class($id) {
            public function __construct(private readonly string $id)
            {
            }

            public function getId(): string
            {
                return $this->id;
            }
        };

        self::assertFalse($access->canViewEntry(TimeTrackUserRef::of(new TestUser('8', 'bob@example.com'), TestUser::class, '7', 'ana@example.com'), $entry->getId()), 'a security user of another id');
        self::assertFalse($access->canEditEntry(TimeTrackUserRef::of($plain('8'), 'App\\User', '7', ''), $entry->getId()), 'a plain object of another id');
        self::assertNull($access->viewableEntry(TimeTrackUserRef::of($plain('07'), 'App\\User', '7', ''), $entry->getId()));
        self::assertTrue($access->canEditEntry(TimeTrackUserRef::of($plain('7'), 'App\\User', '7', ''), $entry->getId()), 'a plain object of the same id is checked by that id');
        self::assertTrue($access->canViewEntry(TimeTrackUserRef::of($this->ana, TestUser::class, '7', 'ana@example.com'), $entry->getId()));
    }

    /** Audit YO-L2: a failing listener or port is logged and answered as the same refusal (no error oracle). */
    public function testAFailureIsLoggedAndAnsweredAsARefusal(): void
    {
        $entry = $this->entry($this->ana, 'task-1');
        $this->dispatcher->addListener(TimeTrackEvents::TIME_ENTRY_ID_ACCESS_CHECK, static function (): never {
            throw new RuntimeException('listener down');
        });
        $logger = new RecordingLogger();
        $access = new TimeEntryAccess($this->entries, $this->guard(), TestUser::class, $logger);

        self::assertFalse($access->canViewEntry('7', $entry->getId()));
        self::assertFalse($access->canEditEntry('7', $entry->getId()));
        self::assertNull($access->viewableEntry('7', $entry->getId()));
        self::assertCount(3, $logger->records);
        self::assertSame(['warning', 'entry_access_view', 'entry_access_edit', RuntimeException::class], [$logger->records[0]['level'], $logger->records[0]['context']['action'], $logger->records[1]['context']['action'], $logger->records[2]['context']['exception']]);
    }

    public function testTheIdActorStandsForItsReference(): void
    {
        $ref   = TimeTrackUserRef::ofId('App\\User', '42', '', ['ROLE_A']);
        $actor = new IdActor($ref);
        self::assertSame(['42', '42', ['ROLE_A']], [$actor->getId(), $actor->getUserIdentifier(), $actor->getRoles()]);
        self::assertSame('ana', (new IdActor(TimeTrackUserRef::ofId('App\\User', '42', 'ana')))->getUserIdentifier());
        $actor->eraseCredentials();
        self::assertSame($ref, $actor->ref());
        self::assertSame($ref, TimeTrackUserRefFactory::buildFromObject($actor));

        $this->expectException(InvalidArgumentException::class);
        new IdActor(new TimeTrackUserRef('App\\User', null));
    }

    private function entry(?TestUser $owner, ?string $task, ?string $scope = null): TimeEntry
    {
        $from  = new DateTimeImmutable('2026-11-10 09:00:00Z');
        $entry = new TimeEntry($owner, $task, $task === null ? '' : 'T', null, $from, $from->modify('+1 hour'), 3600, TimeEntrySource::Web);
        $entry->assignScopeKey($scope);
        $this->entries->save($entry);

        return $entry;
    }

    private function guard(): TeamAccessGuard
    {
        return new TeamAccessGuard(new StubTeamContextProvider(), $this->dispatcher, ['ROLE_ADMIN'], true, true);
    }

    private function access(?TeamAccessGuard $guard = null): TimeEntryAccess
    {
        return new TimeEntryAccess($this->entries, $guard ?? $this->guard(), TestUser::class);
    }
}
