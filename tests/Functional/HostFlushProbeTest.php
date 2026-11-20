<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Tests\Functional;

use DateTimeImmutable;
use Nowo\TimeTrackBundle\Entity\TimeEntry;
use Nowo\TimeTrackBundle\Enum\TimeEntrySource;
use Nowo\TimeTrackBundle\Service\ManualEntryService;
use Nowo\TimeTrackBundle\Tests\Fixtures\FunctionalEntity\AppUser;
use Nowo\TimeTrackBundle\Tests\Fixtures\ProbeEntity\EntryNote;
use Nowo\TimeTrackBundle\Tests\Support\FunctionalKernel;
use RuntimeException;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Component\HttpKernel\TerminableInterface;

use function array_filter;
use function array_values;
use function assert;
use function is_array;
use function json_decode;
use function json_encode;
use function restore_error_handler;
use function set_error_handler;
use function sprintf;
use function str_contains;

use const E_USER_DEPRECATED;
use const JSON_THROW_ON_ERROR;

/**
 * 1.33.0 (REQ-ARCH-001 rule 6 deprecation layer, REQ-RUNTIME-002): the host-flush probe of the shared manager
 * (`database.private_entity_manager: false`) in a kept-alive kernel (`http_kernel->handle()` + `terminate()`, no
 * services resetter, the application's manager never cleared). Every request whose TimeTrack write flushes a pending
 * change of the application reports it once (nothing the probe remembered survives the request); the application's
 * own flushes of TimeTrack rows, and of its rows pointing at a TimeTrack instance, name the class and what to use
 * instead; the bundle's own paths report nothing; a host mode write is unchanged in 1.x.
 */
final class HostFlushProbeTest extends FunctionalTestCase
{
    private const ROUNDS = 5;

    /**
     * @param array<string, mixed> $options
     */
    protected static function createKernel(array $options = []): KernelInterface
    {
        return new FunctionalKernel(self::timeTrackConfig(), false, false, false);
    }

    /**
     * Explicitly the shared manager (a test's configuration wins over TIME_TRACK_TEST_PRIVATE_ENTITY_MANAGER).
     *
     * @return array<string, mixed>
     */
    protected static function timeTrackConfig(): array
    {
        return ['database' => ['private_entity_manager' => false]];
    }

    public function testEachRequestReportsTheApplicationsPendingChangeAgain(): void
    {
        $host = $this->entityManager();
        $seen = $this->capture(function () use ($host): void {
            for ($round = 0; $round < self::ROUNDS; ++$round) {
                $host->persist(new AppUser(sprintf('pending-%d@example.com', $round), 'secret'));
                $this->call('start', ['taskId' => 'task-1'], Response::HTTP_CREATED);
                $this->call('stop');
            }
        });

        $probe = $this->matching($seen, 'Your pending changes to ' . AppUser::class . ' were flushed by NowoTimeTrackBundle; 2.0 no longer does.');
        self::assertCount(self::ROUNDS, $probe, 'one notice per request: the probe forgets at every request boundary');
        self::assertSame([], $this->bundleNotices($seen, AppUser::class), 'the bundle\'s own code paths trigger none of the 1.33 notices');
        self::assertSame(self::ROUNDS, (int) $host->getConnection()->fetchOne("SELECT COUNT(*) FROM app_user WHERE email LIKE 'pending-%'"), 'host mode is unchanged in 1.x: the TimeTrack write still flushed them');
    }

    public function testTheApplicationsOwnWritesOfTimeTrackRowsNameTheOperationToUseInstead(): void
    {
        $host  = $this->entityManager();
        $entry = new TimeEntry($this->user, 'task-1', 'Task one', null, new DateTimeImmutable('-2 hours'), new DateTimeImmutable('-1 hour'), 3600, TimeEntrySource::Manual);
        $seen  = $this->capture(static function () use ($host, $entry): void {
            $host->persist($entry);
            $host->flush();
            $host->persist(new EntryNote($entry));
            $host->flush();
            $host->persist(new EntryNote($entry, 'again')); // same unit of work: not reported twice
            $host->flush();
        });

        $rows = $this->matching($seen, 'Your application inserted ' . TimeEntry::class . ' rows with its own entity manager');
        self::assertCount(1, $rows);
        self::assertStringContainsString(ManualEntryService::class . '::create()', $rows[0]);
        self::assertStringContainsString('"nowo_time_track.database.private_entity_manager: true"', $rows[0]);
        $references = $this->matching($seen, 'Your application inserted ' . EntryNote::class . ' rows that point at a ' . TimeEntry::class . ' instance ("entry")');
        self::assertCount(1, $references);
        self::assertStringContainsString('getReference(' . TimeEntry::class . '::class, $id)', $references[0]);
        self::assertCount(2, $seen, 'nothing else: ' . json_encode($seen));

        // The next request is a new unit of work: the same write is reported again.
        $this->call('current');
        $again = $this->capture(static function () use ($host, $entry): void {
            $host->remove($entry);
            $host->flush();
        });
        self::assertCount(1, $this->matching($again, 'Your application deleted ' . TimeEntry::class . ' rows with its own entity manager'));
    }

