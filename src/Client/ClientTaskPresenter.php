<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Client;

use Nowo\TimeTrackBundle\Dto\TaskReference;
use Nowo\TimeTrackBundle\Entity\ActiveTimer;
use Nowo\TimeTrackBundle\Service\TimerService;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Core\User\UserInterface;
use Throwable;

use function is_int;
use function is_string;
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
     */
    public function __construct(
        private TimerService $timerService,
        private ?string $taskUrlOrigin = null,
        ?LoggerInterface $logger = null,
    ) {
        $this->logger = $logger ?? new NullLogger();
    }

    /**
     * Wire shape of a task in `GET tasks`.
     *
     * @return array{id: string, title: string, boardId: string|null, boardTitle: string|null, projectId: string|null, projectTitle: string|null, url: string|null}
     */
    public function task(TaskReference $task, Request $request): array
    {
        return [
            'id'         => $task->id,
            'title'      => $task->title,
            'boardId'    => $task->boardId,
            'boardTitle' => $task->boardTitle,
        ] + $this->details($task, $request);
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
            return $body + ['boardTitle' => null, 'projectId' => null, 'projectTitle' => null, 'url' => null];
        }

        return $body + ['boardTitle' => $task->boardTitle] + $this->details($task, $request);
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

        $parts = preg_match('/[\x00-\x20\x7F]/', $url) === 1 ? false : parse_url($url);
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
