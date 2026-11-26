<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Tests\Unit\Client;

use ArrayObject;
use Nowo\TimeTrackBundle\Client\ClientAccountChecker;
use Nowo\TimeTrackBundle\Client\ClientAuthenticatorInterface;
use Nowo\TimeTrackBundle\Client\ConfigDeviceScopeFamilyProvider;
use Nowo\TimeTrackBundle\Client\DeviceScopeFamilyRegistry;
use Nowo\TimeTrackBundle\Client\DeviceScopeRequest;
use Nowo\TimeTrackBundle\Client\OpaqueClientCredentialIssuer;
use Nowo\TimeTrackBundle\Client\OrphanedCredentialRevokerInterface;
use Nowo\TimeTrackBundle\Entity\DeviceAuthorization;
use Nowo\TimeTrackBundle\Enum\ClientApiAuditAction;
use Nowo\TimeTrackBundle\Enum\ClientType;
use Nowo\TimeTrackBundle\Event\ClientApiEvent;
use Nowo\TimeTrackBundle\Event\TimeTrackEvents;
use Nowo\TimeTrackBundle\Port\ClientCredentialMinterGate;
use Nowo\TimeTrackBundle\Port\Internal\ClientCredentialIssuerInterface;
use Nowo\TimeTrackBundle\Port\Internal\TaskProviderInterface;
use Nowo\TimeTrackBundle\Service\DeviceAuthorizationService;
use Nowo\TimeTrackBundle\Tests\Stub\RecordingFamilyCredentialMinter;
use Nowo\TimeTrackBundle\Tests\Stub\RevokingFamilyCredentialMinter;
use Nowo\TimeTrackBundle\Tests\Stub\TestUser;
use Nowo\TimeTrackBundle\Tests\Support\ClientApiStack;
use Nowo\TimeTrackBundle\Tests\Support\RecordingLogger;
use Nowo\TimeTrackBundle\Tests\Support\SwitchableUserChecker;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Since 2.2.1 (audit ZBV-v-I2): an exchange refused after the approval audits the orphaned credential and asks a
 * minter implementing {@see OrphanedCredentialRevokerInterface} to revoke it, best effort.
 */
final class OrphanedDeviceCredentialTest extends TestCase
{
    public function testAFamilyUnregisteredAfterTheApprovalRevokesTheCredential(): void
    {
        $minter           = $this->revokingMinter();
        [$stack, $events] = $this->stack();
        $grant            = $this->approved($stack, new ClientCredentialMinterGate($minter), 'chat');

        $without = new DeviceAuthorizationService($stack->devices, new ClientCredentialMinterGate($minter), $stack->authService, $stack->auditor, $stack->rateLimiter, clock: $stack->clock);
        self::assertSame('access_denied', $without->poll($grant)->error);

        self::assertSame([['cred-1', 'chat']], $minter->revoked);
        $denied = array_values(array_filter($events->getArrayCopy(), static fn (ClientApiEvent $event): bool => $event->action === ClientApiAuditAction::DeviceDenied));
        self::assertCount(1, $denied);
        self::assertSame(['cred-1', 'scope_family_unavailable', 'chat'], [$denied[0]->credentialId, $denied[0]->reason, $denied[0]->scopeFamily]);
        self::assertSame('expired_token', $without->poll($grant)->error, 'the request is gone');
        self::assertCount(1, $minter->revoked, 'revoked once');
    }

    public function testAnAccountDisabledAfterTheApprovalRevokesTheCredential(): void
    {
        $minter  = $this->revokingMinter();
        [$stack] = $this->stack();
        $checker = new SwitchableUserChecker();
        $service = new DeviceAuthorizationService($stack->devices, new ClientCredentialMinterGate($minter), $stack->authService, $stack->auditor, $stack->rateLimiter, clock: $stack->clock, accountChecker: new ClientAccountChecker($checker, $stack->tokens, false), families: $this->chat());
        $grant   = $this->approved($stack, new ClientCredentialMinterGate($minter), 'time');
        $checker->refuse('u@example.com');

        self::assertSame('access_denied', $service->poll($grant)->error);
        self::assertSame([['cred-1', 'time']], $minter->revoked);
    }

