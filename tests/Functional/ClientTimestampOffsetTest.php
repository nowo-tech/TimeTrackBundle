<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Tests\Functional;

use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\Attributes\DataProvider;

use function array_map;
use function date_default_timezone_get;
use function date_default_timezone_set;

/**
 * EF-M1 (1.9.2): client timestamps with a UTC offset are converted to the server time zone before Doctrine stores
 * them (it writes the wall time without the offset), so the stored instants and durations are the ones the client
 * sent. Every check reloads from the database after clear(), as a fresh request would.
 */
final class ClientTimestampOffsetTest extends FunctionalTestCase
{
    /**
     * @return iterable<string, array{string}>
     */
    public static function offsets(): iterable
    {
        yield '-11:00' => ['-11:00'];
        yield '+14:00' => ['+14:00'];
        yield 'Z' => ['+00:00'];
    }

    #[DataProvider('offsets')]
    public function testSplitAndDiscardKeepTheInstantsTheClientSent(string $offset): void
    {
        $token = $this->login();
        $this->startTimerAt('-60 minutes', $token);
        $splitFrom = $this->at('-40 minutes', $offset);
        $splitTo   = $this->at('-30 minutes', $offset);

        $split = $this->api('POST', '/api/time-track/timer/discard-idle', ['from' => $splitFrom, 'to' => $splitTo, 'action' => 'split'], $token);
        self::assertResponseIsSuccessful();
        self::assertEqualsWithDelta(1200, $split['entry']['durationSeconds'] ?? 0, 5);
        $this->entityManager()->clear();

        $timer = $this->api('GET', '/api/time-track/timer', null, $token);
        self::assertSame($this->instant($splitTo), $this->instant((string) ($timer['timer']['startedAt'] ?? '')), 'the continued timer starts at the instant sent');

        $discardFrom = $this->at('-10 minutes', $offset);
        $discard     = $this->api('POST', '/api/time-track/timer/discard-idle', ['from' => $discardFrom, 'to' => $this->at('-1 minute', $offset), 'action' => 'discard'], $token);
        self::assertResponseIsSuccessful();
        self::assertEqualsWithDelta(1200, $discard['entry']['durationSeconds'] ?? 0, 5);
        $this->entityManager()->clear();

        $entries = $this->api('GET', '/api/time-track/entries', null, $token)['entries'] ?? [];
        self::assertCount(2, $entries);
        foreach ($entries as $entry) {
            self::assertEqualsWithDelta(1200, $entry['durationSeconds'], 5);
            self::assertSame($entry['durationSeconds'], $this->instant($entry['endedAt']) - $this->instant($entry['startedAt']), 'stored start and end match the duration');
        }
        self::assertSame($this->instant($discardFrom), $this->instant($entries[0]['endedAt']));
        self::assertSame($this->instant($splitFrom), $this->instant($entries[1]['endedAt']));
    }

