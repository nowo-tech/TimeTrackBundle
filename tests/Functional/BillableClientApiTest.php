<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Tests\Functional;

use DateTimeImmutable;
use Nowo\TimeTrackBundle\Entity\TimeEntry;
use Nowo\TimeTrackBundle\Enum\ClientScope;
use Nowo\TimeTrackBundle\Enum\ClientType;
use Nowo\TimeTrackBundle\Event\TimeEntryAccessCheckEvent;
use Nowo\TimeTrackBundle\Event\TimeEntryBillableChangedEvent;
use Nowo\TimeTrackBundle\Event\TimeTrackEvents;
use Nowo\TimeTrackBundle\Import\ImportedTimeEntryData;
use Nowo\TimeTrackBundle\Service\ClientAuthService;
use Nowo\TimeTrackBundle\Service\TeamAccessGuard;
use Nowo\TimeTrackBundle\Service\TimeEntryBillableService;
use Nowo\TimeTrackBundle\Tests\Fixtures\FunctionalEntity\AppUser;
use Nowo\TimeTrackBundle\Tests\Stub\StubBillableDefaultProvider;
use Nowo\TimeTrackBundle\Tests\Stub\StubTimesheetApproverProvider;
use Nowo\TimeTrackBundle\Tests\Support\FixedScopeProvider;
use Nowo\TimeTrackBundle\Tests\Support\TimesheetFixtures;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Security\Core\User\UserInterface;

use function array_column;
use function array_values;

use const DATE_ATOM;

/**
 * Since 1.21 (Beacon D-20261111-12): `billable` on the client API — read on `GET entries`, written on `POST entries` and
 * `PATCH entries/{id}` under the edit and period-lock rules, audited, and the host default for new entries.
 */
final class BillableClientApiTest extends FunctionalTestCase
{
    /** @var list<TimeEntryBillableChangedEvent> */
    private array $audit = [];

    protected static function timeTrackConfig(): array
    {
        return [
            'timesheets'     => ['enabled' => true, 'scope_provider' => FixedScopeProvider::class, 'approver_provider' => StubTimesheetApproverProvider::class],
            'manual_entries' => ['enabled' => true],
            'client_api'     => ['rate_limit' => ['per_token' => 0]],
        ];
    }

    protected function setUp(): void
    {
        parent::setUp();
        $scopes = self::getContainer()->get(FixedScopeProvider::class);
        self::assertInstanceOf(FixedScopeProvider::class, $scopes);
        $scopes->memberships = [(string) $this->user->getId() => ['org-a']];
        $this->audit         = [];
        $dispatcher          = self::getContainer()->get(EventDispatcherInterface::class);
        self::assertInstanceOf(EventDispatcherInterface::class, $dispatcher);
        $dispatcher->addListener(TimeTrackEvents::TIME_ENTRY_BILLABLE_CHANGED, function (TimeEntryBillableChangedEvent $event): void {
            $this->audit[] = $event;
        });
    }

    protected function tearDown(): void
    {
        $this->provider()->answer = null;
        $this->provider()->calls  = [];
        parent::tearDown();
    }

    public function testNewEntriesTakeTheHostDefaultUnlessTheyGiveOne(): void
    {
        $token                    = $this->token(ClientScope::EntriesWrite);
        $this->provider()->answer = true;

        $default = $this->create($token, 4, 'd-1');
        self::assertTrue($default['billable'] ?? null);
        self::assertSame([['org-a', 'task-1']], $this->provider()->calls);
        self::assertFalse($this->create($token, 5, 'd-2', ['billable' => false])['billable'] ?? null);
        self::assertCount(1, $this->provider()->calls, 'an explicit value does not ask the host');

        $this->provider()->answer = null;
        self::assertNull($this->create($token, 6, 'd-3')['billable'], 'a null default keeps the flag unset');
        $this->api('POST', '/api/time-track/entries', $this->body(7) + ['billable' => 'yes'], $token, ['HTTP_IDEMPOTENCY_KEY' => 'd-4']);
        self::assertResponseStatusCodeSame(400);

        // Timer entries too.
        $this->provider()->answer = false;
        $this->api('POST', '/api/time-track/timer/start', ['taskId' => 'task-2'], $this->token(ClientScope::TimerWrite));
        $stopped = $this->api('POST', '/api/time-track/timer/stop', [], $this->token(ClientScope::TimerWrite));
        self::assertFalse($stopped['entry']['billable'] ?? null);
        self::assertSame([], $this->audit, 'a default is not a change');
    }