    public function testAFailingRevokeIsLoggedAndTheDeviceIsStillDenied(): void
    {
        $minter       = $this->revokingMinter();
        $minter->fail = true;
        [$stack]      = $this->stack();
        $logger       = new RecordingLogger();
        $grant        = $this->approved($stack, new ClientCredentialMinterGate($minter), 'chat');
        $without      = new DeviceAuthorizationService($stack->devices, new ClientCredentialMinterGate($minter), $stack->authService, $stack->auditor, $stack->rateLimiter, clock: $stack->clock, logger: $logger);

        self::assertSame('access_denied', $without->poll($grant)->error);
        self::assertSame(['TimeTrack could not revoke the credential of a refused device exchange; revoke it in the host.'], $logger->messages());
        self::assertSame(['bundle' => 'time_track', 'action' => 'device_credential_orphaned', 'reason' => 'scope_family_unavailable', 'family' => 'chat', 'exception' => RuntimeException::class], $logger->records[0]['context']);
        self::assertNotContains('cred-1', $logger->contextValues(), 'the credential id is in the audit event only');
    }

    public function testAMinterWithoutTheRevokerAndTheOpaqueIssuerKeepTheCredential(): void
    {
        $plain   = new RecordingFamilyCredentialMinter();
        [$stack] = $this->stack();
        $grant   = $this->approved($stack, new ClientCredentialMinterGate($plain), 'chat');
        $without = new DeviceAuthorizationService($stack->devices, new ClientCredentialMinterGate($plain), $stack->authService, $stack->auditor, $stack->rateLimiter, clock: $stack->clock);
        self::assertSame('access_denied', $without->poll($grant)->error);

        $opaque  = new OpaqueClientCredentialIssuer();
        $grant   = $this->approved($stack, $opaque, 'time');
        $checker = new SwitchableUserChecker();
        $checker->refuse('u@example.com');
        $service = new DeviceAuthorizationService($stack->devices, $opaque, $stack->authService, $stack->auditor, $stack->rateLimiter, clock: $stack->clock, accountChecker: new ClientAccountChecker($checker, $stack->tokens, false));
        self::assertSame('access_denied', $service->poll($grant)->error);
    }

    /**
     * @return array{0: ClientApiStack, 1: ArrayObject<int, ClientApiEvent>}
     */
    private function stack(): array
    {
        $stack = new ClientApiStack($this->createStub(ClientAuthenticatorInterface::class), $this->createStub(TaskProviderInterface::class));
        /** @var ArrayObject<int, ClientApiEvent> $events */
        $events = new ArrayObject();
        $stack->dispatcher->addListener(TimeTrackEvents::CLIENT_API, static function (ClientApiEvent $event) use ($events): void {
            $events[] = $event;
        });

        return [$stack, $events];
    }

    private function chat(): DeviceScopeFamilyRegistry
    {
        return new DeviceScopeFamilyRegistry([new ConfigDeviceScopeFamilyProvider(['chat' => ['label' => 'Chat', 'scopes' => ['chat:read' => 'Read']]])]);
    }

    /**
     * Starts and approves a request of the family; returns its device code.
     */
    private function approved(ClientApiStack $stack, ClientCredentialIssuerInterface $issuer, string $family): string
    {
        $user    = new TestUser('u-1', 'u@example.com');
        $service = new DeviceAuthorizationService($stack->devices, $issuer, $stack->authService, $stack->auditor, $stack->rateLimiter, clock: $stack->clock, families: $this->chat());
        $grant   = $service->startFamily(ClientType::Desktop, 'Laptop', new DeviceScopeRequest($family, $family === 'chat' ? ['chat:read'] : ['timer:read']));
        $pending = $service->findPending($grant->userCode, $user);
        self::assertInstanceOf(DeviceAuthorization::class, $pending);
        $service->approve($pending, $user);

        return $grant->deviceCode;
    }

    private function revokingMinter(): RevokingFamilyCredentialMinter
    {
        return new RevokingFamilyCredentialMinter();
    }
}
