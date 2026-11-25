<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Tests\Functional;

use DateTimeImmutable;
use Nowo\TimeTrackBundle\Enum\ClientScope;
use Nowo\TimeTrackBundle\Enum\ClientType;
use Nowo\TimeTrackBundle\Service\ClientAuthService;
use Nowo\TimeTrackBundle\Service\TimerService;
use Nowo\TimeTrackBundle\Tests\Fixtures\FunctionalEntity\AppUser;
use Nowo\TimeTrackBundle\Tests\Stub\StubTimesheetApproverProvider;
use Nowo\TimeTrackBundle\Tests\Support\CustomerTestSubjectProvider;
use Nowo\TimeTrackBundle\Tests\Support\FixedScopeProvider;
use Nowo\TimeTrackBundle\Tests\Support\FunctionalKernel;
use Symfony\Component\HttpKernel\KernelInterface;

use function array_values;

use const DATE_ATOM;

/**
 * Since 2.1.0 (Beacon spec 256, §7.3): `entries.require_allocation` and `entries.require_activity_type` on the client API
 * and the web UI — interactive stops of time that misses them are refused (`409` with `{timer}`, a flash on the web) and
 * the timer keeps running; a clearing that would leave the time unallocated is refused; the reports page shows the
 * subject column with the hidden label.
 */
final class AllocationRequiredClientApiTest extends FunctionalTestCase
{
    /**
     * @param array<string, mixed> $options
     */
    protected static function createKernel(array $options = []): KernelInterface
    {
        return new FunctionalKernel(self::timeTrackConfig(), allocationProviders: true);
    }

    protected static function timeTrackConfig(): array
    {
        return [
            'timesheets'     => ['enabled' => true, 'scope_provider' => FixedScopeProvider::class, 'approver_provider' => StubTimesheetApproverProvider::class],
            'manual_entries' => ['enabled' => true],
            'client_api'     => ['rate_limit' => ['per_token' => 0]],
            'entries'        => ['allow_without_task' => true, 'require_allocation' => true, 'require_activity_type' => true],
        ];
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->customers()->reset();
        $this->customers()->loggable = ['c-1', 'c-9'];
        $this->customers()->readable = ['c-1'];
        $scopes                      = self::getContainer()->get(FixedScopeProvider::class);
        self::assertInstanceOf(FixedScopeProvider::class, $scopes);
        $scopes->memberships = [(string) $this->user->getId() => ['org-a']];
    }

    protected function tearDown(): void
    {
        $this->customers()->reset();
        parent::tearDown();
    }

    public function testInteractiveStopsOfUnallocatedTimeAreRefusedAndTheTimerKeepsRunning(): void
    {
        $token = $this->token(ClientScope::TimerWrite, ClientScope::TimerRead);
        $me    = $this->api('GET', '/api/time-track/me', null, $token);
        self::assertSame([true, true], [$me['features']['requireAllocation'] ?? null, $me['features']['requireActivityType'] ?? null]);

        $neither = $this->api('POST', '/api/time-track/timer/start', [], $token);
        self::assertSame([400, 'invalid_request'], [$this->client->getResponse()->getStatusCode(), $neither['error'] ?? null]);

        // A timer the host started without a task nor a subject (a system path is never refused at start).
        $timer   = $this->timers()->start($this->managedUser(), null, ClientType::Web);
        $endedAt = (new DateTimeImmutable('-1 second'))->format(DATE_ATOM);
        $refused = $this->api('POST', '/api/time-track/timer/stop', ['endedAt' => $endedAt], $token);
        self::assertResponseStatusCodeSame(409);
        self::assertSame(['allocation_required', $timer->getId(), null], [$refused['error'] ?? null, $refused['timer']['id'] ?? null, $this->field($refused, 'timer', 'subject')]);
        self::assertSame($timer->getId(), $this->api('GET', '/api/time-track/timer', null, $token)['timer']['id'] ?? null, 'nothing written: the timer keeps running');

        // A subject but no activity type.
        $missing = $this->api('POST', '/api/time-track/timer/stop', ['subject' => ['type' => 'customer', 'id' => 'c-1']], $token);
        self::assertSame([409, 'activity_type_required'], [$this->client->getResponse()->getStatusCode(), $missing['error'] ?? null]);
        self::assertIsArray($missing);
        self::assertArrayHasKey('timer', $missing);

        $stop = $this->api('POST', '/api/time-track/timer/stop', ['subject' => ['type' => 'customer', 'id' => 'c-1'], 'activityTypeKey' => 'dev', 'endedAt' => $endedAt], $token);
        self::assertResponseIsSuccessful();
        self::assertSame(['c-1', 'dev'], [$stop['entry']['subject']['id'] ?? null, $stop['entry']['activityType']['key'] ?? null]);
        $this->api('GET', '/api/time-track/timer', null, $token);
        self::assertResponseStatusCodeSame(204);
    }

