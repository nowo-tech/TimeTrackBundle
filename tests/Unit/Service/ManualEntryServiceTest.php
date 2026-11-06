<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Tests\Unit\Service;

use DateTimeImmutable;
use Nowo\TimeTrackBundle\Bridge\StubTaskProvider;
use Nowo\TimeTrackBundle\Entity\TimeEntry;
use Nowo\TimeTrackBundle\Enum\TimeEntrySource;
use Nowo\TimeTrackBundle\Exception\ManualEntryException;
use Nowo\TimeTrackBundle\Security\TaskProviderTaskAccessChecker;
use Nowo\TimeTrackBundle\Service\ManualEntryService;
use Nowo\TimeTrackBundle\Service\TimerService;
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
