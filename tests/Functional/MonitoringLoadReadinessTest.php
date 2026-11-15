<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Tests\Functional;

use Nowo\TimeTrackBundle\Tests\Support\MonitoringFixtures;
use Nowo\TimeTrackBundle\Tests\Support\RacingMonitoringIntentRepository;

/**
 * 1.27.0 (Beacon spec 239, spec 199 activity monitoring): two screenshot intents for one slot at the same moment. Up to
 * 1.26 both passed the slot check before either was written, both got an upload URL, and each upload then refused the
 * other one's slot as taken: no screenshot was stored. The slot is now checked again, and the intent written, under the
 * user's write lock. Fails on 1.26.0.
 */
final class MonitoringLoadReadinessTest extends MonitoringTestCase
{
    public function testTwoIntentsForOneSlotAtOnceGetOneUploadUrl(): void
    {
        $token = $this->token();
        $this->acknowledge($token);
        $timer = $this->startTimer($token);
        $image = MonitoringFixtures::webp(1280, 720);
        $body  = $this->intentBody($timer, $image);

        // The other request's intent for this slot: written first, then taken out so this request's checks pass, and
        // committed again (by the other request) right after them.
        $this->intent($token, $body, 'other');
        self::assertResponseStatusCodeSame(201);
        $connection = $this->entityManager()->getConnection();
        $row        = $connection->fetchAssociative('SELECT * FROM time_track_monitoring_upload_intents');
        self::assertIsArray($row);
        $connection->delete('time_track_monitoring_upload_intents', ['id' => $row['id']]);
        $racing = self::getContainer()->get(RacingMonitoringIntentRepository::class);
        self::assertInstanceOf(RacingMonitoringIntentRepository::class, $racing);
        $racing->onPurge = static function () use ($connection, $row): void {
            $connection->insert('time_track_monitoring_upload_intents', $row);
        };

        $answer = $this->intent($token, $body, 'mine');

        self::assertResponseStatusCodeSame(409);
        self::assertSame('duplicate', $answer['error'] ?? null);
        self::assertSame(1, (int) $connection->fetchOne('SELECT COUNT(*) FROM time_track_monitoring_upload_intents'), 'one upload URL for the slot');
    }
}
