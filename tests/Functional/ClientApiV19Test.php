<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Tests\Functional;

use DateTimeImmutable;
use Nowo\TimeTrackBundle\Client\ClientTokenRevoker;
use Nowo\TimeTrackBundle\Enum\ClientApiAuditAction;
use Nowo\TimeTrackBundle\Enum\ClientScope;
use Nowo\TimeTrackBundle\Enum\ClientType;
use Nowo\TimeTrackBundle\Event\ClientApiEvent;
use Nowo\TimeTrackBundle\Event\TimeTrackEvents;
use Nowo\TimeTrackBundle\Service\ClientAuthService;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpFoundation\Request;

use function array_filter;
use function array_map;
use function json_encode;
use function str_contains;

use const DATE_ATOM;

/**
 * Client API 1.9 (contract v0.2) end to end: stateless, scopes, task check, switch, idle decision, export, CORS,
 * audit events and revocation by credential.
 */
final class ClientApiV19Test extends FunctionalTestCase
{
    /** @var list<ClientApiEvent> */
    private array $events = [];

    protected static function timeTrackConfig(): array
    {
        return ['client_api' => ['allowed_origins' => ['moz-extension://v19-uuid']]];
    }

    protected function setUp(): void
    {
        parent::setUp();

        /** @var EventDispatcherInterface $dispatcher */
        $dispatcher = self::getContainer()->get('event_dispatcher');
        $dispatcher->addListener(TimeTrackEvents::CLIENT_API, function (ClientApiEvent $event): void {
            $this->events[] = $event;
        });
    }

    public function testApiIsStatelessOnSuccessAndOnDenial(): void
    {
        $token = $this->login();
        $this->assertNoSession();

        $this->api('GET', '/api/time-track/me', null, $token);
        self::assertResponseIsSuccessful();
        $this->assertNoSession();

        $body = $this->api('GET', '/api/time-track/me', null, 'unknown-token');
        self::assertResponseStatusCodeSame(401);
        self::assertSame('invalid_token', $body['error'] ?? null);
        self::assertResponseHeaderSame('WWW-Authenticate', 'Bearer error="invalid_token"');
        $this->assertNoSession();
    }

    public function testScopesAreCheckedPerEndpoint(): void
    {
        $token = $this->issue([ClientScope::TimerRead]);

        $this->api('GET', '/api/time-track/timer', null, $token);
        self::assertResponseStatusCodeSame(204);

        $me = $this->api('GET', '/api/time-track/me', null, $token);
        self::assertSame(['timer:read'], $me['scopes'] ?? null);

        foreach ([['GET', '/api/time-track/tasks'], ['GET', '/api/time-track/entries'], ['GET', '/api/time-track/export'], ['POST', '/api/time-track/timer/start'], ['POST', '/api/time-track/timer/switch'], ['POST', '/api/time-track/timer/discard-idle'], ['POST', '/api/time-track/heartbeat']] as [$method, $uri]) {
            $body = $this->api($method, $uri, null, $token);
            self::assertResponseStatusCodeSame(403, $uri);
            self::assertSame('insufficient_scope', $body['error'] ?? null);
        }

        $denials = array_filter($this->events, static fn (ClientApiEvent $e): bool => $e->action === ClientApiAuditAction::RequestDenied);
        self::assertCount(1, $denials, 'one denial event per credential and reason per interval');
    }

    public function testSwitchChecksTheTaskBeforeStoppingTheRunningTimer(): void
    {
        $token = $this->login();

        $first = $this->api('POST', '/api/time-track/timer/switch', ['taskId' => 'task-1', 'source' => 'extension'], $token);
        self::assertResponseStatusCodeSame(201);
        self::assertNull($first['entry'] ?? null);
        self::assertSame('extension', $first['timer']['clientType'] ?? null);

        $this->api('POST', '/api/time-track/timer/switch', ['taskId' => 'missing-task'], $token);
        self::assertResponseStatusCodeSame(403);

        $this->api('POST', '/api/time-track/timer/switch', ['taskId' => 'task-2', 'source' => 'fax'], $token);
        self::assertResponseStatusCodeSame(400);

        $this->api('POST', '/api/time-track/timer/switch', ['note' => 'no task'], $token);
        self::assertResponseStatusCodeSame(400);

        $timer = $this->api('GET', '/api/time-track/timer', null, $token);
        self::assertSame('task-1', $timer['timer']['taskId'] ?? null);

        $second = $this->api('POST', '/api/time-track/timer/switch', ['taskId' => 'task-2', 'note' => '  wrapped up  '], $token);
        self::assertResponseStatusCodeSame(201);
        self::assertSame('task-1', $second['entry']['taskId'] ?? null);
        self::assertSame('wrapped up', $second['entry']['metadata']['note'] ?? null);
        self::assertSame('task-2', $second['timer']['taskId'] ?? null);
        self::assertSame('desktop', $second['timer']['clientType'] ?? null, 'defaults to the token client');

        $this->api('POST', '/api/time-track/timer/start', ['taskId' => 'task-1', 'source' => 'web'], $token);
        self::assertResponseStatusCodeSame(409);

        $this->api('POST', '/api/time-track/timer/start', ['taskId' => 'task-1', 'source' => 'fax'], $token);
        self::assertResponseStatusCodeSame(400);

        $stopped = $this->api('POST', '/api/time-track/timer/stop', ['note' => 'done'], $token);
        self::assertSame('done', $stopped['entry']['metadata']['note'] ?? null);
    }

