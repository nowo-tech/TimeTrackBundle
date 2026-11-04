<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Tests\Unit\ClientApi;

use ArrayObject;
use Nowo\TimeTrackBundle\Bridge\StubTaskProvider;
use Nowo\TimeTrackBundle\Client\ClientAuthenticatorInterface;
use Nowo\TimeTrackBundle\Client\ClientAuthResult;
use Nowo\TimeTrackBundle\Client\ConfigClientApiSettingsProvider;
use Nowo\TimeTrackBundle\Client\IdleCheckSettings;
use Nowo\TimeTrackBundle\Client\IdleCheckSettingsProviderInterface;
use Nowo\TimeTrackBundle\Client\IdleCheckSettingsResolver;
use Nowo\TimeTrackBundle\Controller\TimeTrackClientApiController;
use Nowo\TimeTrackBundle\Entity\ActiveTimer;
use Nowo\TimeTrackBundle\Enum\ClientType;
use Nowo\TimeTrackBundle\Enum\IdleResolution;
use Nowo\TimeTrackBundle\Event\IdleResolvedEvent;
use Nowo\TimeTrackBundle\Event\TimeTrackEvents;
use Nowo\TimeTrackBundle\Service\TimerService;
use Nowo\TimeTrackBundle\Tests\Stub\TestUser;
use Nowo\TimeTrackBundle\Tests\Support\ClientApiStack;
use Nowo\TimeTrackBundle\Tests\Support\InMemoryActiveTimerRepository;
use Nowo\TimeTrackBundle\Tests\Support\InMemoryTimeEntryRepository;
use Nowo\TimeTrackBundle\Tests\Support\RecordingLogger;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\User\UserInterface;

use function json_decode;
use function json_encode;
use function str_repeat;

use const JSON_THROW_ON_ERROR;

/**
 * Client API 1.10.1 (contract v0.3.1): optional `note` on idle `keep` and `limits.idle_check.user_can_disable`.
 * The stack clock is fixed at 2026-11-02 10:00:00.
 */
final class ClientApiV1101Test extends TestCase
{
    private const SPAN = ['from' => '2026-11-02T09:30:00Z', 'to' => '2026-11-02T09:40:00Z', 'action' => 'keep'];

    private TestUser $user;

    protected function setUp(): void
    {
        $this->user = new TestUser('u-1', 'u@example.com');
    }

    public function testKeepNoteIsStoredAndAppendedAndEndsOnTheEntry(): void
    {
        [, $controller, $token] = $this->running();

        $first = $this->body($controller->timerDiscardIdle($this->request($token, self::SPAN + ['note' => "  Coffee\twith the team\u{0007} "])));
        self::assertSame('Coffee with the team', $first['timer']['metadata']['note'], 'control characters replaced by a space, trimmed');
        self::assertNull($first['entry']);
        self::assertNull($first['reassigned']);

        $second = $this->body($controller->timerDiscardIdle($this->request($token, self::SPAN + ['note' => 'Then a call'])));
        self::assertSame("Coffee with the team\nThen a call", $second['timer']['metadata']['note'], 'appended on a new line');

        $stop = $this->body($controller->timerStop($this->request($token, [])));
        self::assertSame("Coffee with the team\nThen a call", $stop['entry']['metadata']['note']);
    }

    public function testKeepWithoutNoteOrWithABlankNoteChangesNothing(): void
    {
        [, $controller, $token] = $this->running();

        foreach ([[], ['note' => null], ['note' => ''], ['note' => " \n\t "]] as $extra) {
            $body = $this->body($controller->timerDiscardIdle($this->request($token, self::SPAN + $extra)));
            self::assertArrayNotHasKey('note', $body['timer']['metadata'] ?? [], json_encode($extra, JSON_THROW_ON_ERROR));
        }
    }

    public function testInvalidKeepNotesAre400(): void
    {
        [, $controller, $token] = $this->running();

        foreach ([123, ['x'], true, str_repeat('a', 501)] as $note) {
            $response = $controller->timerDiscardIdle($this->request($token, self::SPAN + ['note' => $note]));
            self::assertSame(Response::HTTP_BAD_REQUEST, $response->getStatusCode());
            self::assertSame(['error' => 'invalid_request', 'message' => 'note must be plain text of at most 500 characters.'], $this->body($response));
        }

        $exactly = $controller->timerDiscardIdle($this->request($token, self::SPAN + ['note' => str_repeat('é', 500)]));
        self::assertSame(200, $exactly->getStatusCode(), '500 characters (not bytes) are allowed');
    }

    public function testCombinedNoteIsCutAtTheCapKeepingTheEarlierText(): void
    {
        $timer = new ActiveTimer($this->user, 't', 'T', null, ClientType::Desktop);
        $timer->appendNote(str_repeat('a', 498), 500);
        $timer->appendNote('bcd', 500);
        self::assertSame(str_repeat('a', 498) . "\nb", $timer->getMetadata()['note'] ?? null);

        $full = new ActiveTimer($this->user, 't', 'T', null, ClientType::Desktop, ['note' => str_repeat('x', 500), 'app' => 'tray']);
        $full->appendNote('more', 500);
        self::assertSame(str_repeat('x', 500), $full->getMetadata()['note'] ?? null);
        self::assertSame('tray', $full->getMetadata()['app'] ?? null, 'other metadata kept');
    }

