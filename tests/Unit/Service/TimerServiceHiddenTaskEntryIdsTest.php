<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Tests\Unit\Service;

use DateTimeImmutable;
use Nowo\TimeTrackBundle\Entity\TimeEntry;
use Nowo\TimeTrackBundle\Enum\TimeEntrySource;
use Nowo\TimeTrackBundle\Import\ImportedTimeEntryData;
use Nowo\TimeTrackBundle\Integration\BatchTaskProviderInterface;
use Nowo\TimeTrackBundle\Integration\TaskProviderInterface;
use Nowo\TimeTrackBundle\Service\TimerService;
use Nowo\TimeTrackBundle\Tests\Stub\TestUser;
use Nowo\TimeTrackBundle\Tests\Support\InMemoryActiveTimerRepository;
use Nowo\TimeTrackBundle\Tests\Support\InMemoryTimeEntryRepository;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Since 1.12 (FJ-I8): masking asks a batch-capable task provider once per entry owner.
 */
final class TimerServiceHiddenTaskEntryIdsTest extends TestCase
{
    public function testABatchProviderIsAskedOncePerOwner(): void
    {
        $ann   = new TestUser('1', 'ann@example.com');
        $bob   = new TestUser('2', 'bob@example.com');
        $a1    = $this->imported($ann, 'e-1', 'task-1');
        $a2    = $this->imported($ann, 'e-2', 'task-2');
        $a3    = $this->imported($ann, 'e-3', 'task-2');
        $b1    = $this->imported($bob, 'e-4', '42');
        $live  = new TimeEntry($ann, 'task-2', 'T', null, new DateTimeImmutable('2026-01-01 08:00:00'), new DateTimeImmutable('2026-01-01 09:00:00'), 3600, TimeEntrySource::Web);
        $calls = [];

        $provider = $this->createMock(BatchTaskProviderInterface::class);
        $provider->expects(self::never())->method('canUserTrack');
        $provider->expects(self::exactly(2))->method('filterTrackable')->willReturnCallback(
            static function (UserInterface $user, array $taskIds) use (&$calls): array {
                $calls[] = [$user->getUserIdentifier(), $taskIds];

                return $user->getUserIdentifier() === 'ann@example.com' ? ['task-1'] : [];
            },
        );

        $hidden = $this->service($provider)->hiddenTaskEntryIds([$a1, $a2, $live, $a3, $b1]);

        self::assertSame([$a2->getId() => true, $a3->getId() => true, $b1->getId() => true], $hidden);
        self::assertSame([['ann@example.com', ['task-1', 'task-2']], ['bob@example.com', ['42']]], $calls);
    }

    public function testAPlainProviderIsAskedOncePerOwnerAndTask(): void
    {
        $ann = new TestUser('1', 'ann@example.com');
        $a1  = $this->imported($ann, 'e-1', 'task-1');
        $a2  = $this->imported($ann, 'e-2', 'task-1');
        $a3  = $this->imported($ann, 'e-3', 'task-2');

        $provider = $this->createMock(TaskProviderInterface::class);
        $provider->expects(self::exactly(2))->method('canUserTrack')->willReturnCallback(static fn (UserInterface $user, string $taskId): bool => $taskId === 'task-2');

        self::assertSame([$a1->getId() => true, $a2->getId() => true], $this->service($provider)->hiddenTaskEntryIds([$a1, $a2, $a3]));
    }

    private function service(TaskProviderInterface $provider): TimerService
    {
        return new TimerService($provider, new InMemoryActiveTimerRepository(), new InMemoryTimeEntryRepository(), new EventDispatcher());
    }

    private function imported(UserInterface $user, string $id, string $taskId): TimeEntry
    {
        return TimeEntry::imported($user, new ImportedTimeEntryData('clickup:dir-1', $id, $taskId, 'T', null, new DateTimeImmutable('2026-01-01 10:00:00'), new DateTimeImmutable('2026-01-01 11:00:00'), 3600));
    }
}
