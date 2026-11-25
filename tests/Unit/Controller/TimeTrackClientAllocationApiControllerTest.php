<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Tests\Unit\Controller;

use Nowo\TimeTrackBundle\Bridge\StubTaskProvider;
use Nowo\TimeTrackBundle\Client\ClientAllocationPresenter;
use Nowo\TimeTrackBundle\Client\ClientAuthenticatorInterface;
use Nowo\TimeTrackBundle\Client\ClientAuthResult;
use Nowo\TimeTrackBundle\Client\ClientTaskPresenter;
use Nowo\TimeTrackBundle\Client\ConfigClientApiSettingsProvider;
use Nowo\TimeTrackBundle\Controller\TimeTrackClientAllocationApiController;
use Nowo\TimeTrackBundle\Entity\ActiveTimer;
use Nowo\TimeTrackBundle\Enum\ClientType;
use Nowo\TimeTrackBundle\Service\TimerService;
use Nowo\TimeTrackBundle\Subject\AllocationLabeler;
use Nowo\TimeTrackBundle\Subject\TimeSubjectLabelResolver;
use Nowo\TimeTrackBundle\Tests\Stub\TestUser;
use Nowo\TimeTrackBundle\Tests\Support\AllocationFixtures;
use Nowo\TimeTrackBundle\Tests\Support\ClientApiStack;
use Nowo\TimeTrackBundle\Tests\Support\CustomerTestSubjectProvider;
use Nowo\TimeTrackBundle\Tests\Support\FixedActivityTypeProvider;
use Nowo\TimeTrackBundle\Tests\Support\HookedActiveTimerRepository;
use Nowo\TimeTrackBundle\Tests\Support\InMemoryTimeEntryRepository;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

use function array_fill;
use function json_decode;

/**
 * Since 2.1.0 (Beacon spec 256, Q-TS-19): `POST timer/allocate` when a concurrent switch replaces the running timer on
 * every attempt answers `409 timer_running` (retryable), never a 500 and never an allocation of a timer that no longer runs.
 */
final class TimeTrackClientAllocationApiControllerTest extends TestCase
{
    public function testATimerReplacedOnEveryAttemptIsARetryableConflict(): void
    {
        $user          = new TestUser('42', 'person@example.com');
        $authenticator = $this->createStub(ClientAuthenticatorInterface::class);
        $authenticator->method('authenticate')->willReturn(ClientAuthResult::success($user));
        $timers     = new HookedActiveTimerRepository();
        $stack      = new ClientApiStack($authenticator, new StubTaskProvider(), null, null, new ConfigClientApiSettingsProvider([]), timers: $timers);
        $provider   = new CustomerTestSubjectProvider();
        $guard      = AllocationFixtures::guard([$provider], new FixedActivityTypeProvider());
        $service    = new TimerService(new StubTaskProvider(), $timers, new InMemoryTimeEntryRepository(), $stack->dispatcher, $stack->clock, allocationGuard: $guard);
        $presenter  = new ClientTaskPresenter($service);
        $controller = new TimeTrackClientAllocationApiController($stack->guard, $stack->responses, $stack->rateLimiter, $stack->auditor, $service, new ClientAllocationPresenter(new AllocationLabeler(new TimeSubjectLabelResolver($guard->subjects()), $guard->activityTypes()), $service, $presenter), $presenter);

        $current = $service->start($user, 'task-1', ClientType::Desktop);
        $swap    = static function (HookedActiveTimerRepository $repository) use (&$current, $user, $stack): void {
            $repository->remove($current);
            $current = new ActiveTimer($user, 'task-1', 'Implement login API', 'board-1', ClientType::Desktop, null, $stack->clock->now());
            $repository->save($current);
        };
        // Every second read (the one under the owner's lock) finds another timer.
        $hooks = [];
        foreach (array_fill(0, 10, null) as $_) {
            $hooks[] = null;
            $hooks[] = $swap;
        }
        $timers->beforeFinds(...$hooks);

        $request = Request::create('https://beacon.test/api/time-track/timer/allocate', 'POST', [], [], [], ['REMOTE_ADDR' => '192.0.2.1', 'CONTENT_TYPE' => 'application/json'], '{"activityTypeKey":"dev"}');
        $request->headers->set('Authorization', 'Bearer ' . $stack->authService->issueToken($user, ClientType::Desktop)['token']);
        $response = $controller->allocate($request);

        self::assertSame(409, $response->getStatusCode(), (string) $response->getContent());
        self::assertSame('timer_running', (json_decode((string) $response->getContent(), true) ?? [])['error'] ?? null);
        self::assertNull($current->getActivityTypeKey(), 'no timer was allocated');
    }
}
