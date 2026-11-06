<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Meeting;

use DateTimeImmutable;
use DateTimeZone;
use Nowo\TimeTrackBundle\Security\TaskAccessCheckerInterface;
use Nowo\TimeTrackBundle\Support\UserIdResolver;
use Symfony\Component\Security\Core\User\UserInterface;

use function array_values;
use function count;
use function in_array;
use function is_string;
use function max;
use function mb_strlen;
use function mb_substr;
use function preg_match;
use function preg_replace;
use function timezone_identifiers_list;
use function trim;
use function usort;

/**
 * Since 1.14 (contract §22.4, §22.6): shapes the provider's meetings into the API payload, applying the privacy rules
 * again whatever the host returned — field whitelist in a fixed order; private events lose title, attendees, external
 * count and task links (the join link stays, Q-B16); titles trimmed to 200 characters without control or bidi
 * characters; join links re-detected ({@see MeetingLinkDetector}); at most 50 internal attendees `{id, name}` without
 * the caller; task links only for tasks the caller can track; ids, times and lengths checked (`end ≤ start`, > 24 h,
 * outside the window or a malformed id → dropped and counted). Sorted by start, then id; cut at the limit.
 */
final readonly class MeetingResponder
{
    /** Longest title, in code points. */
    public const TITLE_MAX_LENGTH = 200;

    /** Most attendees per meeting. */
    public const MAX_ATTENDEES = 50;

    /** Longest meeting, in seconds. */
    public const MAX_SECONDS = 86_400;

    /** Meeting id format. */
    public const ID_PATTERN = '/^mtg_[A-Za-z0-9_-]{16,64}$/D';

    /** Control, format (bidi, zero-width) and separator characters removed from titles and names. */
    private const UNSAFE_CHARACTERS = '/[\p{Cc}\p{Cf}\p{Zl}\p{Zp}]+/u';

    /**
     * Receives the link detector and the track check.
     */
    public function __construct(
        private MeetingLinkDetector $links,
        private TaskAccessCheckerInterface $taskAccess,
    ) {
    }

    /**
     * The `meetings` list and how many provider rows were dropped.
     *
     * @return array{meetings: list<array<string, mixed>>, truncated: bool, dropped: int}
     */
    public function respond(UserInterface $user, MeetingQuery $query, MeetingPage $page): array
    {
        $callerId  = UserIdResolver::getId($user);
        $trackable = [];
        $views     = [];
        $dropped   = 0;
        foreach ($page->meetings as $view) {
            $seconds = $view->end->getTimestamp() - $view->start->getTimestamp();
            if (preg_match(self::ID_PATTERN, $view->id) !== 1 || $seconds <= 0 || $seconds > self::MAX_SECONDS
                || $view->start >= $query->to || $view->end <= $query->from) {
                ++$dropped;
                continue;
            }
            $views[] = $view;
        }
        usort($views, static fn (MeetingView $a, MeetingView $b): int => [$a->start->getTimestamp(), $a->id] <=> [$b->start->getTimestamp(), $b->id]);
        $truncated = $page->truncated || count($views) > $query->limit;
        $meetings  = [];
        foreach ($views as $view) {
            if (count($meetings) === $query->limit) {
                break;
            }
            $meetings[] = $this->meeting($user, $callerId, $query->timeZone, $view, $trackable);
        }

        return ['meetings' => $meetings, 'truncated' => $truncated, 'dropped' => $dropped];
    }

    /**
     * @param array<string, bool> $trackable
     *
     * @return array<string, mixed>
     */
    private function meeting(UserInterface $user, string $callerId, DateTimeZone $timeZone, MeetingView $view, array &$trackable): array
    {
        [$start, $end, $date] = $view->allDay ? $this->allDay($view, $timeZone) : [$view->start, $view->end, null];
        $link                 = $this->links->detect($view->joinUrl);
        $private              = $view->private;

        return [
            'id'            => $view->id,
            'start'         => $this->utc($start),
            'end'           => $this->utc($end),
            'timeZone'      => $view->timeZone !== null && in_array($view->timeZone, timezone_identifiers_list(), true) ? $view->timeZone : null,
            'allDay'        => $view->allDay,
            'date'          => $date,
            'busy'          => $view->busy,
            'private'       => $private,
            'title'         => $private ? null : $this->text($view->title, self::TITLE_MAX_LENGTH),
            'joinUrl'       => $link['url'] ?? null,
            'joinProvider'  => $link['provider'] ?? null,
            'organizerIsMe' => $view->organizerIsMe,
            'attendees'     => $private ? [] : $this->attendees($view, $callerId),
            'externalCount' => $private || $view->externalCount === null ? null : max(0, $view->externalCount),
            'taskLinks'     => $private ? [] : $this->taskLinks($user, $view, $trackable),
            'updatedAt'     => $this->utc($view->updatedAt),
        ];
    }

    /**
     * All-day bounds in the request zone: the first local day, its local midnight and the next local midnight at or
     * after the end.
     *
     * @return array{0: DateTimeImmutable, 1: DateTimeImmutable, 2: string}
     */
    private function allDay(MeetingView $view, DateTimeZone $timeZone): array
    {
        $first = $view->start->setTimezone($timeZone)->setTime(0, 0);
        $last  = $view->end->setTimezone($timeZone);
        $end   = $last->setTime(0, 0);
        if ($end < $last) {
            $end = $end->modify('+1 day');
        }

        return [$first, max($end, $first->modify('+1 day')), $first->format('Y-m-d')];
    }

    /**
     * @return list<array{id: string, name: string}>
     */
    private function attendees(MeetingView $view, string $callerId): array
    {
        $attendees = [];
        foreach ($view->attendees as $attendee) {
            $name = $this->text($attendee->name, self::TITLE_MAX_LENGTH);
            if ($attendee->id === '' || mb_strlen($attendee->id) > 64 || $attendee->id === $callerId || $name === null) {
                continue;
            }
            $attendees[] = ['id' => $attendee->id, 'name' => $name];
            if (count($attendees) === self::MAX_ATTENDEES) {
                break;
            }
        }

        return $attendees;
    }

    /**
     * @param array<string, bool> $trackable
     *
     * @return list<array{taskId: string, key: string}>
     */
    private function taskLinks(UserInterface $user, MeetingView $view, array &$trackable): array
    {
        $links = [];
        foreach ($view->taskLinks as $link) {
            if ($link->taskId === '' || mb_strlen($link->taskId) > 36 || $link->key === '' || mb_strlen($link->key) > 32 || isset($links[$link->taskId])) {
                continue;
            }
            if ($trackable[$link->taskId] ??= $this->taskAccess->canTrack($user, $link->taskId)) {
                $links[$link->taskId] = ['taskId' => $link->taskId, 'key' => $link->key];
            }
        }

        return array_values($links);
    }

    private function text(?string $value, int $max): ?string
    {
        if (!is_string($value)) {
            return null;
        }
        $clean = trim((string) preg_replace('/\s+/u', ' ', (string) preg_replace(self::UNSAFE_CHARACTERS, ' ', $value)));

        return $clean === '' ? null : mb_substr($clean, 0, $max);
    }

    private function utc(DateTimeImmutable $time): string
    {
        return $time->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');
    }
}
