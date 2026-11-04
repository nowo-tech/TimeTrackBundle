<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Tests\Unit\ClientApi;

use Nowo\TimeTrackBundle\Bridge\StubTaskProvider;
use Nowo\TimeTrackBundle\Client\ClientAuthenticatorInterface;
use Nowo\TimeTrackBundle\Client\ClientAuthResult;
use Nowo\TimeTrackBundle\Client\ConfigClientApiSettingsProvider;
use Nowo\TimeTrackBundle\Controller\TimeTrackClientApiController;
use Nowo\TimeTrackBundle\Enum\ClientType;
use Nowo\TimeTrackBundle\Tests\Stub\TestUser;
use Nowo\TimeTrackBundle\Tests\Support\ClientApiStack;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

use function json_decode;
use function json_encode;
use function str_repeat;

use const JSON_THROW_ON_ERROR;

/**
 * 1.10.2 (EF2-I2, EF2-I4): one sanitiser and merge rule for every client note. The stack clock is fixed at
 * 2026-11-02 10:00:00.
 */
final class ClientApiV1102Test extends TestCase
{
    private TestUser $user;

    protected function setUp(): void
    {
        $this->user = new TestUser('u-1', 'u@example.com');
    }

    public function testStopNoteIsMergedAfterTheKeepNotes(): void
    {
        [$controller, $token] = $this->controller();
        $this->start($controller, $token, ['note' => "Start \u{202E}note"]);
        $this->keep($controller, $token, "Keep\u{0007}one");

        $stop = $this->body($controller->timerStop($this->request($token, ['note' => "\u{FEFF} Stop\r\nnote "])));

        self::assertSame("Start note\nKeep one\nStop note", $stop['entry']['metadata']['note']);
    }

    public function testStopWithoutNoteKeepsTheCollectedNotes(): void
    {
        foreach ([[], ['note' => " \u{200B} "], ['note' => 42]] as $payload) {
            [$controller, $token] = $this->controller();
            $this->start($controller, $token);
            $this->keep($controller, $token, 'Kept');

            self::assertSame('Kept', $this->body($controller->timerStop($this->request($token, $payload)))['entry']['metadata']['note'], json_encode($payload, JSON_THROW_ON_ERROR));
        }
    }

    public function testMergedNotesAreCutAt500CodePoints(): void
    {
        [$controller, $token] = $this->controller();
        $this->start($controller, $token, ['note' => str_repeat('a', 498)]);

        $stop = $this->body($controller->timerStop($this->request($token, ['note' => str_repeat('b', 600)])));

        self::assertSame(str_repeat('a', 498) . "\nb", $stop['entry']['metadata']['note']);
    }

    public function testSplitDoesNotCopyTheNoteToTheFollowUp(): void
    {
        [$controller, $token] = $this->controller();
        $this->start($controller, $token, ['note' => 'Before the break']);

        $split = $this->body($controller->timerDiscardIdle($this->request($token, ['from' => '2026-11-02T09:30:00Z', 'to' => '2026-11-02T09:40:00Z', 'action' => 'split'])));
        self::assertSame('Before the break', $split['entry']['metadata']['note']);
        self::assertArrayNotHasKey('note', $split['timer']['metadata'] ?? []);

        $stop = $this->body($controller->timerStop($this->request($token, [])));
        self::assertArrayNotHasKey('note', $stop['entry']['metadata'] ?? [], 'not repeated on the follow-up entry');
    }

    public function testReassignContinueDoesNotCopyTheNoteAndCleansTheReassignNote(): void
    {
        [$controller, $token] = $this->controller();
        $this->start($controller, $token, ['note' => 'Original']);

        $body = $this->body($controller->timerDiscardIdle($this->request($token, ['from' => '2026-11-02T09:30:00Z', 'to' => '2026-11-02T09:40:00Z', 'action' => 'reassign', 'taskId' => 'task-2', 'note' => "\u{2066}Meeting\u{2069}\n"])));

        self::assertSame('Original', $body['entry']['metadata']['note']);
        self::assertSame('Meeting', $body['reassigned']['metadata']['note']);
        self::assertArrayNotHasKey('note', $body['timer']['metadata'] ?? []);
    }

