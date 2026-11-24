<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Tests\Functional;

use Nowo\TimeTrackBundle\Event\MonitoringPersonDetailViewedEvent;
use Nowo\TimeTrackBundle\Event\TimeTrackEvents;
use Nowo\TimeTrackBundle\Monitoring\MonitoringPolicy;
use Nowo\TimeTrackBundle\Monitoring\MonitoringTeamDetailDeniedException;
use Nowo\TimeTrackBundle\Monitoring\MonitoringTeamViewGuard;
use Nowo\TimeTrackBundle\Port\TimeTrackUserRefs;
use Nowo\TimeTrackBundle\Tests\Stub\MutableProfileProvider;
use Nowo\TimeTrackBundle\Tests\Stub\StubMonitoringEvidenceStore;
use Nowo\TimeTrackBundle\Tests\Stub\StubMonitoringPolicySource;
use Nowo\TimeTrackBundle\Tests\Stub\StubWorkdayHistorySource;
use Nowo\TimeTrackBundle\Tests\Support\FunctionalKernel;
use Nowo\TimeTrackBundle\Tests\Support\MonitoringFixtures;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
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
use function sprintf;

use const JSON_THROW_ON_ERROR;

/**
 * Since 1.41.0 (REQ-RUNTIME-002; decisions D9 and D11): in FrankenPHP worker mode with the kernel kept alive and **not
 * reset**, a worker on a register break and a working one take turns through `http_kernel`: the break never leaks to
 * the other person (nor the other way round), the team-view guard answers each worker's own policy, and the memory
 * stays flat.
 */
final class MonitoringPrivacyWorkerModeTest extends MonitoringTestCase
{
    /**
     * @param array<string, mixed> $options
     */
    protected static function createKernel(array $options = []): KernelInterface
    {
        return new FunctionalKernel(self::timeTrackConfig(), false, false, false, false, true);
    }

    protected static function timeTrackConfig(): array
    {
        return ['client_api' => [
            'profile_provider' => MutableProfileProvider::class,
            'workday_provider' => StubWorkdayHistorySource::class,
            'rate_limit'       => ['per_token' => 0, 'per_ip' => 0],
            'monitoring'       => [
                'policy_source'  => StubMonitoringPolicySource::class,
                'evidence_store' => StubMonitoringEvidenceStore::class,
                'rate_limit'     => ['intents_per_10_min' => 0, 'uploads_per_10_min' => 0, 'activity_per_10_min' => 0],
            ],
        ]];
    }

    public function testABreakNeverLeaksToAnotherWorker(): void
    {
        $other  = $this->createUser('other@example.com');
        $people = [['id' => (string) $this->user->getId(), 'token' => $this->token(), 'user' => $this->user], ['id' => (string) $other->getId(), 'token' => $this->token($other), 'user' => $other]];
        $now    = $this->now();
        $day    = gmdate('Y-m-d', $now);
        $iso    = static fn (int $time): string => gmdate('Y-m-d\TH:i:s\Z', $time);
        $source = self::getContainer()->get(StubWorkdayHistorySource::class);
        self::assertInstanceOf(StubWorkdayHistorySource::class, $source);
        $source->register->today = $day;
        $source->register->day($day, 'on_break', [['work', $iso($now - 3600), $iso($now - 600)], ['break', $iso($now - 600), null]], 'UTC', $people[0]['id']);
        $source->register->day($day, 'working', [['work', $iso($now - 3600), null]], 'UTC', $people[1]['id']);
        $this->policySource()->byUser[$people[0]['id']] = MonitoringFixtures::policy(overrides: ['purpose' => 'billing_evidence', 'teamView' => 'per_person', 'perPersonDetailRecordedAt' => $day]);

        $timers = [];
        foreach ($people as $i => $who) {
            $this->call('POST', self::ACK, $who['token'], ['policyVersion' => 7, 'noticeSha256' => MonitoringFixtures::noticeSha256(), 'shownAt' => $iso($now)], Response::HTTP_NO_CONTENT, ['HTTP_IDEMPOTENCY_KEY' => 'ack']);
            $timers[$i] = (string) ($this->call('POST', '/api/time-track/timer/start', $who['token'], ['taskId' => 'task-1', 'clientType' => 'desktop'], Response::HTTP_CREATED)['timer']['id'] ?? '');
        }
        $guard = self::getContainer()->get(MonitoringTeamViewGuard::class);
        self::assertInstanceOf(MonitoringTeamViewGuard::class, $guard);
        $dispatcher = self::getContainer()->get('event_dispatcher');
        self::assertInstanceOf(EventDispatcherInterface::class, $dispatcher);
        $viewed = 0;
        $dispatcher->addListener(TimeTrackEvents::MONITORING_PERSON_DETAIL_VIEWED, static function (MonitoringPersonDetailViewedEvent $event) use (&$viewed): void {
            ++$viewed;
        });
        $manager = TimeTrackUserRefs::fromUser($this->createUser('manager@example.com'));
        $slot    = $iso($now - $now % 600);

        $round = function (int $round, bool $check) use ($people, $timers, $slot, $guard, $manager): void {
            foreach ($people as $i => $who) {
                $answer = $this->call('POST', self::ACTIVITY, $who['token'], ['items' => [['entryId' => $timers[$i], 'slot' => $slot, 'kind' => 'level', 'seconds' => 600, 'level' => 40 + $i]]], Response::HTTP_ACCEPTED, ['HTTP_IDEMPOTENCY_KEY' => sprintf('r%d', $round)]);
                if ($check) {
                    self::assertSame($i === 0 ? 0 : 1, $answer['accepted'] ?? null, sprintf('round %d, person %d', $round, $i));
                }
                try {
                    $guard->authorizePersonDetail($manager, TimeTrackUserRefs::fromUser($who['user']));
                    $level = MonitoringPolicy::TEAM_VIEW_PER_PERSON;
                } catch (MonitoringTeamDetailDeniedException) {
                    $level = MonitoringPolicy::TEAM_VIEW_PROJECT_AGGREGATES;
                }
                if ($check) {
                    self::assertSame($i === 0 ? 'per_person' : 'project_aggregates', $level, sprintf('round %d, person %d', $round, $i));
                }
            }
        };
        for ($r = 0; $r < 25; ++$r) {
            $round($r, true);
        }
        self::assertSame(25, $viewed);
        foreach ($this->evidence()->activity as $batch) {
            self::assertSame($people[1]['id'], $batch['user'], 'only the working person\'s activity is stored');
        }

        $logger = $this->logger();
        for ($r = 25; $r < 75; ++$r) {
            $round($r, false);
            $logger->records            = [];
            $this->evidence()->activity = [];
        }
        gc_collect_cycles();
        $start = memory_get_usage();
        for ($r = 75; $r < 375; ++$r) {
            $round($r, false);
            $logger->records            = [];
            $this->evidence()->activity = [];
        }
        gc_collect_cycles();
        self::assertLessThan(256 * 1024, memory_get_usage() - $start, 'the break check and the guard keep a flat memory');
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
        $kernel = self::getContainer()->get('http_kernel');
        assert($kernel instanceof HttpKernelInterface && $kernel instanceof TerminableInterface);
        $response = $kernel->handle($request);
        $kernel->terminate($request, $response);
        self::assertSame($status, $response->getStatusCode(), $request->getMethod() . ' ' . $request->getPathInfo() . ': ' . $response->getContent());
        $content = (string) $response->getContent();
        $body    = $content === '' ? [] : json_decode($content, true, 512, JSON_THROW_ON_ERROR);

        return is_array($body) ? $body : [];
    }
}
