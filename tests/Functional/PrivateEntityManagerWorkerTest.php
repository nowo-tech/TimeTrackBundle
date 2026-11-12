<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Tests\Functional;

use Nowo\TimeTrackBundle\Doctrine\TimeTrackEntityManager;
use Symfony\Component\HttpFoundation\Response;

use function array_replace_recursive;
use function sprintf;

/**
 * 1.23.0 (REQ-RUNTIME-002 rule 4 with `database.private_entity_manager: true`): every scenario of
 * {@see WorkerModeNoResetTest} again with the bundle's private entity manager — one kernel, two people, the container's
 * `http_kernel->handle()` and `terminate()` called directly (never `Kernel::handle()`, which would run the services
 * resetter from the second request on). The private manager is dropped at every request boundary by the bundle itself
 * and a row changed between two requests is read fresh.
 */
final class PrivateEntityManagerWorkerTest extends WorkerModeNoResetTest
{
    protected static function timeTrackConfig(): array
    {
        return array_replace_recursive(parent::timeTrackConfig(), ['database' => ['private_entity_manager' => true]]);
    }

    public function testThePrivateManagerIsDroppedAtEveryBoundaryAndNeverServesARowOfAnotherRequest(): void
    {
        $other   = $this->createUser('other@example.com');
        $people  = ['person' => $this->login(), 'other' => $this->login('other@example.com')];
        $private = self::getContainer()->get(TimeTrackEntityManager::class);
        self::assertInstanceOf(TimeTrackEntityManager::class, $private);
        self::assertNotNull($other->getId());

        for ($round = 0; $round < 100; ++$round) {
            foreach ($people as $name => $token) {
                $this->call('POST', '/api/time-track/timer/start', $token, ['taskId' => 'task-1', 'note' => $name . '-' . $round, 'clientType' => 'desktop'], Response::HTTP_CREATED);
                self::assertFalse($private->isCreated(), sprintf('round %d: dropped after the request of %s', $round, $name));
                // Changed behind the bundle's back between two requests: the next request reads it fresh.
                $this->entityManager()->getConnection()->executeStatement("UPDATE time_track_active_timers SET metadata = '{\"note\":\"sql-" . $round . "\"}' WHERE task_title_snapshot IS NOT NULL AND metadata LIKE '%" . $name . '-' . $round . "%'");
                $timer = $this->call('GET', '/api/time-track/timer', $token)['timer'] ?? null;
                self::assertIsArray($timer);
                self::assertSame('sql-' . $round, $timer['metadata']['note'] ?? null, sprintf('round %d: %s reads the fresh row', $round, $name));
                $this->call('POST', '/api/time-track/timer/stop', $token);
                self::assertFalse($private->isCreated());
            }
        }
    }
}
