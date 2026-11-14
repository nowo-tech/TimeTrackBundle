<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Tests\Unit\Entity;

use DateTimeImmutable;
use DateTimeZone;
use Nowo\TimeTrackBundle\Entity\MonitoringAcknowledgementRecord;
use Nowo\TimeTrackBundle\Entity\MonitoringUploadIntent;
use Nowo\TimeTrackBundle\Tests\Stub\TestUser;
use Nowo\TimeTrackBundle\Tests\Support\MonitoringFixtures;
use PHPUnit\Framework\TestCase;

use function str_repeat;

/**
 * Since 1.26.0: the monitoring rows keep exactly what they were given, in storage time, and never an image.
 */
final class MonitoringEntitiesTest extends TestCase
{
    public function testTheAcknowledgementRecord(): void
    {
        $user   = new TestUser('1', 'u@example.com');
        $record = new MonitoringAcknowledgementRecord($user, 7, new DateTimeImmutable('2026-11-18T09:12:00.5Z'), 'desktop', MonitoringFixtures::noticeSha256());

        self::assertMatchesRegularExpression('/^[0-9a-f-]{36}$/', $record->getId());
        self::assertSame([$user, 7], [$record->getUser(), $record->getPolicyVersion()]);
        $ack = $record->toAcknowledgement();
        self::assertSame([7, 'desktop', MonitoringFixtures::noticeSha256(), '2026-11-18T09:12:00Z'], [$ack->policyVersion, $ack->clientType, $ack->noticeSha256, $ack->acknowledgedAt->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z')]);
    }

    public function testTheUploadIntent(): void
    {
        $now    = new DateTimeImmutable('2026-11-18T09:13:27Z');
        $intent = new MonitoringUploadIntent(new TestUser('1', 'u@example.com'), str_repeat('k', 64), str_repeat('b', 64), 'entry-1', new DateTimeImmutable('2026-11-18T09:10:00Z'), $now, 7, true, 'image/webp', 100, 1280, 720, str_repeat('s', 64), $now);

        self::assertSame([MonitoringUploadIntent::STATUS_PENDING, null, null], [$intent->getStatus(), $intent->getSignedUntil(), $intent->getScreenshotId()]);
        self::assertSame($now->getTimestamp() + 120, $intent->signUntil($now->modify('+120 seconds'))->getSignedUntil()?->getTimestamp());
        self::assertSame([str_repeat('k', 64), str_repeat('b', 64), 'entry-1', 7, true, 'image/webp', 100, 1280, 720, str_repeat('s', 64)], [$intent->getIdempotencyHash(), $intent->getBodyHash(), $intent->getEntryId(), $intent->getPolicyVersion(), $intent->isBlurred(), $intent->getMime(), $intent->getBytes(), $intent->getWidth(), $intent->getHeight(), $intent->getSha256()]);
        self::assertSame([$now->getTimestamp() - 207, $now->getTimestamp(), $now->getTimestamp()], [$intent->getSlot()->getTimestamp(), $intent->getTakenAt()->getTimestamp(), $intent->getCreatedAt()->getTimestamp()]);
    }
}