    public function testDiscardIdleKeepsDiscardsOrSplits(): void
    {
        $token = $this->login();
        $past  = static fn (int $seconds): string => (new DateTimeImmutable('-' . $seconds . ' seconds'))->format(DATE_ATOM);

        $this->api('POST', '/api/time-track/timer/discard-idle', ['from' => 'yesterday', 'to' => $past(1), 'action' => 'keep'], $token);
        self::assertResponseStatusCodeSame(400);

        $this->api('POST', '/api/time-track/timer/discard-idle', ['from' => $past(2), 'to' => $past(1), 'action' => 'keep'], $token);
        self::assertResponseStatusCodeSame(404);

        $this->startTimerAt('-1 hour', $token);

        $this->api('POST', '/api/time-track/timer/discard-idle', ['from' => $past(7200), 'to' => $past(60), 'action' => 'keep'], $token);
        self::assertResponseStatusCodeSame(400, 'idle span before the timer start');

        $kept = $this->api('POST', '/api/time-track/timer/discard-idle', ['from' => $past(1800), 'to' => $past(600), 'action' => 'keep'], $token);
        self::assertResponseIsSuccessful();
        self::assertNull($kept['entry'] ?? null);
        self::assertSame('task-1', $kept['timer']['taskId'] ?? null);

        $split = $this->api('POST', '/api/time-track/timer/discard-idle', ['from' => $past(1800), 'to' => (new DateTimeImmutable('-600 seconds'))->format('Y-m-d\TH:i:s.v\Z'), 'action' => 'split'], $token);
        self::assertResponseIsSuccessful();
        self::assertEqualsWithDelta(1800, $split['entry']['durationSeconds'] ?? 0, 5);
        self::assertSame('task-1', $split['timer']['taskId'] ?? null);

        $discarded = $this->api('POST', '/api/time-track/timer/discard-idle', ['from' => $past(300), 'to' => $past(10), 'action' => 'discard'], $token);
        self::assertResponseIsSuccessful();
        self::assertEqualsWithDelta(300, $discarded['entry']['durationSeconds'] ?? 0, 5);
        self::assertNull($discarded['timer'] ?? null);

        $this->api('GET', '/api/time-track/timer', null, $token);
        self::assertResponseStatusCodeSame(204);
    }

    public function testEntriesAreOwnAndBoundedAndExportDownloads(): void
    {
        $token = $this->login();
        foreach (['task-1', 'task-2', 'task-1'] as $taskId) {
            $this->api('POST', '/api/time-track/timer/start', ['taskId' => $taskId], $token);
            $this->api('POST', '/api/time-track/timer/stop', ['note' => '=HYPERLINK("x")'], $token);
        }

        $page = $this->api('GET', '/api/time-track/entries?limit=2&offset=1', null, $token);
        self::assertCount(2, $page['entries'] ?? []);
        self::assertSame(2, $page['limit'] ?? null);
        self::assertSame(1, $page['offset'] ?? null);

        $all = $this->api('GET', '/api/time-track/entries?limit=500', null, $token);
        self::assertSame(100, $all['limit'] ?? null);

        $this->client->request('GET', '/api/time-track/export?format=csv', [], [], ['HTTP_AUTHORIZATION' => 'Bearer ' . $token]);
        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Content-Type', 'text/csv; charset=UTF-8');
        self::assertStringContainsString('attachment; filename="time-entries-', (string) $this->client->getResponse()->headers->get('Content-Disposition'));
        $csv = (string) $this->client->getResponse()->getContent();
        self::assertStringStartsWith('id,taskId,taskTitle', $csv);
        self::assertStringContainsString('"\'=HYPERLINK(""x"")"', $csv, 'formula neutralised');

        $json = $this->api('GET', '/api/time-track/export', null, $token);
        self::assertCount(3, $json['entries'] ?? []);
        self::assertResponseHeaderSame('Cache-Control', 'no-store, private');

        $this->api('GET', '/api/time-track/export?format=xml', null, $token);
        self::assertResponseStatusCodeSame(400);

        $other = $this->issue([], 'other@example.com');
        $none  = $this->api('GET', '/api/time-track/export', null, $other);
        self::assertSame([], $none['entries'] ?? null, 'own data only');
    }

