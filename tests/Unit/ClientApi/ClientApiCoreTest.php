<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Tests\Unit\ClientApi;

use DateTimeImmutable;
use Nowo\TimeTrackBundle\Client\ClientApiAuditor;
use Nowo\TimeTrackBundle\Client\ClientApiRateLimiter;
use Nowo\TimeTrackBundle\Client\ClientApiSettings;
use Nowo\TimeTrackBundle\Client\ClientAuthResult;
use Nowo\TimeTrackBundle\Client\ClientDistribution;
use Nowo\TimeTrackBundle\Client\ConfigClientDistributionProvider;
use Nowo\TimeTrackBundle\Client\NullClientUserProfileProvider;
use Nowo\TimeTrackBundle\Client\OpaqueClientCredentialIssuer;
use Nowo\TimeTrackBundle\Dto\TaskReference;
use Nowo\TimeTrackBundle\Entity\ClientToken;
use Nowo\TimeTrackBundle\Entity\DeviceAuthorization;
use Nowo\TimeTrackBundle\Enum\ClientApiAuditAction;
use Nowo\TimeTrackBundle\Enum\ClientScope;
use Nowo\TimeTrackBundle\Enum\ClientType;
use Nowo\TimeTrackBundle\Enum\DeviceAuthorizationStatus;
use Nowo\TimeTrackBundle\Event\ClientApiEvent;
use Nowo\TimeTrackBundle\Event\TimeTrackEvents;
use Nowo\TimeTrackBundle\Integration\TaskProviderInterface;
use Nowo\TimeTrackBundle\Security\TaskProviderTaskAccessChecker;
use Nowo\TimeTrackBundle\Support\IpPrefix;
use Nowo\TimeTrackBundle\Tests\Stub\TestUser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use stdClass;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\Security\Core\User\InMemoryUser;

final class ClientApiCoreTest extends TestCase
{
    /**
     * @return iterable<string, array{0: string|null, 1: string|null}>
     */
    public static function ips(): iterable
    {
        yield 'ipv4' => ['198.51.100.23', '198.51.100.0/24'];
        yield 'ipv6' => ['2001:db8:1:2:3:4:5:6', '2001:db8:1:2::/64'];
        yield 'empty' => ['', null];
        yield 'null' => [null, null];
        yield 'garbage' => ['not-an-ip', null];
    }

    #[DataProvider('ips')]
    public function testIpPrefixTruncates(?string $ip, ?string $expected): void
    {
        self::assertSame($expected, IpPrefix::of($ip));
    }

    public function testRateLimiterCountsPerWindowAndIgnoresDisabledLimits(): void
    {
        $clock   = new MockClock('2026-11-02 10:00:30');
        $limiter = new ClientApiRateLimiter(new ArrayAdapter(), $clock);

        self::assertSame(0, $limiter->hit('token', 'a', 0, 60));
        self::assertSame(0, $limiter->hit('token', 'a', 2, 60));
        self::assertSame(0, $limiter->hit('token', 'a', 2, 60));
        self::assertSame(30, $limiter->hit('token', 'a', 2, 60));
        self::assertSame(0, $limiter->hit('token', 'b', 2, 60));
        $clock->sleep(31);
        self::assertSame(0, $limiter->hit('token', 'a', 2, 60));
        self::assertSame(0, (new ClientApiRateLimiter(new ArrayAdapter()))->hit('x', 'y', 1, 0));
    }

    public function testAuditorResolvesUserIdsDefensivelyAndThrottlesDenials(): void
    {
        $events     = [];
        $dispatcher = new EventDispatcher();
        $dispatcher->addListener(TimeTrackEvents::CLIENT_API, static function (ClientApiEvent $event) use (&$events): void {
            $events[] = $event;
        });
        $clock   = new MockClock('2026-11-02 10:00:00');
        $auditor = new ClientApiAuditor($dispatcher, new ClientApiRateLimiter(new ArrayAdapter(), $clock), 60, $clock);

        $auditor->record(ClientApiAuditAction::TokenIssued, new stdClass(), 'c1', ClientType::Desktop, '10.0.0.9');
        $auditor->record(ClientApiAuditAction::TokenIssued, new InMemoryUser('nobody', null), null, null, null);
        $token = new ClientToken('h', new DateTimeImmutable('+1 hour'), new TestUser('u-1', 'u@example.com'), ClientType::Extension, null, 'cred-1', ['timer:read']);
        $auditor->denied('task_forbidden', $token, '10.0.0.9');
        $auditor->denied('task_forbidden', $token, '10.0.0.10');
        $auditor->denied('insufficient_scope', $token, '10.0.0.9');

        self::assertCount(4, $events);
        self::assertNull($events[0]->userId);
        self::assertNull($events[1]->userId);
        self::assertSame(['action' => 'request_denied', 'userId' => 'u-1', 'credentialId' => 'cred-1', 'clientType' => 'extension', 'ipPrefix' => '10.0.0.0/24', 'reason' => 'task_forbidden', 'occurredAt' => '2026-11-02T10:00:00+00:00'], $events[2]->toArray());

        (new ClientApiAuditor())->record(ClientApiAuditAction::TokenRevoked, null, null, null, null);
    }

