<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Tests\Functional;

use Closure;
use DateTimeImmutable;
use Doctrine\ORM\Event\OnFlushEventArgs;
use Nowo\TimeTrackBundle\Client\ClientCredentialIssuerInterface;
use Nowo\TimeTrackBundle\Controller\TimeTrackCalendarApiController;
use Nowo\TimeTrackBundle\Entity\ActiveTimer;
use Nowo\TimeTrackBundle\Entity\DeviceAuthorization;
use Nowo\TimeTrackBundle\Entity\TimeEntry;
use Nowo\TimeTrackBundle\Enum\ClientScope;
use Nowo\TimeTrackBundle\Enum\ClientType;
use Nowo\TimeTrackBundle\Enum\IdleResolution;
use Nowo\TimeTrackBundle\Event\TimeEntryCreatedEvent;
use Nowo\TimeTrackBundle\Event\TimeTrackEvents;
use Nowo\TimeTrackBundle\Exception\ActiveTimerConflictException;
use Nowo\TimeTrackBundle\Exception\DeviceAuthorizationNotPendingException;
use Nowo\TimeTrackBundle\Integration\UserReferenceResolverInterface;
use Nowo\TimeTrackBundle\Monitoring\MonitoringAcknowledgement;
use Nowo\TimeTrackBundle\Port\TimeTrackUserRefFactory;
use Nowo\TimeTrackBundle\Repository\DoctrineOrmMonitoringAcknowledgementStore;
use Nowo\TimeTrackBundle\Service\ClientAuthService;
use Nowo\TimeTrackBundle\Service\DeviceAuthorizationService;
use Nowo\TimeTrackBundle\Service\TimerService;
use Nowo\TimeTrackBundle\TaskMove\TaskTimeMover;
use Nowo\TimeTrackBundle\Tests\Stub\HostFlushListener;
use Nowo\TimeTrackBundle\Tests\Stub\StubServiceAccountPolicy;
use Nowo\TimeTrackBundle\Tests\Support\HookClock;
use Nowo\TimeTrackBundle\Tests\Support\ServiceCopy;
use Nowo\TimeTrackBundle\ValueObject\Uuid;
use PHPUnit\Framework\Attributes\Group;
use RuntimeException;
use Symfony\Component\Clock\NativeClock;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\User\UserInterface;

use function count;
use function json_decode;
use function json_encode;

use const DATE_ATOM;
use const JSON_THROW_ON_ERROR;

/**
 * 1.27.0 (Beacon spec 239): the races of TimeTrack's write paths, each played deterministically (a hook writes what a
 * concurrent request commits at the worst moment). Every test fails on 1.26.0 and passes with the fixes. Runs on SQLite
 * and, in the functional and "Load readiness" CI jobs, on MySQL and PostgreSQL.
 *
 * The shared-manager tests show the class of bug the runner removes: up to 1.26 a lost race closed the entity manager
 * and the bundle reset it, the APPLICATION's manager by default, in the middle of the request, so every entity the
 * application had loaded was detached and its later changes were silently never written.
 */
final class LoadReadinessRaceTest extends FunctionalTestCase
{
    protected static function timeTrackConfig(): array
    {
        return ['manual_entries' => ['enabled' => true], 'service_account_policy' => StubServiceAccountPolicy::class];
    }

    /**
     * R1: a concurrent start commits its timer between this start's check and its insert. Up to 1.26 the insert failed
     * on the unique `user_id` index, closed the manager and reset the application's manager (its user detached).
     */
    #[Group('shared-entity-manager')]
    public function testAConcurrentStartIsRefusedWithoutResettingTheApplicationsManager(): void
    {
        $clock = new HookClock(new NativeClock());
        $timer = ServiceCopy::with($this->timers(), ['clock' => $clock]);
        $clock->arm([1 => fn () => $this->insertTimer('task-3')]);

        try {
            $timer->start($this->user, 'task-1', ClientType::Desktop);
            self::fail('The concurrent start won: this one must be refused.');
        } catch (ActiveTimerConflictException) {
        }

        self::assertTrue($this->entityManager()->isOpen());
        self::assertTrue($this->entityManager()->contains($this->user), 'the application\'s user stays managed');
        self::assertSame('task-3', $this->timers()->getActive($this->user)?->getTaskId());
    }