    public function testCorsAllowsExactOriginsOnly(): void
    {
        foreach (['moz-extension://v19-uuid' => true, 'chrome-extension://functional' => true, 'tauri://localhost' => true, 'moz-extension://other' => false, 'https://evil.example' => false] as $origin => $allowed) {
            $this->api('OPTIONS', '/api/time-track/timer/switch', null, null, ['HTTP_ORIGIN' => $origin]);
            self::assertResponseStatusCodeSame(204);
            if ($allowed) {
                self::assertResponseHeaderSame('Access-Control-Allow-Origin', $origin);
            } else {
                self::assertResponseNotHasHeader('Access-Control-Allow-Origin');
            }
        }
    }

    public function testAuditEventsCarryIdsAndTruncatedIpButNoToken(): void
    {
        $token = $this->login(server: ['REMOTE_ADDR' => '203.0.113.77']);
        $this->api('GET', '/api/time-track/me', null, 'unknown-' . $token);
        $this->api('GET', '/api/time-track/me', null, 'unknown-' . $token);
        $this->api('POST', '/api/time-track/logout', null, $token);

        $actions = array_map(static fn (ClientApiEvent $e): string => $e->action->value, $this->events);
        self::assertSame(['token_issued', 'request_denied', 'token_revoked'], $actions);
        self::assertSame((string) $this->user->getId(), $this->events[0]->userId);
        self::assertSame('203.0.113.0/24', $this->events[0]->ipPrefix);
        self::assertSame('desktop', $this->events[0]->clientType);
        self::assertSame('invalid_token', $this->events[1]->reason);
        foreach ($this->events as $event) {
            self::assertFalse(str_contains((string) json_encode($event->toArray()), $token));
        }
    }

    public function testRevokeByCredentialEndsEverySessionOfTheCredential(): void
    {
        $first  = $this->issue([], 'person@example.com', 'app-password-7');
        $second = $this->issue([], 'person@example.com', 'app-password-7');
        $other  = $this->issue([], 'person@example.com', 'app-password-8');

        /** @var ClientTokenRevoker $revoker */
        $revoker = self::getContainer()->get(ClientTokenRevoker::class);
        self::assertSame(2, $revoker->revokeByCredential('app-password-7', $this->user));
        self::assertSame(0, $revoker->revokeByCredential(''));

        foreach ([$first => 401, $second => 401, $other => 200] as $token => $status) {
            $this->api('GET', '/api/time-track/me', null, $token);
            self::assertResponseStatusCodeSame($status);
        }
        self::assertContains(ClientApiAuditAction::CredentialRevoked, array_map(static fn (ClientApiEvent $e): ClientApiAuditAction => $e->action, $this->events));
    }

    /**
     * @param list<ClientScope> $scopes
     * @param non-empty-string $email
     */
    private function issue(array $scopes, string $email = 'person@example.com', ?string $credentialId = null): string
    {
        $user = $email === 'person@example.com' ? $this->user : $this->createUser($email);

        /** @var ClientAuthService $auth */
        $auth = self::getContainer()->get(ClientAuthService::class);

        return $auth->issueToken($user, ClientType::Desktop, $credentialId, $scopes)['token'];
    }

    private function startTimerAt(string $modifier, string $token): void
    {
        $this->api('POST', '/api/time-track/timer/start', ['taskId' => 'task-1'], $token);
        self::assertResponseStatusCodeSame(201);
        $em = $this->entityManager();
        $em->getConnection()->executeStatement('UPDATE time_track_active_timers SET started_at = ?', [(new DateTimeImmutable($modifier))->format('Y-m-d H:i:s')]);
        $em->clear();
    }

    private function assertNoSession(): void
    {
        self::assertResponseNotHasHeader('Set-Cookie');
        $request = $this->client->getRequest();
        self::assertInstanceOf(Request::class, $request);
        self::assertFalse($request->hasSession() && $request->getSession()->isStarted(), 'the client API never starts a session');
    }
}