    public function testClearingASubjectAloneTimerIsRefused(): void
    {
        $token = $this->token(ClientScope::TimerWrite);
        $this->api('POST', '/api/time-track/timer/start', ['subject' => ['type' => 'customer', 'id' => 'c-1'], 'activityTypeKey' => 'dev'], $token);
        self::assertResponseStatusCodeSame(201);

        $refused = $this->api('POST', '/api/time-track/timer/allocate', ['subject' => null], $token);
        self::assertSame([409, 'allocation_required'], [$this->client->getResponse()->getStatusCode(), $refused['error'] ?? null]);
        self::assertIsArray($refused);
        self::assertArrayNotHasKey('timer', $refused);
        $refused = $this->api('POST', '/api/time-track/timer/stop', ['subject' => null], $token);
        self::assertSame([409, 'allocation_required'], [$this->client->getResponse()->getStatusCode(), $refused['error'] ?? null]);
        self::assertSame('c-1', $refused['timer']['subject']['id'] ?? null);

        // A manual entry misses its activity type: refused like an interactive stop.
        $start   = (new DateTimeImmutable('today -2 days'))->setTime(9, 0);
        $missing = $this->api('POST', '/api/time-track/entries', ['subject' => ['type' => 'customer', 'id' => 'c-1'], 'startedAt' => $start->format(DATE_ATOM), 'endedAt' => $start->modify('+1 hour')->format(DATE_ATOM), 'source' => 'manual'], $this->token(ClientScope::EntriesWrite), ['HTTP_IDEMPOTENCY_KEY' => 'r-1']);
        self::assertSame([422, 'activity_type_required'], [$this->client->getResponse()->getStatusCode(), $missing['error'] ?? null]);
    }

    public function testTheWebStopShowsTheRefusalAndTheTimerKeepsRunning(): void
    {
        $timer = $this->timers()->start($this->managedUser(), null, ClientType::Web);
        $this->client->loginUser($this->user, 'main');
        $crawler = $this->client->request('GET', '/tools/time-track');
        $this->client->submit($crawler->selectButton('Stop')->form());
        $this->client->followRedirect();

        self::assertSelectorTextContains('.nowo-ui-flash--warning', 'Allocate this time to a task or a subject before you stop the timer.');
        self::assertSame($timer->getId(), $this->timers()->getActive($this->managedUser())?->getId());
    }

    public function testTheReportsPageShowsTheSubjectColumnWithTheHiddenLabel(): void
    {
        $token = $this->token(ClientScope::EntriesWrite);
        foreach ([['c-1', 'dev', 2], ['c-9', 'meeting', 3]] as [$id, $key, $daysAgo]) {
            $start = (new DateTimeImmutable('today -' . $daysAgo . ' days'))->setTime(9, 0);
            $this->api('POST', '/api/time-track/entries', ['subject' => ['type' => 'customer', 'id' => $id], 'activityTypeKey' => $key, 'startedAt' => $start->format(DATE_ATOM), 'endedAt' => $start->modify('+1 hour')->format(DATE_ATOM), 'source' => 'manual'], $token, ['HTTP_IDEMPOTENCY_KEY' => 'w-' . $id]);
            self::assertResponseStatusCodeSame(201);
        }
        $this->client->loginUser($this->user, 'main');
        $crawler = $this->client->request('GET', '/tools/time-track/reports?from=' . (new DateTimeImmutable('-7 days'))->format('Y-m-d'));
        self::assertResponseIsSuccessful();

        $cells = $crawler->filter('[data-testid="time-track-subject"]');
        self::assertCount(2, $cells);
        self::assertStringContainsString('customer c-1', $cells->eq(0)->text());
        self::assertSame('Development', $cells->eq(0)->filter('[data-testid="time-track-activity-type"]')->text());
        self::assertSame('Subject not visible', $cells->eq(1)->filter('[data-testid="time-track-subject-hidden"]')->text());
        self::assertStringNotContainsString('customer c-9', (string) $this->client->getResponse()->getContent());
        self::assertSame((string) $this->user->getId(), $this->customers()->describeCalls[0]['actor'] ?? null, 'labelled with the viewer as actor');
    }

    /**
     * The value at the path of a decoded body (which must hold it, null included).
     */
    private function field(mixed $body, string ...$path): mixed
    {
        foreach ($path as $key) {
            self::assertIsArray($body);
            self::assertArrayHasKey($key, $body);
            $body = $body[$key];
        }

        return $body;
    }

    /**
     * The `customer` provider of the kernel.
     */
    private function customers(): CustomerTestSubjectProvider
    {
        $provider = self::getContainer()->get(CustomerTestSubjectProvider::class);
        self::assertInstanceOf(CustomerTestSubjectProvider::class, $provider);

        return $provider;
    }

    /**
     * The bundle's timer service.
     */
    private function timers(): TimerService
    {
        $timers = self::getContainer()->get(TimerService::class);
        self::assertInstanceOf(TimerService::class, $timers);

        return $timers;
    }

    /**
     * The signed-up user as the application's manager holds it.
     */
    private function managedUser(): AppUser
    {
        $user = $this->entityManager()->find(AppUser::class, $this->user->getId());
        self::assertInstanceOf(AppUser::class, $user);

        return $user;
    }

    /**
     * A token of the signed-up user with the scopes.
     */
    private function token(ClientScope ...$scopes): string
    {
        $user = $this->managedUser();
        $auth = self::getContainer()->get(ClientAuthService::class);
        self::assertInstanceOf(ClientAuthService::class, $auth);

        return (string) $auth->issueToken($user, ClientType::Desktop, null, array_values($scopes))['token'];
    }
}