    public function testOnlyChangedReferencesToTimeTrackRowsAreReported(): void
    {
        $host  = $this->entityManager();
        $entry = new TimeEntry($this->user, 'task-1', 'Task one', null, new DateTimeImmutable('-2 hours'), new DateTimeImmutable('-1 hour'), 3600, TimeEntrySource::Manual);
        $host->persist($entry);
        $host->flush();
        $this->call('current'); // a new unit of work
        $note = new EntryNote(null, 'no entry', $this->user);
        $seen = $this->capture(static function () use ($host, $note, $entry): void {
            $host->persist($note); // no TimeTrack row referenced (and a reference to an application user)
            $host->flush();
            $note->text = 'renamed'; // an update that does not touch the reference
            $host->flush();
            $note->entry = $entry; // an update that does
            $host->flush();
        });

        self::assertSame([], $this->matching($seen, 'inserted ' . EntryNote::class));
        self::assertCount(1, $this->matching($seen, 'Your application updated ' . EntryNote::class . ' rows that point at a ' . TimeEntry::class . ' instance ("entry")'));
        self::assertCount(1, $seen, json_encode($seen, JSON_THROW_ON_ERROR));
    }

    public function testADeprecationHandlerThatThrowsNeverStopsTheApplicationsFlush(): void
    {
        $host  = $this->entityManager();
        $entry = new TimeEntry($this->user, 'task-1', 'Task one', null, new DateTimeImmutable('-2 hours'), new DateTimeImmutable('-1 hour'), 3600, TimeEntrySource::Manual);
        // @phpstan-ignore frankenphp.worker.noSetErrorExceptionHandler (test only: restored in the finally block below)
        set_error_handler(static function (): bool {
            throw new RuntimeException('deprecations are errors here');
        }, E_USER_DEPRECATED);
        try {
            $host->persist($entry);
            $host->flush();
        } finally {
            restore_error_handler();
        }

        self::assertSame(1, (int) $host->getConnection()->fetchOne('SELECT COUNT(*) FROM time_track_entries WHERE id = ?', [$entry->getId()]));
    }

    public function testADeprecationHandlerThatThrowsNeverStopsAWrite(): void
    {
        $host = $this->entityManager();
        $host->persist(new AppUser('thrown@example.com', 'secret'));
        // @phpstan-ignore frankenphp.worker.noSetErrorExceptionHandler (test only: restored in the finally block below)
        set_error_handler(static function (): bool {
            throw new RuntimeException('deprecations are errors here');
        }, E_USER_DEPRECATED);
        try {
            $this->call('start', ['taskId' => 'task-1'], Response::HTTP_CREATED);
        } finally {
            restore_error_handler();
        }

        self::assertSame(1, (int) $host->getConnection()->fetchOne("SELECT COUNT(*) FROM app_user WHERE email = 'thrown@example.com'"));
        self::assertSame(1, (int) $host->getConnection()->fetchOne('SELECT COUNT(*) FROM time_track_active_timers'));
    }

    /**
     * @param list<string> $seen
     *
     * @return list<string>
     */
    private function matching(array $seen, string $needle): array
    {
        return array_values(array_filter($seen, static fn (string $message): bool => str_contains($message, $needle)));
    }

    /**
     * The 1.33 probe notices that do not name the test's own host class.
     *
     * @param list<string> $seen
     *
     * @return list<string>
     */
    private function bundleNotices(array $seen, string $hostClass): array
    {
        return array_values(array_filter($seen, static fn (string $message): bool => !str_contains($message, $hostClass) && (str_contains($message, 'flushed by NowoTimeTrackBundle') || str_contains($message, 'with its own entity manager') || str_contains($message, 'rows that point at a'))));
    }

    /**
     * @return list<string> the deprecations triggered while `$work` ran
     */
    private function capture(callable $work): array
    {
        $seen = [];
        // @phpstan-ignore frankenphp.worker.noSetErrorExceptionHandler (test only: restored in the finally block below)
        set_error_handler(static function (int $level, string $message) use (&$seen): bool {
            $seen[] = $message;

            return true;
        }, E_USER_DEPRECATED);
        try {
            $work();
        } finally {
            restore_error_handler();
        }

        return $seen;
    }

    /**
     * One request straight through the container's `http_kernel` (no services resetter); asserts the status.
     *
     * @param array<string, mixed> $payload
     *
     * @return array<mixed>
     */
    private function call(string $action, array $payload = [], int $status = Response::HTTP_OK): array
    {
        $kernel = self::getContainer()->get('http_kernel');
        assert($kernel instanceof HttpKernelInterface && $kernel instanceof TerminableInterface);
        $request  = Request::create('/host-timer/' . $action, 'POST', [], [], [], ['HTTP_X_TEST_TOKEN' => 'bt_person@example.com', 'CONTENT_TYPE' => 'application/json'], json_encode($payload, JSON_THROW_ON_ERROR));
        $response = $kernel->handle($request);
        $kernel->terminate($request, $response);
        self::assertSame($status, $response->getStatusCode(), $action . ': ' . $response->getContent());
        $body = json_decode((string) $response->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertTrue(is_array($body));

        return $body;
    }
}
