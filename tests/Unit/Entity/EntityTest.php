<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Tests\Unit\Entity;

use DateTimeImmutable;
use Nowo\TimeTrackBundle\Entity\ActiveTimer;
use Nowo\TimeTrackBundle\Entity\ClientToken;
use Nowo\TimeTrackBundle\Entity\TimeEntry;
use Nowo\TimeTrackBundle\Enum\ClientType;
use Nowo\TimeTrackBundle\Enum\TimeEntrySource;
use Nowo\TimeTrackBundle\Service\ClientAuthService;
use Nowo\TimeTrackBundle\Tests\Stub\TestUser;
use PHPUnit\Framework\TestCase;

final class EntityTest extends TestCase
{
    public function testActiveTimerHeartbeatAndToArray(): void
    {
        $user  = new TestUser('1', 'u@example.com');
        $timer = new ActiveTimer($user, 'task-1', 'Title', 'board-1', ClientType::Web, ['foo' => 'bar']);

        self::assertNotEmpty($timer->getId());
        self::assertSame($user, $timer->getUser());
        self::assertSame('task-1', $timer->getTaskId());
        self::assertSame('Title', $timer->getTaskTitleSnapshot());
        self::assertSame('board-1', $timer->getBoardIdSnapshot());
        self::assertSame(ClientType::Web, $timer->getClientType());
        self::assertSame(['foo' => 'bar'], $timer->getMetadata());

        $timer->heartbeat(true);
        self::assertTrue($timer->getMetadata()['isIdle'] ?? false);
        self::assertInstanceOf(DateTimeImmutable::class, $timer->getStartedAt());
        self::assertInstanceOf(DateTimeImmutable::class, $timer->getLastHeartbeatAt());

        $idleTimer = new ActiveTimer($user, 'task-2', 'Other', null, ClientType::Desktop);
        $idleTimer->heartbeat(false);
        self::assertFalse($idleTimer->getMetadata()['isIdle'] ?? true);

        $array = $timer->toArray();
        self::assertSame('task-1', $array['taskId']);
        self::assertSame('web', $array['clientType']);
    }

    public function testTimeEntryToArray(): void
    {
        $user    = new TestUser('1', 'u@example.com');
        $started = new DateTimeImmutable('-1 hour');
        $ended   = new DateTimeImmutable();
        $entry   = new TimeEntry($user, 'task-1', 'Title', null, $started, $ended, 3600, TimeEntrySource::Extension);

        self::assertNotEmpty($entry->getId());
        self::assertSame($user, $entry->getUser());
        self::assertSame(3600, $entry->getDurationSeconds());
        self::assertSame(TimeEntrySource::Extension, $entry->getSource());
        self::assertInstanceOf(DateTimeImmutable::class, $entry->getCreatedAt());

        $array = $entry->toArray();
        self::assertSame('task-1', $array['taskId']);
        self::assertSame('extension', $array['source']);
        self::assertSame('Title', $entry->getTaskTitleSnapshot());
        self::assertNull($entry->getBoardIdSnapshot());
        self::assertNull($entry->getMetadata());
        self::assertSame($ended, $entry->getEndedAt());
    }

    public function testClientTokenLifecycle(): void
    {
        $user    = new TestUser('1', 'u@example.com');
        $expires = new DateTimeImmutable('+1 hour');
        $token   = new ClientToken(ClientAuthService::hashToken('plain'), $expires, $user, ClientType::Desktop);

        self::assertNotEmpty($token->getId());
        self::assertFalse($token->isExpired());
        self::assertNull($token->getLastUsedAt());
        self::assertSame(ClientAuthService::hashToken('plain'), $token->getTokenHash());
        self::assertSame($expires, $token->getExpiresAt());
        self::assertSame($user, $token->getUser());

        $token->touch();
        self::assertInstanceOf(DateTimeImmutable::class, $token->getLastUsedAt());
        self::assertSame(ClientType::Desktop, $token->getClientType());
    }

    public function testExpiredClientToken(): void
    {
        $user  = new TestUser('1', 'u@example.com');
        $token = new ClientToken('hash', new DateTimeImmutable('-1 minute'), $user, ClientType::Web);

        self::assertTrue($token->isExpired());
    }
}
