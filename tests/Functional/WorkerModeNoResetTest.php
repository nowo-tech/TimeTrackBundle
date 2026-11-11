<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Tests\Functional;

use Nowo\TimeTrackBundle\Tests\Support\FunctionalKernel;
use Nowo\TimeTrackBundle\Tests\Support\RecordingLogger;
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
 * Since 1.19 (REQ-RUNTIME-002): FrankenPHP worker mode with the kernel kept alive and **not reset** between requests
 * (`FRANKENPHP_RESET_KERNEL=false`). One kernel serves two people in turn straight through the container's
 * `http_kernel` (the test browser would run the services resetter before each request; this test never does): every
 * answer holds only the caller's data, and the hot read path keeps a flat memory.
 *
 * - Cross-user check: 250 rounds × 2 people × 2 reads (`GET me`, `GET timer`) = 1,000 requests, plus 50 rounds of
 *   start / read / stop each (writes are slower; 300 more requests).
 * - Memory check: 100 warm-up steps, then 1,000 steps (one step = `GET me` + `GET timer`, alternating people); the
 *   growth must stay under 64 KB (the test logger's own records are cleared after each step).
 */
final class WorkerModeNoResetTest extends FunctionalTestCase
{
    /**
     * A production-like kernel (debug off): in debug mode the framework's own profilers (Doctrine query log, traced
     * event dispatcher) keep every request until `kernel.reset`, which is not the bundle's memory.
     *
     * @param array<string, mixed> $options
     */
    protected static function createKernel(array $options = []): KernelInterface
    {
        return new FunctionalKernel(self::timeTrackConfig(), false, false, false);
    }

    protected static function timeTrackConfig(): array
    {
        return ['client_api' => ['rate_limit' => ['per_token' => 0, 'per_ip' => 0]]];
    }

    public function testNothingLeaksFromOnePersonToTheNextWithoutTheServicesResetter(): void
    {
        $other  = $this->createUser('other@example.com');
        $people = [
            'person' => ['id' => (string) $this->user->getId(), 'identifier' => 'person@example.com', 'token' => $this->login()],
            'other'  => ['id' => (string) $other->getId(), 'identifier' => 'other@example.com', 'token' => $this->login('other@example.com')],
        ];

        for ($round = 0; $round < 250; ++$round) {
            foreach ($people as $name => $person) {
                $me = $this->call('GET', '/api/time-track/me', $person['token']);
                self::assertSame([$person['id'], $person['identifier']], [$me['userId'] ?? null, $me['identifier'] ?? null], sprintf('round %d: %s sees only their own profile', $round, $name));
                self::assertSame([], $this->call('GET', '/api/time-track/timer', $person['token'], null, Response::HTTP_NO_CONTENT), 'no timer leaks in');
            }
        }

        for ($round = 0; $round < 50; ++$round) {
            foreach ($people as $name => $person) {
                $this->call('POST', '/api/time-track/timer/start', $person['token'], ['taskId' => 'task-1', 'note' => $name . '-' . $round, 'clientType' => 'desktop'], Response::HTTP_CREATED);
            }
            foreach ($people as $name => $person) {
                $timer = $this->call('GET', '/api/time-track/timer', $person['token'])['timer'] ?? null;
                self::assertIsArray($timer);
                self::assertSame($name . '-' . $round, $timer['metadata']['note'] ?? null, sprintf('round %d: %s reads only their own timer', $round, $name));
            }
            foreach ($people as $person) {
                $this->call('POST', '/api/time-track/timer/stop', $person['token']);
            }
        }
    }

    public function testTheHotReadPathKeepsAFlatMemoryWithoutTheServicesResetter(): void
    {
        $other  = $this->createUser('other@example.com');
        $tokens = [$this->login(), $this->login('other@example.com')];
        self::assertNotNull($other->getId());
        $logger = self::getContainer()->get('logger');
        self::assertInstanceOf(RecordingLogger::class, $logger);
        $step = function (int $i) use ($tokens, $logger): void {
            $token = $tokens[$i % 2];
            $this->call('GET', '/api/time-track/me', $token);
            $this->call('GET', '/api/time-track/timer', $token, null, Response::HTTP_NO_CONTENT);
            // The test logger's own records are not the kernel's memory.
            $logger->records = [];
        };

        for ($i = 0; $i < 100; ++$i) {
            $step($i);
        }
        gc_collect_cycles();
        $start = memory_get_usage();
        for ($i = 0; $i < 1_000; ++$i) {
            $step($i);
        }
        gc_collect_cycles();
        $grown = memory_get_usage() - $start;

        self::assertLessThan(64 * 1024, $grown, sprintf('1,000 steps (2,000 requests) grew the memory by %d bytes', $grown));
    }

    /**
     * One request straight through the container's `http_kernel` (no kernel boot check, no services resetter); asserts
     * the status and returns the JSON body ([] when empty).
     *
     * @param array<string, mixed>|null $payload
     *
     * @return array<mixed>
     */
    private function call(string $method, string $uri, string $token, ?array $payload = null, int $status = Response::HTTP_OK): array
    {
        $kernel = self::getContainer()->get('http_kernel');
        assert($kernel instanceof HttpKernelInterface && $kernel instanceof TerminableInterface);
        $request = Request::create($uri, $method, [], [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
            'CONTENT_TYPE'       => 'application/json',
            'REMOTE_ADDR'        => '203.0.113.7',
        ], $payload === null ? '' : json_encode($payload, JSON_THROW_ON_ERROR));
        $response = $kernel->handle($request);
        $kernel->terminate($request, $response);
        self::assertSame($status, $response->getStatusCode(), $method . ' ' . $uri . ': ' . $response->getContent());
        if ((string) $response->getContent() === '') {
            return [];
        }
        $body = json_decode((string) $response->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertTrue(is_array($body));

        return $body;
    }
}
