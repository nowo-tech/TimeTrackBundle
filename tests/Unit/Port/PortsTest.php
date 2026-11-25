<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Tests\Unit\Port;

use DateTimeImmutable;
use LogicException;
use Nowo\TimeTrackBundle\Client\ClientUserProfileSourceInterface;
use Nowo\TimeTrackBundle\Integration\TaskDirectoryInterface;
use Nowo\TimeTrackBundle\Port\BatchTaskDirectoryGate;
use Nowo\TimeTrackBundle\Port\ClientUserDaysOffSourceGate;
use Nowo\TimeTrackBundle\Port\ClientUserLocaleSourceGate;
use Nowo\TimeTrackBundle\Port\ClientUserPrivacySourceGate;
use Nowo\TimeTrackBundle\Port\ClientUserProfileSourceGate;
use Nowo\TimeTrackBundle\Port\EntryOwnerRefsGate;
use Nowo\TimeTrackBundle\Port\Internal\ClientUserDaysOffProviderInterface;
use Nowo\TimeTrackBundle\Port\Internal\ClientUserLocaleProviderInterface;
use Nowo\TimeTrackBundle\Port\Internal\ClientUserPrivacyProviderInterface;
use Nowo\TimeTrackBundle\Port\Internal\ClientUserProfileProviderInterface;
use Nowo\TimeTrackBundle\Port\Internal\EntryOwnersRepositoryInterface;
use Nowo\TimeTrackBundle\Port\Ports;
use Nowo\TimeTrackBundle\Port\TaskDirectoryGate;
use Nowo\TimeTrackBundle\Tests\Support\Ports\RecordingTwinPorts;
use Nowo\TimeTrackBundle\ValueObject\TimeTrackUserRef;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use stdClass;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * 1.23.0 (REQ-ARCH-001 rule 3): which interface the bundle asks a host service through (since 2.0: the twin only).
 */
final class PortsTest extends TestCase
{
    /**
     * @return iterable<string, array{0: string}>
     */
    public static function ports(): iterable
    {
        foreach (Ports::PORTS as $name => $port) {
            yield $name => [$name];
        }
    }

    #[DataProvider('ports')]
    public function testAnApplicationObjectOnTheInternalShapeIsRefused(string $name): void
    {
        // 2.0: the 1.x interfaces are removed; only the bundle's own classes may sit on the internal shape.
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('must implement "' . Ports::PORTS[$name][1] . '" (since 2.0 the only host interface of this port');

        Ports::port($name, $this->createStub(Ports::PORTS[$name][0]));
    }

    #[DataProvider('ports')]
    public function testABundleObjectOnTheInternalShapeIsAskedAsItIs(string $name): void
    {
        $twin = $name === 'task_provider' ? $this->createStub(TaskDirectoryInterface::class) : new RecordingTwinPorts();
        $gate = Ports::port($name, $twin);

        self::assertSame($gate, Ports::port($name, $gate));
        self::assertTrue(Ports::isBundleClass($gate::class));
        self::assertFalse(Ports::isBundleClass('App\\TimeTrack\\Tasks'));
    }

    #[DataProvider('ports')]
    public function testATwinIsWrappedInItsGate(string $name): void
    {
        $twin    = $name === 'task_provider' ? $this->createStub(TaskDirectoryInterface::class) : new RecordingTwinPorts();
        $service = Ports::port($name, $twin);

        self::assertInstanceOf(Ports::PORTS[$name][0], $service);
        self::assertInstanceOf(Ports::PORTS[$name][2], $service);
    }

    #[DataProvider('ports')]
    public function testANeitherServiceFailsClosed(string $name): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('must implement "' . Ports::PORTS[$name][1] . '"');

        Ports::port($name, new stdClass());
    }

    public function testABatchTaskDirectoryGetsTheBatchGate(): void
    {
        self::assertInstanceOf(BatchTaskDirectoryGate::class, Ports::port('task_provider', new RecordingTwinPorts()));
        self::assertInstanceOf(TaskDirectoryGate::class, Ports::port('task_provider', $this->createStub(TaskDirectoryInterface::class)));
    }

    public function testAnUnknownPortIsRefused(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Unknown TimeTrack port "nope".');

        Ports::port('nope', new stdClass());
    }

    public function testTheProfileExtras(): void
    {
        // No provider, or a provider without extras: none.
        self::assertNull(Ports::locale(null));
        $plain = $this->createStub(ClientUserProfileProviderInterface::class);
        self::assertNull(Ports::locale($plain));
        self::assertNull(Ports::daysOff($plain));
        self::assertNull(Ports::privacy($plain));

        // A provider of the bundle's own on the internal shape with the extras: itself.
        $legacy = new class implements ClientUserProfileProviderInterface, ClientUserLocaleProviderInterface, ClientUserDaysOffProviderInterface, ClientUserPrivacyProviderInterface {
            public function getDisplayName(UserInterface $user): ?string
            {
                return null;
            }

            public function getWorkingHours(UserInterface $user): ?array
            {
                return null;
            }

            public function getLocale(UserInterface $user): string
            {
                return 'fr';
            }

            public function getDaysOff(UserInterface $user, DateTimeImmutable $from, DateTimeImmutable $to): ?array
            {
                return null;
            }

            public function getPrivacy(UserInterface $user, ?string $locale): ?array
            {
                return null;
            }
        };
        self::assertSame($legacy, Ports::locale($legacy));
        self::assertSame($legacy, Ports::daysOff($legacy));
        self::assertSame($legacy, Ports::privacy($legacy));

        // A twin source (behind its gate) with the twin extras: their gates.
        $gate = Ports::port('profile_provider', new RecordingTwinPorts());
        self::assertInstanceOf(ClientUserProfileSourceGate::class, $gate);
        self::assertInstanceOf(ClientUserLocaleSourceGate::class, Ports::locale($gate));
        self::assertInstanceOf(ClientUserDaysOffSourceGate::class, Ports::daysOff($gate));
        self::assertInstanceOf(ClientUserPrivacySourceGate::class, Ports::privacy($gate));

        // 2.0: a twin source that also implements the internal shape of an extra: never asked through it.
        $mixed = new class implements ClientUserProfileSourceInterface, ClientUserLocaleProviderInterface {
            public function displayNameOf(TimeTrackUserRef $user): ?string
            {
                return null;
            }

            public function workingHoursOf(TimeTrackUserRef $user): ?array
            {
                return null;
            }

            public function getLocale(UserInterface $user): string
            {
                return 'de';
            }
        };
        $mixedGate = Ports::port('profile_provider', $mixed);
        self::assertNull(Ports::locale($mixedGate));
        self::assertNull(Ports::daysOff($mixedGate));
        self::assertNull(Ports::privacy($mixedGate));
    }

    public function testTheEntryOwnersCapability(): void
    {
        // 2.0: an application store on the internal shape is not asked through it (only the bundle's own store is).
        self::assertNull(Ports::entryOwners($this->createStub(EntryOwnersRepositoryInterface::class)));
        $gate = Ports::entryOwners(new RecordingTwinPorts());
        self::assertSame($gate, Ports::entryOwners($gate));
        self::assertInstanceOf(EntryOwnerRefsGate::class, Ports::entryOwners(new RecordingTwinPorts()));
        self::assertNull(Ports::entryOwners(new stdClass()));
        self::assertNull(Ports::entryOwners(null));
    }
}
