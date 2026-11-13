<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Tests\Fixtures\HostTimer;

use Doctrine\ORM\EntityManagerInterface;
use Nowo\TimeTrackBundle\Port\TimeTrackUserRefFactory;
use Nowo\TimeTrackBundle\Tests\Fixtures\FunctionalEntity\AppUser;
use Nowo\TimeTrackBundle\Timer\HostTimerApi;
use Nowo\TimeTrackBundle\Timer\TimerEntrySnapshot;
use Nowo\TimeTrackBundle\Timer\TimerSnapshot;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

use function is_string;
use function str_starts_with;
use function substr;

/**
 * A host's own controller over {@see HostTimerApi} (Beacon's MCP `timer_*` tools in spirit): the principal comes from an
 * `X-Test-Token: bt_<e-mail>` header (a stand-in for Beacon's `bt_` Task-API token), the host resolves the account and
 * asks the bundle. Stateless.
 */
final readonly class HostTimerTestController
{
    public function __construct(private HostTimerApi $timers, private EntityManagerInterface $entityManager, private TimeTrackUserRefFactory $refs)
    {
    }

    public function __invoke(Request $request, string $action): JsonResponse
    {
        $token = $request->headers->get('X-Test-Token');
        if (!is_string($token) || !str_starts_with($token, 'bt_')) {
            return new JsonResponse(['error' => 'unauthenticated'], Response::HTTP_UNAUTHORIZED);
        }
        $user = $this->entityManager->getRepository(AppUser::class)->findOneBy(['email' => substr($token, 3)]);
        if (!$user instanceof AppUser) {
            return new JsonResponse(['error' => 'unauthenticated'], Response::HTTP_UNAUTHORIZED);
        }
        $actor   = $this->refs->fromUser($user);
        $payload = $request->toArray();
        $channel = is_string($payload['channel'] ?? null) ? $payload['channel'] : 'mcp';
        $note    = is_string($payload['note'] ?? null) ? $payload['note'] : null;

        return match ($action) {
            'start' => (function () use ($actor, $payload, $note, $channel): JsonResponse {
                $start = $this->timers->start($actor, is_string($payload['taskId'] ?? null) ? $payload['taskId'] : '', $note, $channel);

                return new JsonResponse(['timer' => $this->timer($start->timer), 'stopped' => $start->stopped instanceof TimerEntrySnapshot ? $this->entry($start->stopped) : null], Response::HTTP_CREATED);
            })(),
            'stop'    => new JsonResponse(['entry' => ($entry = $this->timers->stop($actor, $note)) instanceof TimerEntrySnapshot ? $this->entry($entry) : null]),
            'current' => new JsonResponse(['timer' => ($timer = $this->timers->current($actor)) instanceof TimerSnapshot ? $this->timer($timer) : null]),
            default   => new JsonResponse(['error' => 'not_found'], Response::HTTP_NOT_FOUND),
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function timer(TimerSnapshot $timer): array
    {
        return ['id' => $timer->timerId, 'taskId' => $timer->taskId, 'channel' => $timer->channel, 'note' => $timer->note];
    }

    /**
     * @return array<string, mixed>
     */
    private function entry(TimerEntrySnapshot $entry): array
    {
        return ['id' => $entry->entryId, 'taskId' => $entry->taskId, 'durationSeconds' => $entry->durationSeconds, 'channel' => $entry->channel, 'serviceAccount' => $entry->serviceAccount];
    }
}