    public function testTheOwnerReadsAndChangesTheFlag(): void
    {
        $token = $this->token(ClientScope::EntriesWrite, ClientScope::EntriesRead);
        $entry = $this->create($token, 3, 'p-1');
        $id    = (string) $entry['id'];
        self::assertNull($entry['billable']);

        $changed = $this->api('PATCH', '/api/time-track/entries/' . $id, ['billable' => true], $token);
        self::assertResponseIsSuccessful();
        self::assertSame('no-store, private', $this->client->getResponse()->headers->get('Cache-Control'));
        self::assertSame([$id, true, false], [$changed['entry']['id'] ?? null, $changed['entry']['billable'] ?? null, $changed['entry']['locked'] ?? null]);
        $list = $this->api('GET', '/api/time-track/entries', null, $token);
        self::assertSame([true], array_column($list['entries'] ?? [], 'billable'));

        $this->api('PATCH', '/api/time-track/entries/' . $id, ['billable' => true], $token);
        $this->api('PATCH', '/api/time-track/entries/' . $id, ['billable' => null], $token);
        self::assertResponseIsSuccessful();
        self::assertSame([[null, true, 'api'], [true, null, 'api']], array_map(static fn (TimeEntryBillableChangedEvent $e): array => [$e->oldBillable, $e->newBillable, $e->via], $this->audit), 'one audit record per real change');
        self::assertSame([(string) $this->user->getId(), (string) $this->user->getId(), $id], [$this->audit[0]->actorId, $this->audit[0]->ownerId, $this->audit[0]->entryId]);

        foreach ([[], ['billable' => 'true'], ['billable' => 1], ['billable' => true, 'note' => 'x']] as $bad) {
            $answer = $this->api('PATCH', '/api/time-track/entries/' . $id, $bad, $token);
            self::assertResponseStatusCodeSame(400);
            self::assertSame('invalid_request', $answer['error'] ?? null);
        }
        $this->api('OPTIONS', '/api/time-track/entries/' . $id, null, null, ['HTTP_ORIGIN' => 'chrome-extension://functional']);
        self::assertResponseStatusCodeSame(204);
        self::assertSame('GET, POST, PATCH, DELETE, OPTIONS', $this->client->getResponse()->headers->get('Access-Control-Allow-Methods'));

        // 120 changes per hour per token (the earlier valid calls count).
        for ($i = 0; $i < 120; ++$i) {
            $this->api('PATCH', '/api/time-track/entries/' . $id, ['billable' => false], $token);
        }
        self::assertResponseStatusCodeSame(429);
    }