    /**
     * R2 (FR-008, time follows the moved task): the task moves to another board after the stop loaded the timer, before
     * it records the entry. Up to 1.26 the entry kept the old board (built from the timer loaded before the delete).
     */
    public function testAStopRecordsTheBoardTheTaskMovedToMeanwhile(): void
    {
        $this->timers()->start($this->user, 'task-1', ClientType::Desktop);
        $mover         = self::getContainer()->get(TaskTimeMover::class);
        $policy        = $this->policy();
        $policy->onAsk = static function () use ($mover): void {
            self::assertInstanceOf(TaskTimeMover::class, $mover);
            $mover->moveTasks(['task-1'], 'board-9', null);
        };

        $entry = $this->timers()->stop($this->user);

        self::assertInstanceOf(TimeEntry::class, $entry);
        self::assertSame('board-9', $entry->getBoardIdSnapshot(), 'the entry is recorded where the task is now');
        self::assertSame('board-9', $this->entityManager()->getConnection()->fetchOne('SELECT board_id_snapshot FROM time_track_entries WHERE id = ?', [$entry->getId()]));
    }

    /**
     * R6: two notes kept on the running timer at the same time. Up to 1.26 the second write put back the metadata it had
     * loaded before the first one committed: the first note was lost.
     */
    public function testTwoNotesKeptAtOnceAreBothStored(): void
    {
        $start = (new DateTimeImmutable())->modify('-20 minutes');
        $timer = $this->timers()->startAt($this->user, 'task-1', ClientType::Desktop, [], $start)['timer'];
        // Another request kept its note first (committed); this request still holds the timer it loaded.
        $this->entityManager()->getConnection()->update('time_track_active_timers', ['metadata' => json_encode(['note' => 'first'], JSON_THROW_ON_ERROR)], ['id' => $timer->getId()]);

        $this->timers()->resolveIdle($this->user, $start->modify('+1 minute'), $start->modify('+5 minutes'), IdleResolution::Keep, note: 'second');

        $stored = json_decode((string) $this->entityManager()->getConnection()->fetchOne('SELECT metadata FROM time_track_active_timers WHERE id = ?', [$timer->getId()]), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($stored);
        self::assertStringContainsString('first', (string) ($stored['note'] ?? ''));
        self::assertStringContainsString('second', (string) ($stored['note'] ?? ''));
    }

    /**
     * Audit QO-I4: a heartbeat writes its idle flag on the timer as committed: a note kept meanwhile by another request
     * stays (the heartbeat rewrote the metadata it had loaded before).
     */
    public function testAHeartbeatKeepsANoteKeptMeanwhile(): void
    {
        $timer = $this->timers()->start($this->user, 'task-1', ClientType::Desktop);
        $this->entityManager()->getConnection()->update('time_track_active_timers', ['metadata' => json_encode(['note' => 'kept'], JSON_THROW_ON_ERROR)], ['id' => $timer->getId()]);

        $beat = $this->timers()->heartbeat($this->user, true);

        self::assertInstanceOf(ActiveTimer::class, $beat);
        $stored = json_decode((string) $this->entityManager()->getConnection()->fetchOne('SELECT metadata FROM time_track_active_timers WHERE id = ?', [$timer->getId()]), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($stored);
        self::assertSame('kept', $stored['note'] ?? null);
        self::assertTrue($stored['isIdle'] ?? null);
    }

    public function testANoteKeptOnATimerStoppedMeanwhileWritesNothing(): void
    {
        $start  = (new DateTimeImmutable())->modify('-20 minutes');
        $timer  = $this->timers()->startAt($this->user, 'task-1', ClientType::Desktop, [], $start)['timer'];
        $clock  = new HookClock(new NativeClock());
        $timers = ServiceCopy::with($this->timers(), ['clock' => $clock]);
        // A concurrent stop deletes the timer after this call loaded it, before its note is written.
        $clock->arm([1 => fn (): int|string => $this->entityManager()->getConnection()->delete('time_track_active_timers', ['id' => $timer->getId()])]);

        self::assertNull($timers->resolveIdle($this->user, $start->modify('+1 minute'), $start->modify('+5 minutes'), IdleResolution::Keep, note: 'late'));
        self::assertSame(0, (int) $this->entityManager()->getConnection()->fetchOne('SELECT COUNT(*) FROM time_track_active_timers'));
    }

    /**
     * Since 1.27.0: an answer stored inside a transaction whose commit then failed is dropped, so a retry with the same
     * key writes the entry instead of replaying a 201 for an entry that does not exist.
     */
    #[Group('shared-entity-manager')]
    public function testAnAnswerOfAFailedCommitIsNeverReplayed(): void
    {
        $token   = $this->entriesToken($this->user);
        $start   = (new DateTimeImmutable('today -2 days'))->setTime(9, 0);
        $payload = ['taskId' => 'task-1', 'startedAt' => $start->format(DATE_ATOM), 'endedAt' => $start->modify('+1 hour')->format(DATE_ATOM), 'source' => 'manual'];
        $host    = self::getContainer()->get(HostFlushListener::class);
        self::assertInstanceOf(HostFlushListener::class, $host);
        // The flush that ends the unit of work (after the entry and its answer were written) fails.
        $host->onFlushOnce = static function (OnFlushEventArgs $args): bool {
            $unitOfWork = $args->getObjectManager()->getUnitOfWork();
            if ($unitOfWork->getScheduledEntityInsertions() !== [] || (int) $args->getObjectManager()->getConnection()->fetchOne('SELECT COUNT(*) FROM time_track_entries') === 0) {
                return false;
            }

            throw new RuntimeException('commit failed');
        };
        $this->client->catchExceptions(false);
        try {
            $this->api('POST', '/api/time-track/entries', $payload, $token, ['HTTP_IDEMPOTENCY_KEY' => 'failed-1']);
            self::fail('The failure reaches the kernel.');
        } catch (RuntimeException $exception) {
            self::assertSame('commit failed', $exception->getMessage());
        } finally {
            $this->client->catchExceptions(true);
        }
        self::assertSame(0, (int) $this->entityManager()->getConnection()->fetchOne('SELECT COUNT(*) FROM time_track_entries'));

        $retry = $this->api('POST', '/api/time-track/entries', $payload, $token, ['HTTP_IDEMPOTENCY_KEY' => 'failed-1']);
        self::assertResponseStatusCodeSame(201);
        self::assertIsArray($retry);
        self::assertSame(1, (int) $this->entityManager()->getConnection()->fetchOne('SELECT COUNT(*) FROM time_track_entries'));
    }

    /**
     * R4: a request with the same `Idempotency-Key` gets the user's lock right after the first one committed, before it
     * stored its answer. Up to 1.26 an agent's retry wrote a second entry (a person's answered `overlap` and stored it).
     */
    public function testARetryRightAfterTheCommitIsReplayed(): void
    {
        $agent   = $this->createUser('agent-sync@example.com');
        $token   = $this->entriesToken($agent);
        $start   = (new DateTimeImmutable('today -2 days'))->setTime(9, 0);
        $payload = ['taskId' => 'task-1', 'startedAt' => $start->format(DATE_ATOM), 'endedAt' => $start->modify('+1 hour')->format(DATE_ATOM), 'source' => 'manual'];
        $retry   = null;
        $this->dispatcher()->addListener(TimeTrackEvents::TIME_ENTRY_CREATED, static function (TimeEntryCreatedEvent $event) use (&$retry, $token, $payload): void {
            if ($retry instanceof Response) {
                return;
            }
            $retry      = 'running';
            $controller = self::getContainer()->get(TimeTrackCalendarApiController::class);
            self::assertInstanceOf(TimeTrackCalendarApiController::class, $controller);
            $retry = $controller->createEntry(Request::create('/api/time-track/entries', 'POST', [], [], [], ['HTTP_AUTHORIZATION' => 'Bearer ' . $token, 'HTTP_IDEMPOTENCY_KEY' => 'retry-1', 'CONTENT_TYPE' => 'application/json'], json_encode($payload, JSON_THROW_ON_ERROR)));
        });

        $first = $this->api('POST', '/api/time-track/entries', $payload, $token, ['HTTP_IDEMPOTENCY_KEY' => 'retry-1']);
        self::assertResponseStatusCodeSame(201);

        self::assertInstanceOf(Response::class, $retry);
        $replayed = json_decode((string) $retry->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(201, $retry->getStatusCode());
        self::assertIsArray($first);
        self::assertIsArray($replayed);
        self::assertSame($first['entry']['id'] ?? null, $replayed['entry']['id'] ?? 'none', 'the retry replays the first answer');
        self::assertSame(1, (int) $this->entityManager()->getConnection()->fetchOne('SELECT COUNT(*) FROM time_track_entries'));
    }

    /**
     * R5: two approvals of one device request (a double submit, another tab). Up to 1.26 each issued a credential and the
     * later one overwrote the earlier one's id: a credential stayed issued with no device ever receiving it.
     */
    public function testASecondApprovalOfTheSameRequestIssuesNothing(): void
    {
        $issued  = [];
        $devices = self::getContainer()->get(DeviceAuthorizationService::class);
        self::assertInstanceOf(DeviceAuthorizationService::class, $devices);
        $issuer = new class($issued) implements ClientCredentialIssuerInterface {
            /** @param list<string> $issued */
            public function __construct(private array &$issued)
            {
            }

            public function issueForDevice(UserInterface $user, ClientType $clientType, string $clientName, array $scopes): string
            {
                $this->issued[] = $id = 'credential-' . (count($this->issued) + 1);

                return $id;
            }
        };
        $devices = ServiceCopy::with($devices, ['credentialIssuer' => $issuer]);
        $grant   = $devices->start(ClientType::Desktop, 'Laptop');
        $request = $this->entityManager()->getRepository(DeviceAuthorization::class)->findOneBy(['deviceCodeHash' => DeviceAuthorizationService::hash($grant->deviceCode)]);
        self::assertInstanceOf(DeviceAuthorization::class, $request);
        $loadedByTheOtherTab = $request;

        self::assertSame('credential-1', $devices->approve($request, $this->user));
        try {
            $devices->approve($loadedByTheOtherTab, $this->user);
            self::fail('The request is no longer pending.');
        } catch (DeviceAuthorizationNotPendingException) {
        }
        try {
            $devices->deny($loadedByTheOtherTab, $this->user);
            self::fail('The request is no longer pending.');
        } catch (DeviceAuthorizationNotPendingException) {
        }

        self::assertSame(['credential-1'], $issued, 'one credential, the one the device gets');
        self::assertSame('credential-1', $this->entityManager()->getConnection()->fetchOne('SELECT credential_id FROM time_track_device_codes WHERE id = ?', [$request->getId()]));
    }

    /**
     * R8 (spec 199): a concurrent request stores the same acknowledgement between this one's check and its insert. Up to
     * 1.26 the insert failed on the unique key, closed the manager and reset the application's manager.
     */
    #[Group('shared-entity-manager')]
    public function testAConcurrentAcknowledgementNeverResetsTheApplicationsManager(): void
    {
        $references = self::getContainer()->get(UserReferenceResolverInterface::class);
        self::assertInstanceOf(UserReferenceResolverInterface::class, $references);
        $racing = new class($references, fn () => $this->insertAcknowledgement(3)) implements UserReferenceResolverInterface {
            public function __construct(private readonly UserReferenceResolverInterface $inner, private ?Closure $race)
            {
            }

            public function reference(UserInterface $user): object
            {
                $race       = $this->race;
                $this->race = null;
                if ($race instanceof Closure) {
                    $race();
                }

                return $this->inner->reference($user);
            }
        };
        $store = new DoctrineOrmMonitoringAcknowledgementStore($this->entityManager(), $racing);

        $store->acknowledge(TimeTrackUserRefFactory::build($this->user), new MonitoringAcknowledgement(3, new DateTimeImmutable(), 'desktop', str_repeat('a', 64)));

        self::assertTrue($this->entityManager()->isOpen());
        self::assertTrue($this->entityManager()->contains($this->user), 'the application\'s user stays managed');
        self::assertSame(1, (int) $this->entityManager()->getConnection()->fetchOne('SELECT COUNT(*) FROM time_track_monitoring_acknowledgements'), 'the first record is kept');
        self::assertSame(3, $store->lastAcknowledged(TimeTrackUserRefFactory::build($this->user))?->policyVersion);
    }

    private function timers(): TimerService
    {
        $timers = self::getContainer()->get(TimerService::class);
        self::assertInstanceOf(TimerService::class, $timers);

        return $timers;
    }

    private function policy(): StubServiceAccountPolicy
    {
        $policy = self::getContainer()->get(StubServiceAccountPolicy::class);
        self::assertInstanceOf(StubServiceAccountPolicy::class, $policy);

        return $policy;
    }

    private function dispatcher(): EventDispatcherInterface
    {
        $dispatcher = self::getContainer()->get('event_dispatcher');
        self::assertInstanceOf(EventDispatcherInterface::class, $dispatcher);

        return $dispatcher;
    }

    private function entriesToken(UserInterface $user): string
    {
        $auth = self::getContainer()->get(ClientAuthService::class);
        self::assertInstanceOf(ClientAuthService::class, $auth);

        return (string) $auth->issueToken($user, ClientType::Desktop, null, [ClientScope::EntriesWrite])['token'];
    }

    /**
     * A concurrent request's committed timer of the user (written beside this request's unit of work).
     */
    private function insertTimer(string $taskId): void
    {
        $now = (new DateTimeImmutable())->format('Y-m-d H:i:s');
        $this->entityManager()->getConnection()->insert('time_track_active_timers', [
            'id'                  => Uuid::generate()->toString(),
            'user_id'             => $this->user->getId(),
            'task_id'             => $taskId,
            'task_title_snapshot' => 'Racer',
            'client_type'         => 'desktop',
            'started_at'          => $now,
            'last_heartbeat_at'   => $now,
        ]);
    }

    /**
     * A concurrent request's committed acknowledgement of the user.
     */
    private function insertAcknowledgement(int $version): void
    {
        $this->entityManager()->getConnection()->insert('time_track_monitoring_acknowledgements', [
            'id'              => Uuid::generate()->toString(),
            'user_id'         => $this->user->getId(),
            'policy_version'  => $version,
            'acknowledged_at' => (new DateTimeImmutable())->format('Y-m-d H:i:s'),
            'client_type'     => 'desktop',
            'notice_sha256'   => str_repeat('b', 64),
        ]);
    }
}
