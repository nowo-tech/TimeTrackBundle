<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Tests\Unit\Command;

use DateTimeImmutable;
use Nowo\TimeTrackBundle\Command\BackfillEntryScopeCommand;
use Nowo\TimeTrackBundle\Entity\TimeEntry;
use Nowo\TimeTrackBundle\Enum\TimeEntrySource;
use Nowo\TimeTrackBundle\Repository\ScopeBackfillRepositoryInterface;
use Nowo\TimeTrackBundle\Repository\TimeEntryRepositoryInterface;
use Nowo\TimeTrackBundle\Tests\Stub\TestUser;
use Nowo\TimeTrackBundle\Tests\Support\InMemoryTimeEntryRepository;
use Nowo\TimeTrackBundle\Tests\Support\RecordingLogger;
use Nowo\TimeTrackBundle\Timesheet\SingleScopeProvider;
use Nowo\TimeTrackBundle\Timesheet\TimesheetScopeProviderInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Security\Core\User\UserInterface;

use function array_slice;
use function str_repeat;

final class BackfillEntryScopeCommandTest extends TestCase
{
    public function testItNeedsAScopeProviderAndABackfillStore(): void
    {
        $withoutProvider = new CommandTester(new BackfillEntryScopeCommand(new InMemoryTimeEntryRepository()));
        self::assertSame(Command::FAILURE, $withoutProvider->execute([]));
        self::assertStringContainsString('Timesheets are off', $withoutProvider->getDisplay());

        $withoutStore = new CommandTester(new BackfillEntryScopeCommand(new InMemoryTimeEntryRepository(), new SingleScopeProvider()));
        self::assertSame(Command::FAILURE, $withoutStore->execute([]));
    }

    public function testUnplaceableEntriesStayWithoutAScopeAndTheLogHasCountsOnly(): void
    {
        $user    = new TestUser('7', 'ana@example.com');
        $entries = [];
        foreach (['a', 'b', 'c'] as $id) {
            $entries[] = new TimeEntry($user, 'task-' . $id, 'T', null, new DateTimeImmutable(), new DateTimeImmutable(), 0, TimeEntrySource::Web);
        }
        $store = new class($entries) implements TimeEntryRepositoryInterface, ScopeBackfillRepositoryInterface {
            /** @var array<string, string> */
            public array $assigned = [];

            /** @param list<TimeEntry> $entries */
            public function __construct(private readonly array $entries)
            {
            }

            public function save(TimeEntry $entry): void
            {
            }

            public function findByUserAndPeriod(string $userId, DateTimeImmutable $from, DateTimeImmutable $to): array
            {
                return [];
            }

            public function findScopelessAfter(string $afterId, int $limit): array
            {
                return $afterId === '' ? array_slice($this->entries, 0, $limit) : [];
            }

            public function assignScopeKeys(array $scopeKeys): int
            {
                $this->assigned = $scopeKeys;

                return 1;
            }
        };
        $provider = new class implements TimesheetScopeProviderInterface {
            public function scopesFor(UserInterface $user): array
            {
                return [];
            }

            public function scopeKeyForTask(UserInterface $user, string $taskId, ?string $boardId): ?string
            {
                return null;
            }

            public function scopeKeysForEntries(array $entries): array
            {
                return [$entries[0]->getId() => 'org-a', $entries[1]->getId() => null, $entries[2]->getId() => str_repeat('x', 65)];
            }
        };
        $logger = new RecordingLogger();
        $tester = new CommandTester(new BackfillEntryScopeCommand($store, $provider, $logger));

        self::assertSame(Command::SUCCESS, $tester->execute(['--batch' => '10']));
        self::assertSame([$entries[0]->getId() => 'org-a'], $store->assigned);
        self::assertStringContainsString('Placed 1 entry; 2 left without a scope.', $tester->getDisplay());
        self::assertSame(['bundle' => 'time_track', 'action' => 'entries_backfill_scope', 'placed' => 1, 'unplaced' => 2, 'dry_run' => false], $logger->records[0]['context']);
    }
}
