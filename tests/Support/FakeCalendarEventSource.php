<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Tests\Support;

use DateTimeImmutable;
use Nowo\TimeTrackBundle\Calendar\CalendarCredential;
use Nowo\TimeTrackBundle\Calendar\CalendarEventSourceInterface;
use Nowo\TimeTrackBundle\Calendar\CalendarEventView;
use Nowo\TimeTrackBundle\Calendar\CalendarSyncException;
use Nowo\TimeTrackBundle\Enum\CalendarProvider;

/**
 * Hand-written calendar source for the sync tests (no real calendar): yields `$events`, or fails with `$errorCode`
 * while iterating (like a real adapter).
 */
final class FakeCalendarEventSource implements CalendarEventSourceInterface
{
    /** @var list<array{string, DateTimeImmutable, DateTimeImmutable}> credential token prefix, from, to */
    public array $calls = [];

    /**
     * @param list<CalendarEventView> $events
     */
    public function __construct(
        private readonly CalendarProvider $provider = CalendarProvider::Google,
        public array $events = [],
        public ?string $errorCode = null,
    ) {
    }

    public function provider(): CalendarProvider
    {
        return $this->provider;
    }

    public function fetch(CalendarCredential $credential, DateTimeImmutable $from, DateTimeImmutable $to): iterable
    {
        $this->calls[] = [$credential->reveal(), $from, $to];
        if ($this->errorCode !== null) {
            throw new CalendarSyncException($this->errorCode);
        }

        yield from $this->events;
    }
}