    public function testOtherUsersScopesLocksImportsAndHostDenials(): void
    {
        $token = $this->token(ClientScope::EntriesWrite);
        $id    = (string) $this->create($token, 3, 'o-1')['id'];

        $read = $this->api('PATCH', '/api/time-track/entries/' . $id, ['billable' => true], $this->token(ClientScope::EntriesRead));
        self::assertResponseStatusCodeSame(403);
        self::assertSame('insufficient_scope', $read['error'] ?? null);
        $other = $this->createUser('other@example.com');
        $this->api('PATCH', '/api/time-track/entries/' . $id, ['billable' => true], $this->tokenFor($other, ClientScope::EntriesWrite));
        self::assertResponseStatusCodeSame(404, 'another user\'s entry looks like an unknown one');
        $this->api('PATCH', '/api/time-track/entries/unknown-id', ['billable' => true], $token);
        self::assertResponseStatusCodeSame(404);

        // An imported entry is read-only.
        $start    = new DateTimeImmutable('today -2 days 09:00');
        $imported = TimeEntry::imported($this->managedUser(), new ImportedTimeEntryData('clickup:1', 'e-1', 'task-1', 'T', null, $start, $start->modify('+1 hour'), 3600, true));
        $this->entityManager()->persist($imported);
        $this->entityManager()->flush();
        self::assertSame('read_only', $this->api('PATCH', '/api/time-track/entries/' . $imported->getId(), ['billable' => false], $token)['error'] ?? null);
        self::assertResponseStatusCodeSame(409);

        // A host listener that denies the edit (403), then a locked period (409) whatever the listener says.
        $dispatcher = self::getContainer()->get(EventDispatcherInterface::class);
        self::assertInstanceOf(EventDispatcherInterface::class, $dispatcher);
        $deny = static function (TimeEntryAccessCheckEvent $event): void {
            $event->deny();
        };
        $dispatcher->addListener(TimeTrackEvents::TIME_ENTRY_ACCESS_CHECK, $deny);
        self::assertSame('forbidden', $this->api('PATCH', '/api/time-track/entries/' . $id, ['billable' => true], $token)['error'] ?? null);
        self::assertResponseStatusCodeSame(403);
        $dispatcher->removeListener(TimeTrackEvents::TIME_ENTRY_ACCESS_CHECK, $deny);

        // Audit MV-T-L3: `edit_billable` alone, on PATCH and on an explicit value of POST entries.
        $denyBillable = static function (TimeEntryAccessCheckEvent $event): void {
            if ($event->getAction() === TeamAccessGuard::ACTION_EDIT_BILLABLE) {
                $event->deny();
            }
        };
        $dispatcher->addListener(TimeTrackEvents::TIME_ENTRY_ACCESS_CHECK, $denyBillable);
        self::assertSame('forbidden', $this->api('PATCH', '/api/time-track/entries/' . $id, ['billable' => true], $token)['error'] ?? null);
        $refused = $this->api('POST', '/api/time-track/entries', ['billable' => true] + $this->body(8), $token, ['HTTP_IDEMPOTENCY_KEY' => 'o-3']);
        self::assertResponseStatusCodeSame(403);
        self::assertSame('billable_forbidden', $refused['error'] ?? null);
        self::assertNull($this->create($token, 9, 'o-4')['billable'], 'without a value nothing is asked');
        $dispatcher->removeListener(TimeTrackEvents::TIME_ENTRY_ACCESS_CHECK, $denyBillable);

        $old    = (new DateTimeImmutable('today -10 days'))->setTime(9, 0);
        $locked = $this->create($token, 10, 'o-2');
        $this->entityManager()->persist(TimesheetFixtures::locked($this->managedUser(), 'org-a', $old->format('o-\WW')));
        $this->entityManager()->flush();
        $answer = $this->api('PATCH', '/api/time-track/entries/' . $locked['id'], ['billable' => true], $token);
        self::assertResponseStatusCodeSame(409);
        self::assertSame('period_locked', $answer['error'] ?? null);
        self::assertArrayHasKey('lockedThrough', $answer);
        self::assertSame([], $this->audit);
        $this->entityManager()->clear();
        $stored = $this->entityManager()->find(TimeEntry::class, $locked['id']);
        self::assertInstanceOf(TimeEntry::class, $stored);
        self::assertNull($stored->getBillable());
    }

    public function testTheServiceIsPublicForHosts(): void
    {
        $service = self::getContainer()->get(TimeEntryBillableService::class);
        self::assertInstanceOf(TimeEntryBillableService::class, $service);
        $id = (string) $this->create($this->token(ClientScope::EntriesWrite), 2, 'h-1')['id'];

        self::assertTrue($service->changeById($this->managedUser(), $id, true)->getBillable());
        self::assertSame(['host'], array_values(array_map(static fn (TimeEntryBillableChangedEvent $e): string => $e->via, $this->audit)));
    }

    /**
     * @param array<string, mixed> $extra
     *
     * @return array<string, mixed>
     */
    private function create(string $token, int $daysAgo, string $key, array $extra = []): array
    {
        $created = $this->api('POST', '/api/time-track/entries', $extra + $this->body($daysAgo), $token, ['HTTP_IDEMPOTENCY_KEY' => $key]);
        self::assertResponseStatusCodeSame(201);
        /** @var array<string, mixed> $entry */
        $entry = $created['entry'] ?? [];

        return $entry;
    }

    /**
     * @return array<string, string>
     */
    private function body(int $daysAgo): array
    {
        $start = (new DateTimeImmutable('today -' . $daysAgo . ' days'))->setTime(9, 0);

        return ['taskId' => 'task-1', 'startedAt' => $start->format(DATE_ATOM), 'endedAt' => $start->modify('+1 hour')->format(DATE_ATOM), 'source' => 'manual'];
    }

    private function provider(): StubBillableDefaultProvider
    {
        $provider = self::getContainer()->get(StubBillableDefaultProvider::class);
        self::assertInstanceOf(StubBillableDefaultProvider::class, $provider);

        return $provider;
    }

    private function token(ClientScope ...$scopes): string
    {
        return $this->tokenFor($this->managedUser(), ...$scopes);
    }

    private function managedUser(): AppUser
    {
        $user = $this->entityManager()->find(AppUser::class, $this->user->getId());
        self::assertInstanceOf(AppUser::class, $user);

        return $user;
    }

    private function tokenFor(UserInterface $user, ClientScope ...$scopes): string
    {
        $auth = self::getContainer()->get(ClientAuthService::class);
        self::assertInstanceOf(ClientAuthService::class, $auth);

        return (string) $auth->issueToken($user, ClientType::Desktop, null, array_values($scopes))['token'];
    }
}