    #[DataProvider('offsets')]
    public function testBackdatedStartStopAndReassignKeepTheInstants(string $offset): void
    {
        $token = $this->login();

        $startedAt = $this->at('-30 minutes', $offset);
        $start     = $this->api('POST', '/api/time-track/timer/start', ['taskId' => 'task-1', 'startedAt' => $startedAt], $token);
        self::assertResponseStatusCodeSame(201);
        self::assertFalse($start['startedAtResult']['clamped'] ?? null);
        $this->entityManager()->clear();
        $timer = $this->api('GET', '/api/time-track/timer', null, $token);
        self::assertSame($this->instant($startedAt), $this->instant((string) ($timer['timer']['startedAt'] ?? '')), 'stored start = the instant sent');

        $endedAt = $this->at('-20 minutes', $offset);
        $stop    = $this->api('POST', '/api/time-track/timer/stop', ['endedAt' => $endedAt], $token);
        self::assertResponseIsSuccessful();
        self::assertSame(600, $stop['entry']['durationSeconds'] ?? null);
        $this->entityManager()->clear();

        // Overlap rule on the real instants: a start before the stored end is moved to it, whatever the offset.
        $overlap = $this->api('POST', '/api/time-track/timer/start', ['taskId' => 'task-1', 'startedAt' => $this->at('-25 minutes', $offset)], $token);
        self::assertSame('overlap', $overlap['startedAtResult']['reason'] ?? null);
        self::assertSame($this->instant($endedAt), $this->instant((string) ($overlap['timer']['startedAt'] ?? '')));
        $this->entityManager()->clear();

        $from     = $this->at('-15 minutes', $offset);
        $to       = $this->at('-5 minutes', $offset);
        $reassign = $this->api('POST', '/api/time-track/timer/discard-idle', ['from' => $from, 'to' => $to, 'action' => 'reassign', 'taskId' => 'task-2'], $token);
        self::assertResponseIsSuccessful();
        self::assertSame(300, $reassign['entry']['durationSeconds'] ?? null);
        self::assertSame(600, $reassign['reassigned']['durationSeconds'] ?? null);
        $this->entityManager()->clear();

        $this->api('POST', '/api/time-track/timer/stop', [], $token);
        $this->entityManager()->clear();
        $entries = $this->api('GET', '/api/time-track/entries', null, $token)['entries'] ?? [];
        self::assertSame(['task-1', 'task-2', 'task-1', 'task-1'], array_map(static fn (array $e): string => $e['taskId'], $entries));
        $total = 0;
        foreach ($entries as $entry) {
            self::assertSame($entry['durationSeconds'], $this->instant($entry['endedAt']) - $this->instant($entry['startedAt']));
            self::assertGreaterThanOrEqual(0, $entry['durationSeconds']);
            $total += $entry['durationSeconds'];
        }
        self::assertEqualsWithDelta(1800, $total, 5, 'no inflation: 30 min of wall time');
        self::assertSame($this->instant($from), $this->instant($entries[1]['startedAt']));
        self::assertSame($this->instant($to), $this->instant($entries[1]['endedAt']));
    }

    public function testOffsetsOutsideTheRealRangeAre400(): void
    {
        $token = $this->login();
        $this->startTimerAt('-60 minutes', $token);

        foreach (['+14:01', '-12:01', '+99:00', '-99:59'] as $offset) {
            $body = $this->api('POST', '/api/time-track/timer/discard-idle', ['from' => $this->at('-40 minutes', $offset), 'to' => $this->at('-30 minutes', $offset), 'action' => 'split'], $token);
            self::assertResponseStatusCodeSame(400, $offset);
            self::assertSame('invalid_request', $body['error'] ?? null);
        }

        $this->api('POST', '/api/time-track/timer/discard-idle', ['from' => $this->at('-40 minutes', '-12:00'), 'to' => $this->at('-30 minutes', '+14:00'), 'action' => 'keep'], $token);
        self::assertResponseIsSuccessful();
    }

    public function testHonestUtcClientsOnANonUtcServer(): void
    {
        $previous = date_default_timezone_get();
        date_default_timezone_set('Europe/Madrid'); // @phpstan-ignore frankenphp.worker.noDateDefaultTimezoneSet (test only, restored below)
        try {
            $token = $this->login();
            $this->startTimerAt('-60 minutes', $token);
            $to = $this->at('-30 minutes', '+00:00');

            $this->api('POST', '/api/time-track/timer/discard-idle', ['from' => $this->at('-40 minutes', '+00:00'), 'to' => $to, 'action' => 'split'], $token);
            self::assertResponseIsSuccessful();
            $this->entityManager()->clear();

            $timer = $this->api('GET', '/api/time-track/timer', null, $token);
            self::assertSame($this->instant($to), $this->instant((string) ($timer['timer']['startedAt'] ?? '')));
        } finally {
            date_default_timezone_set($previous); // @phpstan-ignore frankenphp.worker.noDateDefaultTimezoneSet (restores the test process zone)
        }
    }

    private function startTimerAt(string $modifier, string $token): void
    {
        $this->api('POST', '/api/time-track/timer/start', ['taskId' => 'task-1'], $token);
        self::assertResponseStatusCodeSame(201);
        $em = $this->entityManager();
        $em->getConnection()->executeStatement('UPDATE time_track_active_timers SET started_at = ?', [(new DateTimeImmutable($modifier))->format('Y-m-d H:i:s')]);
        $em->clear();
    }

    /**
     * The instant `$modifier` from now, written in the given UTC offset (RFC 3339).
     */
    private function at(string $modifier, string $offset): string
    {
        return (new DateTimeImmutable($modifier))->setTimezone(new DateTimeZone($offset))->format('Y-m-d\TH:i:sP');
    }

    private function instant(string $value): int
    {
        return (new DateTimeImmutable($value))->getTimestamp();
    }
}
