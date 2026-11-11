<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Tests\Unit\Calendar;

use DateTimeImmutable;
use LogicException;
use Monolog\Formatter\JsonFormatter;
use Monolog\Formatter\LineFormatter;
use Monolog\Formatter\NormalizerFormatter;
use Monolog\Level;
use Monolog\LogRecord;
use Nowo\TimeTrackBundle\Calendar\CalendarAttendee;
use Nowo\TimeTrackBundle\Calendar\CalendarCredential;
use Nowo\TimeTrackBundle\Calendar\CalendarMeetingBatch;
use Nowo\TimeTrackBundle\Calendar\CalendarMeetingView;
use Nowo\TimeTrackBundle\Calendar\CalendarPkce;
use Nowo\TimeTrackBundle\Calendar\CalendarTokens;
use Nowo\TimeTrackBundle\Calendar\SensitiveValue;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Serializer\Encoder\JsonEncoder;
use Symfony\Component\Serializer\Normalizer\DateTimeNormalizer;
use Symfony\Component\Serializer\Normalizer\JsonSerializableNormalizer;
use Symfony\Component\Serializer\Normalizer\ObjectNormalizer;
use Symfony\Component\Serializer\Serializer;

use function get_object_vars;
use function json_encode;
use function print_r;
use function serialize;
use function str_repeat;
use function var_export;

use const JSON_THROW_ON_ERROR;

/**
 * Since 1.19 (audit LZ-L1): tokens, the PKCE verifier and meeting personal data never come out of a generic channel —
 * `json_encode()`, `(array)` casts, `get_object_vars()`, `var_export()`, `print_r()`, Symfony Serializer (with and
 * without the JsonSerializable normaliser) and Monolog's normaliser / JSON / line formatters. Every value here is a fake
 * marker built at run time.
 */
final class SensitiveValueChannelsTest extends TestCase
{
    /**
     * @return iterable<string, array{object, list<string>}>
     */
    public static function holders(): iterable
    {
        $access  = 'ACC' . str_repeat('x', 8) . '-MARKER';
        $refresh = 'REF' . str_repeat('y', 8) . '-MARKER';
        yield 'tokens' => [new CalendarTokens($access, $refresh, new DateTimeImmutable('2026-11-12T10:00:00Z')), [$access, $refresh]];
        yield 'credential' => [new CalendarCredential($access), [$access]];
        $pkce = CalendarPkce::create();
        yield 'pkce' => [$pkce, [$pkce->verifier()]];
        yield 'attendee' => [new CalendarAttendee('attendee.marker@example.test', true), ['attendee.marker']];
        $start   = new DateTimeImmutable('2026-11-12T09:00:00Z');
        $meeting = new CalendarMeetingView(
            'EVENT-MARKER',
            'SERIES-MARKER',
            'ORIGINAL-MARKER',
            'TITLE-MARKER',
            'DESCRIPTION-MARKER',
            'LOCATION-MARKER',
            $start,
            $start->modify('+1 hour'),
            organizerEmail: 'organizer.marker@example.test',
            attendees: [new CalendarAttendee('attendee.marker@example.test')],
            joinCandidates: ['https://meet.example.test/JOIN-MARKER'],
        );
        $personal = ['EVENT-MARKER', 'SERIES-MARKER', 'ORIGINAL-MARKER', 'TITLE-MARKER', 'DESCRIPTION-MARKER', 'LOCATION-MARKER', 'organizer.marker', 'attendee.marker', 'JOIN-MARKER'];
        yield 'meeting' => [$meeting, $personal];
        yield 'batch' => [new CalendarMeetingBatch([$meeting]), $personal];
        yield 'sensitive value' => [new SensitiveValue($access), [$access]];
    }

    /**
     * @param list<string> $secrets
     */
    #[DataProvider('holders')]
    public function testNoGenericChannelPrintsTheSecrets(object $holder, array $secrets): void
    {
        $serializers = [
            'serializer (json serializable)' => new Serializer([new JsonSerializableNormalizer(), new DateTimeNormalizer(), new ObjectNormalizer()], [new JsonEncoder()]),
            'serializer (object normaliser)' => new Serializer([new DateTimeNormalizer(), new ObjectNormalizer()], [new JsonEncoder()]),
        ];
        $record   = new LogRecord(new DateTimeImmutable(), 'app', Level::Info, 'calendar', ['holder' => $holder, 'list' => [$holder]]);
        $channels = [
            'json_encode'         => json_encode($holder, JSON_THROW_ON_ERROR),
            'json_encode (array)' => json_encode((array) $holder, JSON_THROW_ON_ERROR),
            'get_object_vars'     => json_encode(get_object_vars($holder), JSON_THROW_ON_ERROR),
            'var_export'          => var_export($holder, true),
            'var_export (array)'  => var_export((array) $holder, true),
            'print_r'             => print_r($holder, true),
            // The internal wrapper itself, cast to an array, shows its closure's debug info: only the public classes
            // that hold one are checked on this channel (their cast shows the wrapper, which redacts itself).
            'print_r (array)'    => $holder instanceof SensitiveValue ? '' : print_r((array) $holder, true),
            'monolog normaliser' => json_encode((new NormalizerFormatter())->format($record), JSON_THROW_ON_ERROR),
            'monolog json'       => (new JsonFormatter())->format($record),
            'monolog line'       => (new LineFormatter())->format($record),
        ];
        foreach ($serializers as $name => $serializer) {
            $channels[$name] = $serializer->serialize($holder, 'json');
        }

        foreach ($channels as $channel => $output) {
            foreach ($secrets as $secret) {
                self::assertStringNotContainsString($secret, $output, $channel . ' leaks ' . $secret);
            }
        }

        $this->expectException(LogicException::class);
        serialize($holder);
    }

    public function testTheAccessorsStillRevealTheValues(): void
    {
        $value = new SensitiveValue(['a' => 1]);

        self::assertSame(['a' => 1], $value->reveal());
        self::assertSame(SensitiveValue::REDACTED, $value->jsonSerialize());
        self::assertSame(['value' => SensitiveValue::REDACTED], $value->__debugInfo());
        self::assertSame(['meetings' => 0, 'complete' => false], (new CalendarMeetingBatch([], false))->jsonSerialize());
        self::assertSame(['accessToken' => SensitiveValue::REDACTED], (new CalendarCredential('x'))->jsonSerialize());
    }
}