    public function testKeepNoteReachesTheEventAndOnlyItsLengthIsLogged(): void
    {
        $logger     = new RecordingLogger();
        $dispatcher = new EventDispatcher();
        $seen       = new ArrayObject();
        $dispatcher->addListener(TimeTrackEvents::IDLE_RESOLVED, static function (IdleResolvedEvent $event) use ($seen): void {
            $seen->append($event);
        });
        $clock   = new MockClock('2026-11-02 10:00:00');
        $service = new TimerService(new StubTaskProvider(), new InMemoryActiveTimerRepository(), new InMemoryTimeEntryRepository(), $dispatcher, $clock, $logger);
        $service->start($this->user, 'task-1', ClientType::Desktop);
        $clock->sleep(600);
        $logger->records = [];

        $service->resolveIdle($this->user, $clock->now()->modify('-300 seconds'), $clock->now()->modify('-60 seconds'), IdleResolution::Keep, null, clientType: ClientType::Extension, note: 'Secret meeting with ACME');
        $service->resolveIdle($this->user, $clock->now()->modify('-300 seconds'), $clock->now()->modify('-60 seconds'), IdleResolution::Keep);

        /** @var list<IdleResolvedEvent> $events */
        $events = $seen->getArrayCopy();
        self::assertCount(2, $events);
        self::assertSame('Secret meeting with ACME', $events[0]->getNote());
        self::assertSame(IdleResolution::Keep, $events[0]->getResolution());
        self::assertNull($events[1]->getNote());

        $records = $this->records($logger);
        self::assertCount(2, $records);
        self::assertSame(['bundle' => 'time_track', 'action' => 'timer_idle_keep', 'client' => 'extension', 'note_length' => 24], $records[0]['context']);
        self::assertSame(0, $records[1]['context']['note_length'] ?? null);
        foreach ($logger->contextValues() as $value) {
            self::assertStringNotContainsString('Secret', $value);
            self::assertStringNotContainsString('ACME', $value);
        }
        self::assertStringNotContainsString('Secret', $records[0]['message']);
    }

    public function testMeAdvertisesTheKeepNoteAndUserCanDisable(): void
    {
        $stack = $this->stack(new ConfigClientApiSettingsProvider([], idleCheckUserCanDisable: true));
        $me    = $this->body($stack->apiController()->me($this->request($this->token($stack), null, 'GET')));

        self::assertTrue($me['features']['idle_keep_note']);
        self::assertSame(['threshold_minutes' => 10, 'answer_timeout_minutes' => 5, 'user_can_disable' => true], $me['limits']['idle_check']);

        $default = $this->stack();
        self::assertFalse($this->body($default->apiController()->me($this->request($this->token($default), null, 'GET')))['limits']['idle_check']['user_can_disable']);
    }

    public function testUserCanDisableFromTheProviderWithFallbacks(): void
    {
        $stack  = $this->stack(new ConfigClientApiSettingsProvider([], idleCheckUserCanDisable: true));
        $token  = $this->token($stack);
        $logger = new RecordingLogger();
        $me     = fn (IdleCheckSettingsProviderInterface $provider): array => $this->body($stack->apiController(idleCheckResolver: new IdleCheckSettingsResolver($stack->settings, $provider, $logger))->me($this->request($token, null, 'GET')))['limits']['idle_check'];

        self::assertFalse($me($this->provider(new IdleCheckSettings(true, 10, 5)))['user_can_disable'], 'a 1.10.0 provider (three arguments) still works: false');
        self::assertTrue($me($this->provider(new IdleCheckSettings(true, 10, 5, true)))['user_can_disable'], 'personal account');
        self::assertFalse($me($this->provider(new IdleCheckSettings(true, 10, 5, false)))['user_can_disable'], 'organisation policy');
        self::assertTrue($me($this->provider(null))['user_can_disable'], 'null: the configured value');
        self::assertTrue($me(new class implements IdleCheckSettingsProviderInterface {
            public function forUser(UserInterface $user): ?IdleCheckSettings
            {
                throw new RuntimeException('down');
            }
        })['user_can_disable'], 'exception: the configured value');
    }

    /**
     * @return list<array{level: string, message: string, context: array<mixed>}>
     */
    private function records(RecordingLogger $logger): array
    {
        return $logger->records;
    }

    private function provider(?IdleCheckSettings $settings): IdleCheckSettingsProviderInterface
    {
        return new class($settings) implements IdleCheckSettingsProviderInterface {
            public function __construct(private readonly ?IdleCheckSettings $settings)
            {
            }

            public function forUser(UserInterface $user): ?IdleCheckSettings
            {
                return $this->settings;
            }
        };
    }

    /**
     * @return array{ClientApiStack, TimeTrackClientApiController, string}
     */
    private function running(): array
    {
        $stack      = $this->stack();
        $controller = $stack->apiController();
        $token      = $this->token($stack);
        $start      = $controller->timerStart($this->request($token, ['taskId' => 'task-1', 'startedAt' => '2026-11-02T09:00:00Z']));
        self::assertSame(201, $start->getStatusCode());

        return [$stack, $controller, $token];
    }

    private function stack(?ConfigClientApiSettingsProvider $settings = null): ClientApiStack
    {
        $authenticator = $this->createStub(ClientAuthenticatorInterface::class);
        $authenticator->method('authenticate')->willReturn(ClientAuthResult::success($this->user));

        return new ClientApiStack($authenticator, new StubTaskProvider(), null, null, $settings ?? new ConfigClientApiSettingsProvider([]));
    }

    private function token(ClientApiStack $stack): string
    {
        return $stack->authService->issueToken($this->user, ClientType::Desktop)['token'];
    }

    /**
     * @param array<string, mixed>|null $payload
     */
    private function request(string $token, ?array $payload, string $method = 'POST'): Request
    {
        $request = Request::create('https://beacon.test/api', $method, [], [], [], ['REMOTE_ADDR' => '192.0.2.1'], $payload === null ? null : json_encode($payload, JSON_THROW_ON_ERROR));
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
