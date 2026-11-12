<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Tests\Unit\Service;

use DateTimeImmutable;
use Nowo\TimeTrackBundle\Bridge\StubTaskProvider;
use Nowo\TimeTrackBundle\Entity\TimeEntry;
use Nowo\TimeTrackBundle\Enum\TimeEntrySource;
use Nowo\TimeTrackBundle\Event\TimeEntryAccessCheckEvent;
use Nowo\TimeTrackBundle\Event\TimeTrackEvents;
use Nowo\TimeTrackBundle\Exception\ManualEntryException;
use Nowo\TimeTrackBundle\Integration\NullTimeEntryBillableDefaultProvider;
use Nowo\TimeTrackBundle\Security\TaskProviderTaskAccessChecker;
use Nowo\TimeTrackBundle\Service\ManualEntryService;
use Nowo\TimeTrackBundle\Service\TeamAccessGuard;
use Nowo\TimeTrackBundle\Service\TimerService;
use Nowo\TimeTrackBundle\Tests\Stub\StubBillableDefaultProvider;
use Nowo\TimeTrackBundle\Tests\Stub\StubTeamContextProvider;
use Nowo\TimeTrackBundle\Tests\Stub\TestUser;
use Nowo\TimeTrackBundle\Tests\Support\InMemoryActiveTimerRepository;
use Nowo\TimeTrackBundle\Tests\Support\InMemoryTimeEntryRepository;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\EventDispatcher\EventDispatcher;

/**
 * Since 1.14 (contract §10.3, Q-O7): entry stores without the overlap query are read from two days before; imported
 * entries never overlap; only `manual` and `calendar` are accepted.
 */
final class ManualEntryServiceTest extends TestCase
{
    public function testStoresWithoutTheOverlapQuery(): void
    {
        $user    = new TestUser('1', 'u@example.com');
        $entries = new InMemoryTimeEntryRepository();
        $timers  = new InMemoryActiveTimerRepository();
        $tasks   = new StubTaskProvider();
        $clock   = new MockClock('2026-11-05 12:00:00');
        $service = new ManualEntryService(new TimerService($tasks, $timers, $entries, new EventDispatcher(), $clock), $entries, $timers, new TaskProviderTaskAccessChecker($tasks), clock: $clock);
        $entries->save(new TimeEntry($user, 'task-1', 'Long', null, new DateTimeImmutable('2026-11-03 08:00'), new DateTimeImmutable('2026-11-04 07:00'), 82_800, TimeEntrySource::Web));
        $entries->save(new TimeEntry($user, 'task-1', 'Imported', null, new DateTimeImmutable('2026-11-04 08:00'), new DateTimeImmutable('2026-11-04 09:00'), 3600, TimeEntrySource::Import));

        self::assertFalse($service->isEnabled());
        $created = $service->create($user, 'task-2', new DateTimeImmutable('2026-11-04 08:00'), new DateTimeImmutable('2026-11-04 08:30'), TimeEntrySource::Manual);
        self::assertSame(['task-2', null, null], [$created->getTaskId(), $created->getMetadata(), $created->getScopeKey()]);
        $this->expectRefusal(ManualEntryException::OVERLAP, static fn (): TimeEntry => $service->create($user, 'task-2', new DateTimeImmutable('2026-11-04 06:00'), new DateTimeImmutable('2026-11-04 07:30'), TimeEntrySource::Manual));
        $this->expectRefusal(ManualEntryException::INVALID_RANGE, static fn (): TimeEntry => $service->create($user, 'task-2', new DateTimeImmutable('2026-11-04 10:00'), new DateTimeImmutable('2026-11-04 11:00'), TimeEntrySource::Desktop));
        self::assertSame([403, 409, 422], [(new ManualEntryException(ManualEntryException::NOT_TRACKABLE))->httpStatus(), (new ManualEntryException(ManualEntryException::OVERLAP))->httpStatus(), (new ManualEntryException(ManualEntryException::TOO_OLD))->httpStatus()]);
    }

