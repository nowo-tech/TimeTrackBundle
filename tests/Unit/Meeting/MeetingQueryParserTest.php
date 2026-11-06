<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Tests\Unit\Meeting;

use DateTimeImmutable;
use Nowo\TimeTrackBundle\Meeting\MeetingQueryParser;
use Nowo\TimeTrackBundle\Meeting\MeetingRequestException;
use Nowo\TimeTrackBundle\Meeting\MeetingSettings;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Since 1.14 (contract §22.3, AC-24): the validation matrix of `GET me/meetings` — every invalid input is a 400 code
 * with the field, never the value.
 */
final class MeetingQueryParserTest extends TestCase
{
    private const NOW = '2026-11-05T10:17:42Z';

    public function testDefaults(): void
    {
        $query = $this->parser()->parse([], new DateTimeImmutable(self::NOW), 'Europe/Madrid');

        self::assertSame(['2026-11-05T10:17:00Z', '2026-11-06T10:17:00Z', 'Europe/Madrid', 100], [
            $query->from->format('Y-m-d\TH:i:s\Z'), $query->to->format('Y-m-d\TH:i:s\Z'), $query->timeZone->getName(), $query->limit,
        ]);
        self::assertSame('UTC', $this->parser()->parse([], new DateTimeImmutable(self::NOW), null)->timeZone->getName());
        self::assertSame('UTC', $this->parser()->parse([], new DateTimeImmutable(self::NOW), 'Mars/Base')->timeZone->getName());
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string, string, string}>
     */
    public static function valid(): iterable
    {
        yield 'offsets' => [['from' => '2026-11-05T08:00:00+14:00', 'to' => '2026-11-06T08:00-12:00'], '2026-11-04T18:00:00Z', '2026-11-06T20:00:00Z', 'UTC'];
        yield 'exactly 14 days' => [['from' => '2026-11-05T10:00:00Z', 'to' => '2026-11-19T10:00:00Z'], '2026-11-05T10:00:00Z', '2026-11-19T10:00:00Z', 'UTC'];
        yield 'from = now - 1 day' => [['from' => '2026-11-04T10:17:42Z'], '2026-11-04T10:17:42Z', '2026-11-05T10:17:42Z', 'UTC'];
        yield 'fraction and zone' => [['from' => '2026-11-05T10:00:00.123456Z', 'tz' => 'America/Argentina/Buenos_Aires', 'limit' => '200'], '2026-11-05T10:00:00Z', '2026-11-06T10:00:00Z', 'America/Argentina/Buenos_Aires'];
        yield 'DST day in Madrid' => [['from' => '2026-10-25T00:00:00+02:00', 'to' => '2026-10-26T00:00:00+01:00', 'tz' => 'Europe/Madrid'], '2026-10-24T22:00:00Z', '2026-10-25T23:00:00Z', 'Europe/Madrid'];
    }

    /**
     * @param array<string, mixed> $parameters
     */
    #[DataProvider('valid')]
    public function testValidQueries(array $parameters, string $from, string $to, string $tz): void
    {
        $now   = new DateTimeImmutable(isset($parameters['from']) && $parameters['from'] === '2026-10-25T00:00:00+02:00' ? '2026-10-25T00:00:00Z' : self::NOW);
        $query = (new MeetingQueryParser(new MeetingSettings(true, 14, 1, 100)))->parse($parameters, $now, null);

        self::assertSame([$from, $to, $tz], [$query->from->format('Y-m-d\TH:i:s\Z'), $query->to->format('Y-m-d\TH:i:s\Z'), $query->timeZone->getName()]);
    }

    /**
     * @return iterable<string, array{array<array-key, mixed>, string, string}>
     */
    public static function invalid(): iterable
    {
        yield 'unknown parameter' => [['userId' => '2'], 'unknown_parameter', 'userId'];
        yield 'scope parameter' => [['scope' => 'org-a'], 'unknown_parameter', 'scope'];
        yield 'numeric parameter name' => [['0' => 'x'], 'unknown_parameter', '0'];
        yield 'no offset' => [['from' => '2026-11-05T10:00:00'], 'invalid_datetime', 'from'];
        yield 'date only' => [['to' => '2026-11-06'], 'invalid_datetime', 'to'];
        yield 'week syntax' => [['from' => '2026-W45'], 'invalid_datetime', 'from'];
        yield 'decoded plus' => [['from' => '2026-11-05T10:00:00 01:00'], 'invalid_datetime', 'from'];
        yield 'array' => [['from' => ['x']], 'invalid_datetime', 'from'];
        yield 'too far back' => [['from' => '2026-11-04T10:17:41Z'], 'invalid_range', 'from'];
        yield 'to before from' => [['from' => '2026-11-05T12:00:00Z', 'to' => '2026-11-05T12:00:00Z'], 'invalid_range', 'to'];
        yield 'longer than 14 days' => [['from' => '2026-11-05T10:00:00Z', 'to' => '2026-11-19T10:00:01Z'], 'invalid_range', 'to'];
        yield 'beyond 14 days ahead' => [['from' => '2026-11-18T10:00:00Z', 'to' => '2026-11-19T10:17:43Z'], 'invalid_range', 'to'];
        yield 'unknown zone' => [['tz' => 'Mars/Base'], 'invalid_timezone', 'tz'];
        yield 'offset as zone' => [['tz' => '+01:00'], 'invalid_timezone', 'tz'];
        yield 'zone array' => [['tz' => ['UTC']], 'invalid_timezone', 'tz'];
        yield 'limit 0' => [['limit' => '0'], 'invalid_limit', 'limit'];
        yield 'limit 201' => [['limit' => '201'], 'invalid_limit', 'limit'];
        yield 'limit text' => [['limit' => '1e2'], 'invalid_limit', 'limit'];
        yield 'limit padded' => [['limit' => '0010'], 'invalid_limit', 'limit'];
        yield 'limit array' => [['limit' => ['5']], 'invalid_limit', 'limit'];
    }

    /**
     * @param array<array-key, mixed> $parameters
     */
    #[DataProvider('invalid')]
    public function testInvalidQueries(array $parameters, string $code, string $field): void
    {
        try {
            $this->parser()->parse($parameters, new DateTimeImmutable(self::NOW), null);
            self::fail('expected ' . $code);
        } catch (MeetingRequestException $exception) {
            self::assertSame([$code, $field, $code], [$exception->errorCode, $exception->field, $exception->getMessage()]);
        }
    }

    public function testMaxPastDaysIsConfigurable(): void
    {
        $query = (new MeetingQueryParser(new MeetingSettings(true, 14, 7)))->parse(['from' => '2026-10-29T10:17:42Z'], new DateTimeImmutable(self::NOW), null);

        self::assertSame('2026-10-29T10:17:42Z', $query->from->format('Y-m-d\TH:i:s\Z'));
    }

    private function parser(): MeetingQueryParser
    {
        return new MeetingQueryParser(new MeetingSettings(true));
    }
}
