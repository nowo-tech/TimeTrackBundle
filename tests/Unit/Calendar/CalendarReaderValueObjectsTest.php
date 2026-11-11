<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Tests\Unit\Calendar;

use DateTimeImmutable;
use InvalidArgumentException;
use LogicException;
use Nowo\TimeTrackBundle\Calendar\CalendarAttendee;
use Nowo\TimeTrackBundle\Calendar\CalendarDescriptor;
use Nowo\TimeTrackBundle\Calendar\CalendarFields;
use Nowo\TimeTrackBundle\Calendar\CalendarMeetingBatch;
use Nowo\TimeTrackBundle\Calendar\CalendarMeetingView;
use Nowo\TimeTrackBundle\Calendar\CalendarPkce;
use Nowo\TimeTrackBundle\Calendar\CalendarTokens;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

use function base64_encode;
use function hash;
use function print_r;
use function rtrim;
use function serialize;
use function sprintf;
use function str_repeat;
use function strlen;
use function strtr;
use function unserialize;

/**
 * Since 1.19 (Beacon spec 210): the in-memory values of the calendar readers and OAuth helpers never leak their
 * personal data or secrets through debug output and cannot be serialized.
 */
final class CalendarReaderValueObjectsTest extends TestCase
{
    public function testDescriptor(): void
    {
        $calendar = new CalendarDescriptor('cal-1', 'Team', true);

        self::assertSame(['cal-1', 'Team', true], [$calendar->id, $calendar->name, $calendar->primary]);
    }

    public function testAttendeeIsRedactedAndNotSerializable(): void
    {
        $attendee = new CalendarAttendee('fake.person@example.test', true, false, true);

        self::assertSame(['email' => '[redacted]', 'self' => true, 'declined' => false, 'resource' => true], $attendee->__debugInfo());
        self::assertStringNotContainsString('fake.person', print_r($attendee, true));
        $this->assertNotSerializable($attendee);
    }

    public function testMeetingViewIsRedactedAndNotSerializable(): void
    {
        $start   = new DateTimeImmutable('2026-11-12T09:00:00Z');
        $meeting = new CalendarMeetingView('evt', null, null, 'Secret title', 'Body', 'Room', $start, $start->modify('+1 hour'));

        self::assertSame(['eventId' => '[redacted]', 'title' => '[redacted]', 'description' => '[redacted]', 'start' => $start, 'end' => $meeting->end], $meeting->__debugInfo());
        self::assertSame([[], [], false, null], [$meeting->attendees, $meeting->joinCandidates, $meeting->private, $meeting->timeZone]);
        $this->assertNotSerializable($meeting);
        $batch = new CalendarMeetingBatch([$meeting]);
        self::assertTrue($batch->complete);
        $this->assertNotSerializable($batch);
    }

    public function testTokensAreRedactedAndBecomeACredential(): void
    {
        $tokens = new CalendarTokens('access-REDACTED-TEST', 'refresh-REDACTED-TEST', null);

        self::assertSame('access-REDACTED-TEST', $tokens->credential()->reveal());
        self::assertSame(['accessToken' => '[redacted]', 'refreshToken' => '[redacted]', 'expiresAt' => null], $tokens->__debugInfo());
        $this->assertNotSerializable($tokens);
    }

    public function testPkcePairs(): void
    {
        $pair = CalendarPkce::create();
        self::assertSame(86, strlen($pair->verifier));
        self::assertSame(rtrim(strtr(base64_encode(hash('sha256', $pair->verifier, true)), '+/', '-_'), '='), $pair->challenge);
        self::assertSame($pair->challenge, CalendarPkce::fromVerifier($pair->verifier)->challenge);
        self::assertNotSame($pair->verifier, CalendarPkce::create()->verifier);
        self::assertSame(['verifier' => '[redacted]', 'challenge' => $pair->challenge], $pair->__debugInfo());
        // RFC 7636 appendix B.
        self::assertSame('E9Melhoa2OwvFrEMTJguCHaoeK1t8URWbuGJSstw-cM', CalendarPkce::fromVerifier('dBjftJeZ4CVP-mB92K27uhbUJU1p1r_wW1gFWFOEjXk')->challenge);
        $this->assertNotSerializable($pair);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function badVerifiers(): iterable
    {
        yield 'too short' => [str_repeat('a', 42)];
        yield 'too long' => [str_repeat('a', 129)];
        yield 'reserved character' => [str_repeat('a', 42) . '+'];
    }

    #[DataProvider('badVerifiers')]
    public function testPkceRefusesBadVerifiers(string $verifier): void
    {
        $this->expectException(InvalidArgumentException::class);

        CalendarPkce::fromVerifier($verifier);
    }

    public function testFields(): void
    {
        self::assertSame('', CalendarFields::text(3));
        self::assertSame('x', CalendarFields::text('x'));
        self::assertNull(CalendarFields::email(''));
        self::assertNull(CalendarFields::email(null));
        self::assertSame('fake@example.test', CalendarFields::email('Fake@Example.TEST'));
        self::assertSame('Europe/Madrid', CalendarFields::zone('Europe/Madrid'));
        self::assertNull(CalendarFields::zone('Romance Standard Time'), 'Windows zone names are not IANA');
        self::assertNull(CalendarFields::zone(str_repeat('a', 65)));
        self::assertNull(CalendarFields::zone(null));
        self::assertSame('primary', CalendarFields::calendarId('primary'));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function badCalendarIds(): iterable
    {
        yield 'empty' => [''];
        yield 'too long' => [str_repeat('c', 513)];
        yield 'control character' => ["cal\n1"];
    }

    #[DataProvider('badCalendarIds')]
    public function testFieldsRefuseBadCalendarIds(string $calendarId): void
    {
        $this->expectException(InvalidArgumentException::class);

        CalendarFields::calendarId($calendarId);
    }

    private function assertNotSerializable(object $value): void
    {
        try {
            serialize($value);
            self::fail('serialize() must throw');
        } catch (LogicException) {
            $this->addToAssertionCount(1);
        }
        try {
            unserialize(sprintf('O:%d:"%s":0:{}', strlen($value::class), $value::class));
            self::fail('unserialize() must throw');
        } catch (LogicException) {
            $this->addToAssertionCount(1);
        }
    }
}