    public function testTheHostDefaultBillableAppliesWhenNoValueIsGiven(): void
    {
        $user     = new TestUser('1', 'u@example.com');
        $entries  = new InMemoryTimeEntryRepository();
        $timers   = new InMemoryActiveTimerRepository();
        $tasks    = new StubTaskProvider();
        $clock    = new MockClock('2026-11-05 12:00:00');
        $provider = new StubBillableDefaultProvider(true);
        $service  = new ManualEntryService(new TimerService($tasks, $timers, $entries, new EventDispatcher(), $clock), $entries, $timers, new TaskProviderTaskAccessChecker($tasks), clock: $clock, billableDefaults: $provider);

        $default  = $service->create($user, 'task-1', new DateTimeImmutable('2026-11-04 08:00'), new DateTimeImmutable('2026-11-04 08:30'), TimeEntrySource::Manual);
        $explicit = $service->create($user, 'task-2', new DateTimeImmutable('2026-11-04 09:00'), new DateTimeImmutable('2026-11-04 09:30'), TimeEntrySource::Manual, billable: false);
        self::assertSame([true, false], [$default->getBillable(), $explicit->getBillable()]);
        self::assertSame([['', 'task-1']], $provider->calls, 'asked once, with no scope as an empty string; an explicit value does not ask');

        // A null answer keeps the behaviour before 1.21: the flag stays unset.
        $provider->answer = null;
        self::assertNull($service->create($user, 'task-1', new DateTimeImmutable('2026-11-04 10:00'), new DateTimeImmutable('2026-11-04 10:30'), TimeEntrySource::Manual)->getBillable());
        $none = new ManualEntryService(new TimerService($tasks, $timers, $entries, new EventDispatcher(), $clock), $entries, $timers, new TaskProviderTaskAccessChecker($tasks), clock: $clock, billableDefaults: new NullTimeEntryBillableDefaultProvider());
        self::assertNull($none->create($user, 'task-1', new DateTimeImmutable('2026-11-04 11:00'), new DateTimeImmutable('2026-11-04 11:30'), TimeEntrySource::Manual)->getBillable());
    }

    public function testAnExplicitBillableNeedsTheEditBillableGrant(): void
    {
        // Audit MV-T-L3: the host's `edit_billable` check decides an explicit value; the default needs no grant.
        $user       = new TestUser('1', 'u@example.com');
        $entries    = new InMemoryTimeEntryRepository();
        $timers     = new InMemoryActiveTimerRepository();
        $tasks      = new StubTaskProvider();
        $clock      = new MockClock('2026-11-05 12:00:00');
        $dispatcher = new EventDispatcher();
        $dispatcher->addListener(TimeTrackEvents::TIME_ENTRY_ACCESS_CHECK, static function (TimeEntryAccessCheckEvent $event): void {
            if ($event->getAction() === TeamAccessGuard::ACTION_EDIT_BILLABLE) {
                $event->deny();
            }
        });
        $guard   = new TeamAccessGuard(new StubTeamContextProvider(), $dispatcher, [], false, false);
        $service = new ManualEntryService(new TimerService($tasks, $timers, $entries, new EventDispatcher(), $clock), $entries, $timers, new TaskProviderTaskAccessChecker($tasks), clock: $clock, billableDefaults: new StubBillableDefaultProvider(true), accessGuard: $guard);

        $this->expectRefusal(ManualEntryException::BILLABLE_FORBIDDEN, static fn (): TimeEntry => $service->create($user, 'task-1', new DateTimeImmutable('2026-11-04 08:00'), new DateTimeImmutable('2026-11-04 08:30'), TimeEntrySource::Manual, billable: false));
        self::assertSame([], $entries->findByUserAndPeriod('1', new DateTimeImmutable('2026-11-01'), new DateTimeImmutable('2026-11-06')));
        self::assertTrue($service->create($user, 'task-1', new DateTimeImmutable('2026-11-04 08:00'), new DateTimeImmutable('2026-11-04 08:30'), TimeEntrySource::Manual)->getBillable());
        self::assertSame(403, (new ManualEntryException(ManualEntryException::BILLABLE_FORBIDDEN))->httpStatus());
    }

    private function expectRefusal(string $code, callable $operation): void
    {
        try {
            $operation();
            self::fail('expected ' . $code);
        } catch (ManualEntryException $exception) {
            self::assertSame($code, $exception->errorCode);
        }
    }
}
