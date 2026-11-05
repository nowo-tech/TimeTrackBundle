<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Tests\Support;

use DateTimeImmutable;
use Nowo\TimeTrackBundle\Meeting\MeetingAttendeeView;
use Nowo\TimeTrackBundle\Meeting\MeetingTaskLink;
use Nowo\TimeTrackBundle\Meeting\MeetingView;

/**
 * Hand-written fake meetings (no real calendar, person or link). Every text carries a marker so tests can prove it
 * never reaches a log line, an event or an exception.
 */
final class MeetingFixtures
{
    public const TITLE_MARKER = 'ZZ-MEETING-TITLE-MARKER';

    public const NAME_MARKER = 'ZZ-ATTENDEE-NAME-MARKER';

    public const LINK = 'https://meet.google.com/zzz-fake-link?pwd=ZZPASSCODEMARKER';

    /**
     * @param array<string, mixed> $overrides MeetingView constructor arguments by name
     */
    public static function meeting(string $id, string $start, int $minutes = 45, array $overrides = []): MeetingView
    {
        $from = new DateTimeImmutable($start);
        $args = $overrides + [
            'id'            => $id,
            'start'         => $from,
            'end'           => $from->modify('+' . $minutes . ' minutes'),
            'timeZone'      => 'Europe/Madrid',
            'allDay'        => false,
            'busy'          => true,
            'private'       => false,
            'title'         => 'Sprint review ' . self::TITLE_MARKER,
            'joinUrl'       => self::LINK,
            'organizerIsMe' => false,
            'attendees'     => [new MeetingAttendeeView('u-luis', 'Luis ' . self::NAME_MARKER)],
            'externalCount' => 2,
            'taskLinks'     => [new MeetingTaskLink('task-1', '#task-1')],
            'updatedAt'     => new DateTimeImmutable('2026-11-05T06:58:41Z'),
        ];

        return new MeetingView(...$args);
    }
}
