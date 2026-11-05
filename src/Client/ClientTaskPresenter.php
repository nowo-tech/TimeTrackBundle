<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Client;

use Nowo\TimeTrackBundle\Dto\TaskReference;
use Nowo\TimeTrackBundle\Entity\ActiveTimer;
use Nowo\TimeTrackBundle\Repository\TaskEffortRepositoryInterface;
use Nowo\TimeTrackBundle\Service\TimerService;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Core\User\UserInterface;
use Throwable;

use function is_int;
use function is_string;
use function max;
use function mb_strlen;
use function mb_substr;
use function parse_url;
use function preg_match;
use function strlen;
use function strtolower;
use function trim;

/**
 * Task details of the client API (contract v0.3, since 1.10): `projectId`, `projectTitle` and `url` on `tasks`, and
 * `boardTitle`, `projectId`, `projectTitle` and `url` on every `timer` object, resolved live through the task provider
 * for the token user. Output rules: `projectId` 1–64 chars, `projectTitle` trimmed and cut to 255 chars, `url` only when
 * it is an absolute https URL without credentials on the expected origin (`client_api.task_url.origin`, else the request
 * origin); anything else is null. A URL is never logged.
 *
 * @internal
 */
final readonly class ClientTaskPresenter
{
    /** Max length of a published task URL. */
    public const URL_MAX_LENGTH = 2048;

    /** Max length of a published project title. */
    public const PROJECT_TITLE_MAX_LENGTH = 255;

    /** Max length of a published project id. */
    public const PROJECT_ID_MAX_LENGTH = 64;

    private LoggerInterface $logger;

    /**
     * @param TimerService $timerService Resolves the timer's task for the token user (through the task provider)
     * @param string|null $taskUrlOrigin `client_api.task_url.origin`: exact `https://host[:port]` (null = the request origin)
     * @param LoggerInterface|null $logger Logs dropped URLs and provider failures without data (defaults to no logging)
     * @param TaskEffortRepositoryInterface|null $effort Logged time per task (since 1.13; null = unknown unless the provider gives it)
     */
    public function __construct(
        private TimerService $timerService,
        private ?string $taskUrlOrigin = null,
        ?LoggerInterface $logger = null,
        private ?TaskEffortRepositoryInterface $effort = null,
    ) {
        $this->logger = $logger ?? new NullLogger();
    }

    /**
     * Wire shape of a task in `GET tasks`.
     *
     * @return array{id: string, title: string, boardId: string|null, boardTitle: string|null, projectId: string|null, projectTitle: string|null, url: string|null, estimateSeconds: int|null, loggedSeconds: int|null, remainingSeconds: int|null}
     */
    public function task(TaskReference $task, Request $request, ?int $logged = null): array
    {
        return [
            'id'         => $task->id,
            'title'      => $task->title,
            'boardId'    => $task->boardId,
            'boardTitle' => $task->boardTitle,
        ] + $this->details($task, $request) + $this->effort($task, $logged ?? $this->loggedOf([$task])[$task->id] ?? null);
    }

    /**
     * Since 1.13: the wire shape of a list of tasks, with one logged-time query for the whole list.
     *
     * @param list<TaskReference> $tasks
     *
     * @return list<array<string, mixed>>
     */
    public function tasks(array $tasks, Request $request): array
    {
        $logged = $this->loggedOf($tasks);
        $out    = [];
        foreach ($tasks as $task) {
            $out[] = $this->task($task, $request, $logged[$task->id] ?? null);
        }

        return $out;
    }

    /**
     * Wire shape of a timer: the 1.9 keys, then the live task details (null when the task is gone, no longer visible to
     * the user, or the provider fails).
     *
     * @param TaskReference|null $known The task when the caller already resolved it (no second provider call)
     *
     * @return array<string, mixed>
     */
    public function timer(ActiveTimer $timer, UserInterface $user, Request $request, ?TaskReference $known = null): array
    {
        $task = $known instanceof TaskReference && $known->id === $timer->getTaskId() ? $known : $this->resolve($timer->getTaskId(), $user);
        $body = $timer->toArray();

        if (!$task instanceof TaskReference) {
            return $body + ['boardTitle' => null, 'projectId' => null, 'projectTitle' => null, 'url' => null, 'estimateSeconds' => null, 'loggedSeconds' => null, 'remainingSeconds' => null];
        }

        return $body + ['boardTitle' => $task->boardTitle] + $this->details($task, $request) + $this->effort($task, $this->loggedOf([$task])[$task->id] ?? null);
    }

    /**
     * The URL when it may be published for this request, else null.
     */
    public function safeUrl(?string $url, Request $request): ?string
    {
        if ($url === null || $url === '') {
            return null;
        }

        $reason = $this->urlProblem($url, $request);
        if ($reason === null) {
            return $url;
        }

        $this->logger->debug('TimeTrack task URL dropped.', ['bundle' => 'time_track', 'action' => 'task_url_dropped', 'reason' => $reason]);

        return null;
    }

    /**
     * Exact `https://host[:port]` origin of a URL (host lower-cased, port 443 dropped), or null when it is not one.
     */
    public static function origin(string $url): ?string
    {
        $parts = parse_url($url);
        if ($parts === false || !isset($parts['scheme'], $parts['host']) || strtolower($parts['scheme']) !== 'https' || $parts['host'] === '') {
            return null;
        }

        $port = isset($parts['port']) && is_int($parts['port']) && $parts['port'] !== 443 ? ':' . $parts['port'] : '';

        return 'https://' . strtolower($parts['host']) . $port;
    }

    /**
     * @return array{projectId: string|null, projectTitle: string|null, url: string|null}
     */
    private function details(TaskReference $task, Request $request): array
    {
        $projectId = is_string($task->projectId) ? trim($task->projectId) : '';
        $title     = is_string($task->projectTitle) ? trim($task->projectTitle) : '';

        return [
            'projectId'    => $projectId !== '' && mb_strlen($projectId) <= self::PROJECT_ID_MAX_LENGTH ? $projectId : null,
            'projectTitle' => $title !== '' ? mb_substr($title, 0, self::PROJECT_TITLE_MAX_LENGTH) : null,
            'url'          => $this->safeUrl($task->url, $request),
        ];
    }

    /**
     * Since 1.13: `estimateSeconds`, `loggedSeconds` (a total of every user's closed time on the task, never a per-user
     * figure) and `remainingSeconds = max(0, estimate − logged)`; each null when unknown.
     *
     * @return array{estimateSeconds: int|null, loggedSeconds: int|null, remainingSeconds: int|null}
     */
    private function effort(TaskReference $task, ?int $logged): array
    {
        $estimate = is_int($task->estimateSeconds) && $task->estimateSeconds >= 0 ? $task->estimateSeconds : null;
        $logged   = is_int($task->loggedSeconds) && $task->loggedSeconds >= 0 ? $task->loggedSeconds : $logged;

        return [
            'estimateSeconds'  => $estimate,
            'loggedSeconds'    => $logged,
            'remainingSeconds' => $estimate !== null && $logged !== null ? max(0, $estimate - $logged) : null,
        ];
    }

    /**
     * Logged seconds of the tasks the provider did not total itself (one query).
     *
     * @param list<TaskReference> $tasks
     *
     * @return array<string, int>
     */
    private function loggedOf(array $tasks): array
    {
        $ids = [];
        foreach ($tasks as $task) {
            if ($task->loggedSeconds === null) {
                $ids[] = $task->id;
            }
        }
        if ($ids === [] || !$this->effort instanceof TaskEffortRepositoryInterface) {
            return [];
        }
        try {
            return $this->effort->loggedSecondsByTask($ids);
        } catch (Throwable) {
            $this->logger->warning('TimeTrack task effort could not be read.', ['bundle' => 'time_track', 'action' => 'task_effort_failed']);

            return [];
        }
    }

    private function resolve(string $taskId, UserInterface $user): ?TaskReference
    {
        try {
            return $this->timerService->findTask($user, $taskId);
        } catch (Throwable) {
            $this->logger->warning('TimeTrack timer task details could not be resolved.', ['bundle' => 'time_track', 'action' => 'timer_task_resolve_failed']);

            return null;
        }
    }

    /**
     * Why the URL may not be published (`length`, `syntax`, `scheme`, `userinfo`, `origin`), or null when it may.
     */
    private function urlProblem(string $url, Request $request): ?string
    {
        if (strlen($url) > self::URL_MAX_LENGTH) {
            return 'length';
        }

        // Any space or control character (Unicode included, EF-I2); invalid UTF-8 (preg_match false) is refused too.
        $parts = preg_match('/[\x00-\x20\x7F\p{Z}\p{Cc}]/u', $url) !== 0 ? false : parse_url($url);
        if ($parts === false || !isset($parts['scheme'], $parts['host'])) {
            return 'syntax';
        }

        if (strtolower($parts['scheme']) !== 'https') {
            return 'scheme';
        }

        if (isset($parts['user']) || isset($parts['pass'])) {
            return 'userinfo';
        }

        $expected = $this->taskUrlOrigin !== null ? self::origin($this->taskUrlOrigin) : self::origin($request->getSchemeAndHttpHost());

        return $expected !== null && self::origin($url) === $expected ? null : 'origin';
    }
}
