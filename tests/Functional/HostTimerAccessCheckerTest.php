<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Tests\Functional;

use DateTimeImmutable;
use Nowo\TimeTrackBundle\Entity\TimeEntry;
use Nowo\TimeTrackBundle\Exception\TaskNotTrackableException;
use Nowo\TimeTrackBundle\Port\TimeTrackUserRefFactory;
use Nowo\TimeTrackBundle\Security\TaskAccessCheckerInterface;
use Nowo\TimeTrackBundle\Tests\Fixtures\FunctionalEntity\AppUser;
use Nowo\TimeTrackBundle\Tests\Stub\RecordingTaskAccessChecker;
use Nowo\TimeTrackBundle\Timer\HostTimerApi;
use Nowo\TimeTrackBundle\ValueObject\TimeTrackUserRef;
use Symfony\Component\HttpFoundation\Response;

use const DATE_ATOM;

/**
 * Since 1.25.0 (audit PA-1): the host's `client_api.task_access_checker` decides `HostTimerApi::start()` as it decides
 * `POST timer/start`. The checker here refuses `task-2`, a task the stub task provider resolves for everyone, so a pass
 * could only come from skipping the checker.
 */
final class HostTimerAccessCheckerTest extends FunctionalTestCase
{
    protected static function timeTrackConfig(): array
    {
        return ['client_api' => ['task_access_checker' => RecordingTaskAccessChecker::class], 'manual_entries' => ['enabled' => true]];
    }

    public function testTheAccessCheckerRefusesATaskTheProviderWouldResolve(): void
    {
        $api = self::getContainer()->get(HostTimerApi::class);
        self::assertInstanceOf(HostTimerApi::class, $api);
        self::assertInstanceOf(RecordingTaskAccessChecker::class, self::getContainer()->get(TaskAccessCheckerInterface::class), 'the host checker is the configured one');
        $actor = $this->ref($this->user);

        $api->start($actor, 'task-1', null, 'mcp');

        try {
            $api->start($actor, 'task-2', null, 'mcp');
            self::fail('the checker refuses task-2');
        } catch (TaskNotTrackableException) {
            // Same exception as an unknown task: a token principal learns nothing about what it may not see.
        }

        self::assertSame('task-1', $api->current($actor)?->taskId, 'the running timer was not stopped by the refused start');
        self::assertCount(0, $this->entityManager()->getRepository(TimeEntry::class)->findAll(), 'nothing was recorded');
        self::assertSame(['task-1', 'task-2'], $this->checker()->checked, 'the checker is asked on every start');

        // The same principal may stop its own timer: the checker guards starting, not stopping.
        self::assertNotNull($api->stop($actor));
    }

    public function testTheClientApiRefusesTheSameTask(): void
    {
        // The two paths agree, which is the point of the finding.
        $token = $this->login();
        $this->api('POST', '/api/time-track/timer/start', ['taskId' => 'task-2'], $token);
        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
        $this->api('POST', '/api/time-track/timer/start', ['taskId' => 'task-1'], $token);
        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);
    }

    public function testAManualEntryIsRefusedForTheSameTask(): void
    {
        $token = $this->login();
        $day   = (new DateTimeImmutable('-1 day 09:00'))->format(DATE_ATOM);
        $this->api('POST', '/api/time-track/entries', ['taskId' => 'task-2', 'startedAt' => $day, 'endedAt' => (new DateTimeImmutable('-1 day 10:00'))->format(DATE_ATOM)], $token);
        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
    }

    private function checker(): RecordingTaskAccessChecker
    {
        $checker = self::getContainer()->get(RecordingTaskAccessChecker::class);
        self::assertInstanceOf(RecordingTaskAccessChecker::class, $checker);

        return $checker;
    }

    private function ref(AppUser $user): TimeTrackUserRef
    {
        return TimeTrackUserRefFactory::build($user);
    }
}