    public function testSwitchNoteIsMergedOntoTheStoppedEntry(): void
    {
        [$controller, $token] = $this->controller();
        $this->start($controller, $token, ['note' => 'First']);

        $switch = $this->body($controller->timerSwitch($this->request($token, ['taskId' => 'task-2', 'note' => 'Done'])));

        self::assertSame("First\nDone", $switch['entry']['metadata']['note']);
        self::assertArrayNotHasKey('note', $switch['timer']['metadata'] ?? []);
    }

    public function testFreeMetadataNoteIsCleanedToo(): void
    {
        $authenticator = $this->createStub(ClientAuthenticatorInterface::class);
        $authenticator->method('authenticate')->willReturn(ClientAuthResult::success($this->user));
        $stack      = new ClientApiStack($authenticator, new StubTaskProvider(), null, null, new ConfigClientApiSettingsProvider([]));
        $controller = $stack->apiController(allowMetadata: true);
        $token      = $stack->authService->issueToken($this->user, ClientType::Desktop)['token'];

        $start = $this->body($controller->timerStart($this->request($token, ['taskId' => 'task-1', 'metadata' => ['app' => 'tray', 'note' => "\u{202E}x\u{0000}y"]])));
        self::assertSame(['app' => 'tray', 'note' => 'x y'], $start['timer']['metadata']);
        $controller->timerStop($this->request($token, []));

        $nonString = $this->body($controller->timerStart($this->request($token, ['taskId' => 'task-1', 'metadata' => ['app' => 'tray', 'note' => ['x']]])));
        self::assertSame(['app' => 'tray'], $nonString['timer']['metadata']);
    }

    public function testKeepNoteWithOnlyInvisibleCharactersIsIgnored(): void
    {
        [$controller, $token] = $this->controller();
        $this->start($controller, $token);

        $keep = $this->body($controller->timerDiscardIdle($this->request($token, ['from' => '2026-11-02T09:30:00Z', 'to' => '2026-11-02T09:40:00Z', 'action' => 'keep', 'note' => "\u{202E}\u{200B}\u{FEFF}"])));

        self::assertArrayNotHasKey('note', $keep['timer']['metadata'] ?? []);
    }

    /**
     * @return array{TimeTrackClientApiController, string}
     */
    private function controller(): array
    {
        $authenticator = $this->createStub(ClientAuthenticatorInterface::class);
        $authenticator->method('authenticate')->willReturn(ClientAuthResult::success($this->user));
        $stack = new ClientApiStack($authenticator, new StubTaskProvider(), null, null, new ConfigClientApiSettingsProvider([]));

        return [$stack->apiController(), $stack->authService->issueToken($this->user, ClientType::Desktop)['token']];
    }

    /**
     * @param array<string, mixed> $extra
     */
    private function start(TimeTrackClientApiController $controller, string $token, array $extra = []): void
    {
        $response = $controller->timerStart($this->request($token, ['taskId' => 'task-1', 'startedAt' => '2026-11-02T09:00:00Z'] + $extra));
        self::assertSame(201, $response->getStatusCode(), (string) $response->getContent());
    }

    private function keep(TimeTrackClientApiController $controller, string $token, string $note): void
    {
        $response = $controller->timerDiscardIdle($this->request($token, ['from' => '2026-11-02T09:30:00Z', 'to' => '2026-11-02T09:40:00Z', 'action' => 'keep', 'note' => $note]));
        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function request(string $token, array $payload): Request
    {
        $request = Request::create('https://beacon.test/api', 'POST', [], [], [], ['REMOTE_ADDR' => '192.0.2.1'], json_encode($payload, JSON_THROW_ON_ERROR));
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
