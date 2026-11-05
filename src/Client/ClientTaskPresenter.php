<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Client;

use Nowo\TimeTrackBundle\Dto\TaskReference;
use Nowo\TimeTrackBundle\Entity\ActiveTimer;
use Nowo\TimeTrackBundle\Repository\TaskEffortRepositoryInterface;
use Nowo\TimeTrackBundle\Service\TeamAccessGuard;
use Nowo\TimeTrackBundle\Service\TimerService;
use Nowo\TimeTrackBundle\Support\UserIdResolver;
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
     * @param TeamAccessGuard|null $effortAccess Who may see the all-users total ({@see TeamAccessGuard::canSeeTaskEffortTotal()}; null = nobody)
     */
    public function __construct(
        private TimerService $timerService,
        private ?string $taskUrlOrigin = null,
        ?LoggerInterface $logger = null,
        private ?TaskEffortRepositoryInterface $effort = null,
        private ?TeamAccessGuard $effortAccess = null,
    ) {
        $this->logger = $logger ?? new NullLogger();
    }

    /**
     * Wire shape of a task in `GET tasks`.
     *
     * @param UserInterface|null $user The token user (null: the logged figures are unknown unless the provider gives them)
     *
     * @return array{id: string, title: string, boardId: string|null, boardTitle: string|null, projectId: string|null, projectTitle: string|null, url: string|null, estimateSeconds: int|null, loggedSeconds: int|null, loggedSecondsScope: string|null, remainingSeconds: int|null}
     */
    public function task(TaskReference $task, Request $request, ?UserInterface $user = null): array
    {
        return $this->taskWith($task, $request, $user instanceof UserInterface ? $this->loggedOf([$task], $user)[$task->id] : null);
    }

    /**
     * Since 1.13: the wire shape of a list of tasks for the user, with one logged-time query for the whole list (two
     * when the user manages some of the tasks).
     *
     * @param list<TaskReference> $tasks
     *
     * @return list<array<string, mixed>>
     */
    public function tasks(array $tasks, Request $request, UserInterface $user): array
    {
        $logged = $this->loggedOf($tasks, $user);
        $out    = [];
        foreach ($tasks as $task) {
            $out[] = $this->taskWith($task, $request, $logged[$task->id] ?? null);
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
            return $body + ['boardTitle' => null, 'projectId' => null, 'projectTitle' => null, 'url' => null, 'estimateSeconds' => null, 'loggedSeconds' => null, 'loggedSecondsScope' => null, 'remainingSeconds' => null];
        }

        return $body + ['boardTitle' => $task->boardTitle] + $this->details($task, $request) + $this->effort($task, $this->loggedOf([$task], $user)[$task->id]);
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
     * @param array{seconds: int|null, scope: string}|null $logged
     *
     * @return array{id: string, title: string, boardId: string|null, boardTitle: string|null, projectId: string|null, projectTitle: string|null, url: string|null, estimateSeconds: int|null, loggedSeconds: int|null, loggedSecondsScope: string|null, remainingSeconds: int|null}
     */
    private function taskWith(TaskReference $task, Request $request, ?array $logged): array
    {
        return [
            'id'         => $task->id,
            'title'      => $task->title,
            'boardId'    => $task->boardId,
            'boardTitle' => $task->boardTitle,
        ] + $this->details($task, $request) + $this->effort($task, $logged);
    }

    /**
     * Since 1.13: `estimateSeconds`, `loggedSeconds` (the user's own closed time on the task; every user's total only
     * for users who can manage the task — owner decision 2026-11-05, audit GB-M3; a provider value is the host's own
     * figure for that user), `loggedSecondsScope` (`own` or `all`: whose time `loggedSeconds` counts; null when
     * `loggedSeconds` is null or the audience is unknown) and `remainingSeconds = max(0, estimate − logged)`; each null
     * when unknown.
     *
     * @param array{seconds: int|null, scope: string}|null $logged
     *
     * @return array{estimateSeconds: int|null, loggedSeconds: int|null, loggedSecondsScope: string|null, remainingSeconds: int|null}
     */
    private function effort(TaskReference $task, ?array $logged): array
    {
        $estimate = is_int($task->estimateSeconds) && $task->estimateSeconds >= 0 ? $task->estimateSeconds : null;
        $given    = is_int($task->loggedSeconds) && $task->loggedSeconds >= 0 ? $task->loggedSeconds : null;
        $seconds  = $given ?? $logged['seconds'] ?? null;

        return [
            'estimateSeconds'    => $estimate,
            'loggedSeconds'      => $seconds,
            'loggedSecondsScope' => $seconds !== null ? $logged['scope'] ?? null : null,
            'remainingSeconds'   => $estimate !== null && $seconds !== null ? max(0, $estimate - $seconds) : null,
        ];
    }

    /**
     * Logged seconds and their audience per task: the user's own total (`own`), or every user's total (`all`) for the
     * tasks the user may manage ({@see TeamAccessGuard::canSeeTaskEffortTotal()}, deny by default). A task whose
     * provider gives its own `loggedSeconds` is not queried; its audience follows the same rule (the provider must
     * apply it). Seconds are null when unknown.
     *
     * @param list<TaskReference> $tasks
     *
     * @return array<string, array{seconds: int|null, scope: string}>
     */
    private function loggedOf(array $tasks, UserInterface $user): array
    {
        $out     = [];
        $own     = [];
        $managed = [];
        foreach ($tasks as $task) {
            $all            = $this->effortAccess instanceof TeamAccessGuard && $this->effortAccess->canSeeTaskEffortTotal($user, $task->id);
            $out[$task->id] = ['seconds' => null, 'scope' => $all ? 'all' : 'own'];
            if ($task->loggedSeconds === null) {
                if ($all) {
                    $managed[] = $task->id;
                } else {
                    $own[] = $task->id;
                }
            }
        }
        if (($own === [] && $managed === []) || !$this->effort instanceof TaskEffortRepositoryInterface) {
            return $out;
        }
        try {
            $totals = ($own === [] ? [] : $this->effort->loggedSecondsByTask($own, UserIdResolver::getId($user)))
                + ($managed === [] ? [] : $this->effort->loggedSecondsByTask($managed));
        } catch (Throwable) {
            $this->logger->warning('TimeTrack task effort could not be read.', ['bundle' => 'time_track', 'action' => 'task_effort_failed']);

            return $out;
        }
        foreach ($totals as $taskId => $seconds) {
            if (isset($out[(string) $taskId])) {
                $out[(string) $taskId]['seconds'] = $seconds;
            }
        }

        return $out;
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
