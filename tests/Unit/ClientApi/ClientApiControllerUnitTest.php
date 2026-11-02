<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Tests\Unit\ClientApi;

use DateTimeImmutable;
use Nowo\TimeTrackBundle\Client\ClientAuthenticatorInterface;
use Nowo\TimeTrackBundle\Client\ClientAuthResult;
use Nowo\TimeTrackBundle\Client\ClientCredentialStateInterface;
use Nowo\TimeTrackBundle\Client\ConfigClientApiSettingsProvider;
use Nowo\TimeTrackBundle\Dto\TaskReference;
use Nowo\TimeTrackBundle\Entity\ActiveTimer;
use Nowo\TimeTrackBundle\Entity\ClientToken;
use Nowo\TimeTrackBundle\Entity\TimeEntry;
use Nowo\TimeTrackBundle\Enum\ClientType;
use Nowo\TimeTrackBundle\Enum\TimeEntrySource;
use Nowo\TimeTrackBundle\Integration\TaskProviderInterface;
use Nowo\TimeTrackBundle\Repository\ActiveTimerRepositoryInterface;
use Nowo\TimeTrackBundle\Security\TaskAccessCheckerInterface;
use Nowo\TimeTrackBundle\Tests\Stub\TestUser;
use Nowo\TimeTrackBundle\Tests\Support\ClientApiStack;
use Nowo\TimeTrackBundle\Tests\Support\InMemoryTimeEntryRepository;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

use function json_decode;
use function json_encode;

use const JSON_THROW_ON_ERROR;

/**
 * Branches of the 1.9 API that the functional tests cannot reach: inactive credential, per-IP limit on an
 * authenticated route, a provider that refuses after the checker allowed, a concurrent start during a switch, the
 * deprecated team view and allowed metadata.
 */
final class ClientApiControllerUnitTest extends TestCase
{
    private TestUser $user;

    protected function setUp(): void
    {
        $this->user = new TestUser('u-1', 'u@example.com', ['ROLE_ADMIN']);
    }

    public function testInactiveCredentialAnswersInvalidToken(): void
    {
        $state = $this->createStub(ClientCredentialStateInterface::class);
        $state->method('isActive')->willReturnCallback(static fn (ClientToken $t): bool => $t->getCredentialId() !== 'revoked');
        $stack = $this->stack(credentialState: $state);
        $token = $stack->authService->issueToken($this->user, ClientType::Desktop, 'revoked')['token'];

        $response = $stack->apiController()->me($this->request('GET', $token));

        self::assertSame(Response::HTTP_UNAUTHORIZED, $response->getStatusCode());
        self::assertSame('invalid_token', $this->body($response)['error']);
    }

    public function testPerIpLimitAppliesBeforeTheToken(): void
    {
        $stack = $this->stack(settings: new ConfigClientApiSettingsProvider([], 0, 1));
        $token = $stack->authService->issueToken($this->user, ClientType::Desktop)['token'];

        self::assertSame(200, $stack->apiController()->me($this->request('GET', $token))->getStatusCode());
        self::assertSame(429, $stack->apiController()->me($this->request('GET', $token))->getStatusCode());
    }

    public function testProviderRefusalAfterTheCheckerIsTaskForbiddenToo(): void
    {
        $allowAll = $this->createStub(TaskAccessCheckerInterface::class);
        $allowAll->method('canTrack')->willReturn(true);
        $stack      = $this->stack();
        $controller = $stack->apiController($allowAll);
        $token      = $stack->authService->issueToken($this->user, ClientType::Desktop)['token'];

        $start = $controller->timerStart($this->request('POST', $token, ['taskId' => 'foreign']));
        self::assertSame(403, $start->getStatusCode());
        self::assertSame('task_forbidden', $this->body($start)['error']);

        $switch = $controller->timerSwitch($this->request('POST', $token, ['taskId' => 'foreign']));
        self::assertSame(403, $switch->getStatusCode());
    }

    public function testConcurrentStartDuringSwitchIsTimerRunning(): void
    {
        $sticky = new class implements ActiveTimerRepositoryInterface {
            public ?ActiveTimer $timer = null;

            public function save(ActiveTimer $timer): void
            {
                $this->timer ??= $timer;
            }

            public function remove(ActiveTimer $timer): void
            {
            }

            public function findByUserId(string $userId): ?ActiveTimer
            {
                return $this->timer;
            }
        };
        $stack = $this->stack(timers: $sticky);
        $token = $stack->authService->issueToken($this->user, ClientType::Desktop)['token'];
        $stack->timerService->start($this->user, 'task-1', ClientType::Desktop);

        $response = $stack->apiController()->timerSwitch($this->request('POST', $token, ['taskId' => 'task-1']));

        self::assertSame(409, $response->getStatusCode());
        self::assertSame('timer_running', $this->body($response)['error']);
    }

    public function testDeprecatedTeamViewAndAllowedMetadata(): void
    {
        $entries = new InMemoryTimeEntryRepository();
        $other   = new TestUser('u-2', 'o@example.com');
        $entries->save(new TimeEntry($other, 'task-1', 'T', null, new DateTimeImmutable('2026-11-01 09:00'), new DateTimeImmutable('2026-11-01 10:00'), 3600, TimeEntrySource::Web));
        $stack      = $this->stack(entries: $entries);
        $controller = $stack->apiController(allowMetadata: true);
        $token      = $stack->authService->issueToken($this->user, ClientType::Desktop)['token'];

        $team = $controller->entries($this->request('GET', $token, null, '?userId=u-2'));
        self::assertCount(1, $this->body($team)['entries']);

        $start = $controller->timerStart($this->request('POST', $token, ['taskId' => 'task-1', 'metadata' => ['app' => 'tray'], 'note' => 'n']));
        self::assertSame(201, $start->getStatusCode());
        self::assertSame(['app' => 'tray', 'note' => 'n'], $this->body($start)['timer']['metadata']);
    }

    private function stack(
        ?ClientCredentialStateInterface $credentialState = null,
        ?ConfigClientApiSettingsProvider $settings = null,
        ?ActiveTimerRepositoryInterface $timers = null,
        ?InMemoryTimeEntryRepository $entries = null,
    ): ClientApiStack {
        $authenticator = $this->createStub(ClientAuthenticatorInterface::class);
        $authenticator->method('authenticate')->willReturn(ClientAuthResult::success($this->user));
        $tasks = $this->createStub(TaskProviderInterface::class);
        $tasks->method('canUserTrack')->willReturnCallback(static fn ($u, string $id): bool => $id === 'task-1');
        $tasks->method('findForUser')->willReturnCallback(static fn (string $id): ?TaskReference => $id === 'task-1' ? new TaskReference('task-1', 'Task one') : null);

        return new ClientApiStack($authenticator, $tasks, $entries, $credentialState, $settings, null, $timers);
    }

    /**
     * @param array<string, mixed>|null $payload
     */
    private function request(string $method, string $token, ?array $payload = null, string $query = ''): Request
    {
        $request = Request::create('/api' . $query, $method, [], [], [], ['REMOTE_ADDR' => '192.0.2.1'], $payload === null ? null : json_encode($payload, JSON_THROW_ON_ERROR));
        $request->headers->set('Authorization', 'Bearer ' . $token);

        return $request;
    }

    /**
     * @return array<string, mixed>
     */
    private function body(Response $response): array
    {
        /** @var array<string, mixed> $body */
        $body = json_decode((string) $response->getContent(), true, 512, JSON_THROW_ON_ERROR);

        return $body;
    }
}
