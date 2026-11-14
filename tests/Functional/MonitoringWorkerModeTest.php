<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Tests\Functional;

use Nowo\TimeTrackBundle\Tests\Support\FunctionalKernel;
use Nowo\TimeTrackBundle\Tests\Support\MonitoringFixtures;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Component\HttpKernel\TerminableInterface;

use function assert;
use function gc_collect_cycles;
use function gmdate;
use function is_array;
use function json_decode;
use function json_encode;
use function memory_get_usage;
use function parse_url;
use function sprintf;
use function strlen;

use const JSON_THROW_ON_ERROR;

/**
 * Since 1.26.0 (REQ-RUNTIME-002, contract §24.6): the monitoring controller in FrankenPHP worker mode with the kernel
 * kept alive and **not reset** — two people with different policies take turns straight through the container's
 * `http_kernel` (no services resetter): each sees only their own policy and acknowledgement, one can never use the
 * other's upload URL, the evidence store gets each upload under its owner, and the memory stays flat.
 */
class MonitoringWorkerModeTest extends MonitoringTestCase
{
    /**
     * @param array<string, mixed> $options
     */
    protected static function createKernel(array $options = []): KernelInterface
    {
        return new FunctionalKernel(static::timeTrackConfig(), false, false, false);
    }

    public function testTwoPeopleTakeTurnsWithoutTheServicesResetter(): void
    {
        $other                                  = $this->createUser('other@example.com');
        $person                                 = ['id' => (string) $this->user->getId(), 'token' => $this->token()];
        $second                                 = ['id' => (string) $other->getId(), 'token' => $this->token($other)];
        $source                                 = $this->policySource();
        $source->byUser[(string) $second['id']] = MonitoringFixtures::policy(3, ['activity']);

        $this->call('POST', self::ACK, $person['token'], ['policyVersion' => 7, 'noticeSha256' => MonitoringFixtures::noticeSha256(), 'shownAt' => gmdate('Y-m-d\TH:i:s\Z')], Response::HTTP_NO_CONTENT, ['HTTP_IDEMPOTENCY_KEY' => 'same-key']);
        $timers = [];
        foreach ([$person, $second] as $i => $who) {
            $timers[$i] = (string) ($this->call('POST', '/api/time-track/timer/start', $who['token'], ['taskId' => 'task-1', 'clientType' => 'desktop'], Response::HTTP_CREATED)['timer']['id'] ?? '');
        }

        for ($round = 0; $round < 25; ++$round) {
            $mine   = $this->call('GET', '/api/time-track/me', $person['token'])['monitoring'] ?? null;
            $theirs = $this->call('GET', '/api/time-track/me', $second['token'])['monitoring'] ?? null;
            self::assertSame([7, false], [$mine['policyVersion'] ?? null, $mine['acknowledgement']['required'] ?? null], sprintf('round %d: the person sees their policy and acknowledgement', $round));
            self::assertSame([3, true, ['activity']], [$theirs['policyVersion'] ?? null, $theirs['acknowledgement']['required'] ?? null, $theirs['kinds'] ?? null], 'the other person never inherits it');
            // The same Idempotency-Key from the other person is theirs, not a replay of the person's.
            $this->call('POST', self::ACK, $second['token'], ['policyVersion' => 7, 'noticeSha256' => MonitoringFixtures::noticeSha256(), 'shownAt' => '2026-11-20T08:59:41Z'], Response::HTTP_CONFLICT, ['HTTP_IDEMPOTENCY_KEY' => 'same-key']);
        }

        $image = MonitoringFixtures::webp(1280, 720);
        $grant = $this->call('POST', self::INTENT, $person['token'], $this->intentBody($timers[0], $image), Response::HTTP_CREATED, ['HTTP_IDEMPOTENCY_KEY' => 'cap']);
        $this->call('POST', self::INTENT, $second['token'], $this->intentBody($timers[1], $image, ['policyVersion' => 3]), Response::HTTP_UNPROCESSABLE_ENTITY, ['HTTP_IDEMPOTENCY_KEY' => 'cap']);
        $url = (string) ($grant['url'] ?? '');
        $this->upload($url, $second['token'], $image, Response::HTTP_FORBIDDEN);
        $this->upload($url, $person['token'], $image, Response::HTTP_CREATED);
        self::assertSame($person['id'], $this->evidence()->screenshots['shot-1']['user']);

        $logger = $this->logger();
        $step   = function (int $i) use ($person, $second, $logger): void {
            $this->call('GET', '/api/time-track/me', $i % 2 === 0 ? $person['token'] : $second['token']);
            $logger->records = [];
        };
        for ($i = 0; $i < 50; ++$i) {
            $step($i);
        }
        gc_collect_cycles();
        $start = memory_get_usage();
        for ($i = 0; $i < 500; ++$i) {
            $step($i);
        }
        gc_collect_cycles();
        self::assertLessThan(64 * 1024, memory_get_usage() - $start, 'GET me with the monitoring block keeps a flat memory');
    }

    private function upload(string $url, string $token, string $image, int $status): void
    {
        $parts   = parse_url($url);
        $request = Request::create(($parts['path'] ?? '') . '?' . ($parts['query'] ?? ''), 'PUT', [], [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
            'CONTENT_TYPE'       => 'image/webp',
            'CONTENT_LENGTH'     => (string) strlen($image),
            'REMOTE_ADDR'        => '203.0.113.7',
        ], $image);
        $this->handle($request, $status);
    }

    /**
     * @param array<string, mixed>|null $payload
     * @param array<string, string> $server
     *
     * @return array<mixed>
     */
    private function call(string $method, string $uri, string $token, ?array $payload = null, int $status = Response::HTTP_OK, array $server = []): array
    {
        $request = Request::create($uri, $method, [], [], [], $server + [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
            'CONTENT_TYPE'       => 'application/json',
            'REMOTE_ADDR'        => '203.0.113.7',
        ], $payload === null ? '' : json_encode($payload, JSON_THROW_ON_ERROR));

        return $this->handle($request, $status);
    }

    /**
     * @return array<mixed>
     */
    private function handle(Request $request, int $status): array
    {
        $kernel = self::getContainer()->get('http_kernel');
        assert($kernel instanceof HttpKernelInterface && $kernel instanceof TerminableInterface);
        $response = $kernel->handle($request);
        $kernel->terminate($request, $response);
        self::assertSame($status, $response->getStatusCode(), $request->getMethod() . ' ' . $request->getPathInfo() . ': ' . $response->getContent());
        $content = (string) $response->getContent();
        if ($content === '') {
            return [];
        }
        $body = json_decode($content, true, 512, JSON_THROW_ON_ERROR);

        return is_array($body) ? $body : [];
    }
}
