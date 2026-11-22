<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Tests\Functional;

use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Nowo\TimeTrackBundle\Access\TimeEntryAccessInterface;
use Nowo\TimeTrackBundle\Doctrine\TimeTrackEntityManager;
use Nowo\TimeTrackBundle\Entity\TimeEntry;
use Nowo\TimeTrackBundle\Enum\TimeEntrySource;
use Nowo\TimeTrackBundle\Event\TimeEntryIdAccessCheckEvent;
use Nowo\TimeTrackBundle\Event\TimeTrackEvents;
use Nowo\TimeTrackBundle\Import\ImportedTimeEntryData;
use Nowo\TimeTrackBundle\Integration\UserReferenceResolverInterface;
use Nowo\TimeTrackBundle\ValueObject\TimeTrackUserRef;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Security\Core\User\UserInterface;

use function array_keys;
use function bin2hex;
use function random_bytes;
use function strtoupper;

/**
 * 1.37.0 (DDD-host checklist B1/B8): entry access by user id and entry id through the public
 * {@see TimeEntryAccessInterface}, on a standard host ({@see EntryAccessByIdStandardTest}) and on an id / XML-mapping host
 * with the private entity manager ({@see EntryAccessByIdDddHostTest}). On MySQL and MariaDB the entry id column folds
 * case, so the look-alike scenarios reach the PHP re-check.
 */
abstract class EntryAccessByIdTestCase extends FunctionalTestCase
{
    /**
     * A host user of the variant (persisted when the variant needs it).
     *
     * @param non-empty-string $name
     */
    abstract protected function person(string $name): UserInterface;

    /**
     * The user id the port takes for that user.
     */
    abstract protected function idOf(UserInterface $user): string;

    public function testTheOwnerReachesTheEntryAndEveryOtherAnswerIsTheSameRefusal(): void
    {
        [$ana, $bob] = [$this->person('ana'), $this->person('bob')];
        $mine        = $this->entry($ana, 'task-1');
        $access      = $this->access();

        self::assertTrue($access->canViewEntry($this->idOf($ana), $mine));
        self::assertTrue($access->canEditEntry($this->idOf($ana), $mine));
        $view = $access->viewableEntry($this->idOf($ana), $mine);
        self::assertSame([$mine, $this->idOf($ana), 'task-1', 'org-a', 3600], [$view?->id, $view?->userId, $view?->taskId, $view?->scopeKey, $view?->durationSeconds]);

        foreach ([[$this->idOf($bob), $mine], [$this->idOf($ana), strtoupper($mine)], [$this->idOf($ana), $mine . ' '], [$this->idOf($ana), 'missing'], ['0' . $this->idOf($ana), $mine], [$this->idOf($ana) . ' ', $mine]] as [$user, $id]) {
            self::assertFalse($access->canViewEntry($user, $id), $user . ' / ' . $id);
            self::assertFalse($access->canEditEntry($user, $id));
            self::assertNull($access->viewableEntry($user, $id));
        }
    }

    public function testAdminRolesFromTheReferenceAndImportedEntries(): void
    {
        $ana      = $this->person('ana');
        $imported = $this->imported($ana);
        $admin    = TimeTrackUserRef::ofId('App\\User', 'admin-1', '', ['ROLE_ADMIN']);

        self::assertTrue($this->access()->canViewEntry($this->idOf($ana), $imported));
        self::assertFalse($this->access()->canEditEntry($this->idOf($ana), $imported), 'imported entries are read-only');
        self::assertFalse($this->access()->canViewEntry('admin-1', $imported), 'a bare id has no roles');
        self::assertTrue($this->access()->canViewEntry($admin, $imported));
        self::assertTrue($this->access()->canEditEntry($admin, $this->entry($ana, 'task-2')));
    }

