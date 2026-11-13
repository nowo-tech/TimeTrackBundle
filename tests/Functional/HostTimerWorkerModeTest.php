<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Tests\Functional;

use Nowo\TimeTrackBundle\Tests\Stub\StubServiceAccountPolicy;
use Nowo\TimeTrackBundle\Tests\Support\FunctionalKernel;
use Nowo\TimeTrackBundle\Tests\Support\RecordingLogger;
use Nowo\TimeTrackBundle\Timer\HostTimerApi;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Component\HttpKernel\TerminableInterface;

use function assert;
use function gc_collect_cycles;
use function is_array;
use function json_decode;
use function json_encode;
use function memory_get_usage;
use function sprintf;

use const JSON_THROW_ON_ERROR;

/**
 * Since 1.25.0 (REQ-RUNTIME-002, Beacon spec 234): {@see HostTimerApi} behind a host
 * controller with a token principal, in FrankenPHP worker mode with the kernel kept alive and **not reset**: one kernel
 * serves a person and two agents in turn straight through `http_kernel` (no services resetter). Every answer holds only
 * the caller's timer, an agent never stops the person's timer, the service-account answer is asked per request (never
 * memoised), and the memory stays flat.
 */
final class HostTimerWorkerModeTest extends FunctionalTestCase
{
    /**
     * @param array<string, mixed> $options
     */
    protected static function createKernel(array $options = []): KernelInterface
    {
        return new FunctionalKernel(self::timeTrackConfig(), false, false, false);
    }

    public function testAgentsAndAPersonThroughOneKernelWithoutTheServicesResetter(): void
    {
        $this->createUser('agent-executor@example.com');
        $this->createUser('agent-verifier@example.com');
        $person = 'bt_person@example.com';
        $agents = ['bt_agent-executor@example.com', 'bt_agent-verifier@example.com'];

        $this->call($person, 'start', ['taskId' => 'task-1', 'channel' => 'api', 'note' => 'person'], Response::HTTP_CREATED);
        $personTimer = $this->call($person, 'current')['timer']['id'] ?? null;
        self::assertIsString($personTimer);

        for ($round = 0; $round < 40; ++$round) {
            foreach ($agents as $i => $agent) {
                $start = $this->call($agent, 'start', ['taskId' => 'task-' . (($round % 2) + 1), 'channel' => 'mcp', 'note' => $agent . $round], Response::HTTP_CREATED);
                if ($round === 0) {
                    self::assertNull($start['stopped'], 'nothing of anyone else is stopped');
                } else {
                    self::assertSame([true, 'mcp'], [$start['stopped']['serviceAccount'] ?? null, $start['stopped']['channel'] ?? null], sprintf('round %d: agent %d replaced its own timer', $round, $i));
                }
            }
            foreach ($agents as $agent) {
                self::assertSame($agent . $round, $this->call($agent, 'current')['timer']['note'] ?? null, 'each agent reads only its own timer');
            }
            self::assertSame($personTimer, $this->call($person, 'current')['timer']['id'] ?? null, sprintf('round %d: the person\'s timer still runs', $round));
        }

        // The host's answer changes between two requests: the next write uses it at once.
        $policy = self::getContainer()->get(StubServiceAccountPolicy::class);
        assert($policy instanceof StubServiceAccountPolicy);
        self::assertFalse($this->call($person, 'stop')['entry']['serviceAccount'] ?? null);
        $policy->agentIds = [(string) $this->user->getId()];
        $this->call($person, 'start', ['taskId' => 'task-1'], Response::HTTP_CREATED);
        self::assertTrue($this->call($person, 'stop')['entry']['serviceAccount'] ?? null);
        $policy->agentIds = [];
        foreach ($agents as $agent) {
            self::assertSame('mcp', $this->call($agent, 'stop')['entry']['channel'] ?? null);
        }
        self::assertNull($this->call($person, 'stop')['entry']);
    }

    public function testTheTimerPathKeepsAFlatMemory(): void
    {
        $this->createUser('agent-executor@example.com');
        $step = function (int $i): void {
            $token = $i % 2 === 0 ? 'bt_agent-executor@example.com' : 'bt_person@example.com';
            $this->call($token, 'start', ['taskId' => 'task-1'], Response::HTTP_CREATED);
            $this->call($token, 'current');
        };
        $logger = self::getContainer()->get('logger');
        assert($logger instanceof RecordingLogger);
        for ($i = 0; $i < 50; ++$i) {
            $step($i);
            $logger->records = [];
        }
        $this->entityManager()->clear();
        gc_collect_cycles();
        $start = memory_get_usage();
        for ($i = 0; $i < 300; ++$i) {
            $step($i);
            $logger->records = [];
        }
        $this->entityManager()->clear();
        gc_collect_cycles();
        $grown = memory_get_usage() - $start;

        self::assertLessThan(256 * 1024, $grown, sprintf('300 steps (600 requests, each start a write) grew the memory by %d bytes', $grown));
    }

    /**
     * One request straight through the container's `http_kernel` (no services resetter); asserts the status.
     *
     * @param array<string, mixed> $payload
     *
     * @return array<mixed>
     */
    private function call(string $token, string $action, array $payload = [], int $status = Response::HTTP_OK): array
    {
        $kernel = self::getContainer()->get('http_kernel');
        assert($kernel instanceof HttpKernelInterface && $kernel instanceof TerminableInterface);
        $request  = Request::create('/host-timer/' . $action, 'POST', [], [], [], ['HTTP_X_TEST_TOKEN' => $token, 'CONTENT_TYPE' => 'application/json'], json_encode($payload, JSON_THROW_ON_ERROR));
        $response = $kernel->handle($request);
        $kernel->terminate($request, $response);
        self::assertSame($status, $response->getStatusCode(), $action . ': ' . $response->getContent());
        $body = json_decode((string) $response->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertTrue(is_array($body));

        return $body;
    }
}