    public function testTaskAccessCheckerDeniesByDefault(): void
    {
        $user     = new TestUser('u-1', 'u@example.com');
        $provider = $this->createStub(TaskProviderInterface::class);
        $provider->method('canUserTrack')->willReturnCallback(static fn ($u, string $id): bool => $id !== 'foreign' && ($id !== 'boom' || throw new RuntimeException('down')));
        $provider->method('findForUser')->willReturnCallback(static fn (string $id): ?TaskReference => $id === 'task-1' ? new TaskReference('task-1', 'T') : null);
        $checker = new TaskProviderTaskAccessChecker($provider);

        self::assertTrue($checker->canTrack($user, 'task-1'));
        self::assertFalse($checker->canTrack($user, ''));
        self::assertFalse($checker->canTrack($user, 'foreign'));
        self::assertFalse($checker->canTrack($user, 'missing'));
        self::assertFalse($checker->canTrack($user, 'boom'));
    }

    public function testScopesAuthResultAndTokens(): void
    {
        self::assertSame(ClientScope::all(), ClientScope::normalize([]));
        self::assertSame([ClientScope::TimerRead, ClientScope::EntriesRead], ClientScope::normalize(['entries:read', ClientScope::TimerRead, 'admin', 7, 'entries:read']));
        self::assertSame([], ClientScope::normalize(['admin']));

        $user   = new TestUser('u-1', 'u@example.com');
        $result = ClientAuthResult::success($user, 'pw-1', ['tasks:read']);
        self::assertSame('pw-1', $result->getCredentialId());
        self::assertSame([ClientScope::TasksRead], $result->getScopes());
        self::assertNull(ClientAuthResult::success($user, '')->getCredentialId());
        self::assertSame(ClientScope::all(), ClientAuthResult::success($user)->getScopes());
        self::assertSame([], ClientAuthResult::failure()->getScopes());

        $issued = new DateTimeImmutable('2026-11-01 08:00:00');
        $legacy = new ClientToken('h', new DateTimeImmutable('+1 hour'), $user, ClientType::Desktop, $issued);
        self::assertNull($legacy->getCredentialId());
        self::assertSame(ClientScope::all(), $legacy->getScopes(), '1.8 tokens keep every scope');
        self::assertSame($issued, $legacy->getIssuedAt());
        $scoped = new ClientToken('h2', new DateTimeImmutable('+1 hour'), $user, ClientType::Desktop, null, 'pw', ['timer:read']);
        self::assertTrue($scoped->hasScope(ClientScope::TimerRead));
        self::assertFalse($scoped->hasScope(ClientScope::TimerWrite));
    }

    public function testDeviceAuthorizationEntity(): void
    {
        $created = new DateTimeImmutable('2026-11-02 10:00:00');
        $device  = new DeviceAuthorization('dh', 'uh', ClientType::Desktop, 'Laptop', [ClientScope::TimerRead], $created->modify('+10 minutes'), $created);
        $user    = new TestUser('u-1', 'u@example.com');

        self::assertSame('dh', $device->getDeviceCodeHash());
        self::assertSame('uh', $device->getUserCodeHash());
        self::assertSame($created, $device->getCreatedAt());
        self::assertNull($device->getLastPolledAt());
        self::assertSame(DeviceAuthorizationStatus::Pending, $device->getStatus());
        self::assertFalse($device->isExpired($created));
        self::assertTrue($device->isExpired($created->modify('+10 minutes')));

        $device->approve($user, 'cred');
        self::assertSame($user, $device->getUser());
        self::assertSame('cred', $device->getCredentialId());
        $device->deny($user);
        self::assertSame(DeviceAuthorizationStatus::Denied, $device->getStatus());
    }

    public function testDistributionKeepsAbsoluteHttpLinksOnly(): void
    {
        $distribution = (new ConfigClientDistributionProvider(
            ['chrome' => 'https://example.com/c', 'edge' => 'javascript:alert(1)', 'firefox' => '/relative'],
            ['windows' => 'http://example.com/w', 'macos' => "https://example.com/m\n", 'linux' => null, 'latest_version' => '1.0.0', 'update_manifest_url' => 'https://example.com/latest.json', 'other' => 'https://x'],
        ))->getDistribution();

        self::assertSame(['chrome' => 'https://example.com/c', 'windows' => 'http://example.com/w'], $distribution->links());
        self::assertTrue($distribution->hasLinks());
        self::assertSame('1.0.0', $distribution->latestVersion);
        self::assertSame('https://example.com/latest.json', $distribution->updateManifestUrl);
        self::assertFalse((new ClientDistribution())->hasLinks());
    }

    public function testDefaultsOfTheOptionalHostInterfaces(): void
    {
        $user = new TestUser('u-1', 'u@example.com');
        self::assertStringStartsWith('device:', (new OpaqueClientCredentialIssuer())->issueForDevice($user, ClientType::Desktop, 'Laptop', []));
        self::assertNull((new NullClientUserProfileProvider())->getDisplayName($user));
        self::assertNull((new NullClientUserProfileProvider())->getWorkingHours($user));

        $settings = new ClientApiSettings(['a', 'a', 'b'], windowSeconds: 0);
        self::assertSame(['a', 'b'], $settings->allowedOrigins);
        self::assertSame(1, $settings->windowSeconds);
    }
}
