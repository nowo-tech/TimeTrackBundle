<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Tests\Functional;

use DateTimeImmutable;
use Nowo\TimeTrackBundle\Enum\ClientScope;
use Nowo\TimeTrackBundle\Enum\ClientType;
use Nowo\TimeTrackBundle\Service\ClientAuthService;
use Nowo\TimeTrackBundle\Support\CanonicalJson;
use Nowo\TimeTrackBundle\Tests\Support\RacingCachePool;

use function hash;

use const DATE_ATOM;

/**
 * Since the GG fixes (audit GG-L1): `POST entries` re-checks the `Idempotency-Key` under the user's lock, so a request
 * that raced another one with the same key replays its answer (or the mismatch) instead of writing a second entry.
 */
final class EntriesIdempotencyRaceTest extends FunctionalTestCase
{
    protected static function timeTrackConfig(): array
    {
        return ['manual_entries' => ['enabled' => true], 'client_api' => ['rate_limit' => ['cache_pool' => RacingCachePool::class]]];
    }

    public function testARacedKeyIsReplayedNotWrittenTwice(): void
    {
        $pool = self::getContainer()->get(RacingCachePool::class);
        self::assertInstanceOf(RacingCachePool::class, $pool);
        $pool->clear();
        $auth = self::getContainer()->get(ClientAuthService::class);
        self::assertInstanceOf(ClientAuthService::class, $auth);
        $token   = (string) $auth->issueToken($this->user, ClientType::Desktop, null, [ClientScope::EntriesWrite])['token'];
        $start   = (new DateTimeImmutable('today -2 days'))->setTime(9, 0);
        $payload = ['taskId' => 'task-1', 'startedAt' => $start->format(DATE_ATOM), 'endedAt' => $start->modify('+1 hour')->format(DATE_ATOM), 'source' => 'manual'];

        $pool->raced = ['hash' => hash('sha256', 'entries' . "\0" . CanonicalJson::encode($payload)), 'status' => 201, 'body' => ['entry' => ['id' => 'from-the-other-request']]];
        $body        = $this->api('POST', '/api/time-track/entries', $payload, $token, ['HTTP_IDEMPOTENCY_KEY' => 'race-1']);
        self::assertResponseStatusCodeSame(201);
        self::assertSame(['entry' => ['id' => 'from-the-other-request']], $body);
        self::assertSame(0, (int) $this->entityManager()->getConnection()->fetchOne('SELECT COUNT(*) FROM time_track_entries'));

        $pool->raced = ['hash' => 'another body', 'status' => 201, 'body' => []];
        $body        = $this->api('POST', '/api/time-track/entries', $payload, $token, ['HTTP_IDEMPOTENCY_KEY' => 'race-2']);
        self::assertResponseStatusCodeSame(422);
        self::assertSame('idempotency_mismatch', $body['error'] ?? null);
        self::assertSame(0, (int) $this->entityManager()->getConnection()->fetchOne('SELECT COUNT(*) FROM time_track_entries'));
    }
}