    public function testAnIdsOnlyListenerNarrowsEveryCheck(): void
    {
        $ana   = $this->person('ana');
        $entry = $this->entry($ana, 'task-1');
        $seen  = [];
        $this->dispatcher()->addListener(TimeTrackEvents::TIME_ENTRY_ID_ACCESS_CHECK, static function (TimeEntryIdAccessCheckEvent $event) use (&$seen): void {
            $seen[] = [$event->getAction(), $event->getOwnerId(), $event->getTaskId(), $event->getScopeKey()];
            if ($event->getAction() === 'edit') {
                $event->deny();
            }
        });

        self::assertTrue($this->access()->canViewEntry($this->idOf($ana), $entry));
        self::assertFalse($this->access()->canEditEntry($this->idOf($ana), $entry));
        self::assertSame([['view', $this->idOf($ana), 'task-1', 'org-a'], ['edit', $this->idOf($ana), 'task-1', 'org-a']], $seen);
    }

    /**
     * The check reads the bundle's own manager: with the private one, nothing enters the host's entity manager, and two
     * people in turn on one kernel (worker mode, no reset) get their own answers.
     */
    public function testNothingIsKeptBetweenCalls(): void
    {
        [$ana, $bob] = [$this->person('ana'), $this->person('bob')];
        [$a, $b]     = [$this->entry($ana, 'task-1'), $this->entry($bob, 'task-1')];

        for ($i = 0; $i < 3; ++$i) {
            self::assertSame([true, false, false, true], [$this->access()->canEditEntry($this->idOf($ana), $a), $this->access()->canEditEntry($this->idOf($ana), $b), $this->access()->canEditEntry($this->idOf($bob), $a), $this->access()->canEditEntry($this->idOf($bob), $b)]);
        }
        if (self::getContainer()->has(TimeTrackEntityManager::class)) {
            self::assertNotContains(TimeEntry::class, array_keys($this->entityManager()->getUnitOfWork()->getIdentityMap()), 'no bundle entity in the host manager');
        }
    }

    protected function access(): TimeEntryAccessInterface
    {
        $access = self::getContainer()->get(TimeEntryAccessInterface::class);
        self::assertInstanceOf(TimeEntryAccessInterface::class, $access);

        return $access;
    }

    /**
     * A closed live entry in scope `org-a`; returns its id.
     */
    protected function entry(UserInterface $user, string $task): string
    {
        $startedAt = new DateTimeImmutable('2026-11-02 09:00 UTC');
        $entry     = new TimeEntry($this->owner($user), $task, 'Task ' . $task, 'board-1', $startedAt, $startedAt->modify('+1 hour'), 3600, TimeEntrySource::Web);
        $entry->assignScopeKey('org-a');
        $this->persist($entry);

        return $entry->getId();
    }

    /**
     * An imported (read-only) entry; returns its id.
     */
    protected function imported(UserInterface $user): string
    {
        $startedAt = new DateTimeImmutable('2026-11-03 09:00 UTC');
        $entry     = TimeEntry::imported($this->owner($user), new ImportedTimeEntryData('clickup:dir', 'ext-' . bin2hex(random_bytes(4)), 'task-1', 'Task', 'board-1', $startedAt, $startedAt->modify('+1 hour'), 3600, true));
        $entry->assignScopeKey('org-a');
        $this->persist($entry);

        return $entry->getId();
    }

    private function persist(object $row): void
    {
        $this->bundleManager()->persist($row);
        $this->bundleManager()->flush();
        // Read back from the database, not from the identity map.
        $this->bundleManager()->detach($row);
    }

    private function owner(UserInterface $user): object
    {
        $resolver = self::getContainer()->get(UserReferenceResolverInterface::class);
        self::assertInstanceOf(UserReferenceResolverInterface::class, $resolver);

        return $resolver->reference($user);
    }

    private function bundleManager(): EntityManagerInterface
    {
        $container = self::getContainer();
        $manager   = $container->has(TimeTrackEntityManager::class) ? $container->get(TimeTrackEntityManager::class) : $this->entityManager();
        self::assertInstanceOf(EntityManagerInterface::class, $manager);

        return $manager;
    }

    private function dispatcher(): EventDispatcherInterface
    {
        $dispatcher = self::getContainer()->get(EventDispatcherInterface::class);
        self::assertInstanceOf(EventDispatcherInterface::class, $dispatcher);

        return $dispatcher;
    }
}
