<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Tests\Unit\Port;

use DateTimeImmutable;
use LogicException;
use Nowo\TimeTrackBundle\Client\ClientUserDaysOffProviderInterface;
use Nowo\TimeTrackBundle\Client\ClientUserLocaleProviderInterface;
use Nowo\TimeTrackBundle\Client\ClientUserPrivacyProviderInterface;
use Nowo\TimeTrackBundle\Client\ClientUserProfileProviderInterface;
use Nowo\TimeTrackBundle\Client\ClientUserProfileSourceInterface;
use Nowo\TimeTrackBundle\Integration\TaskDirectoryInterface;
use Nowo\TimeTrackBundle\Port\BatchTaskDirectoryGate;
use Nowo\TimeTrackBundle\Port\ClientUserDaysOffSourceGate;
use Nowo\TimeTrackBundle\Port\ClientUserLocaleSourceGate;
use Nowo\TimeTrackBundle\Port\ClientUserPrivacySourceGate;
use Nowo\TimeTrackBundle\Port\ClientUserProfileSourceGate;
use Nowo\TimeTrackBundle\Port\EntryOwnerRefsGate;
use Nowo\TimeTrackBundle\Port\Ports;
use Nowo\TimeTrackBundle\Port\TaskDirectoryGate;
use Nowo\TimeTrackBundle\Repository\EntryOwnersRepositoryInterface;
use Nowo\TimeTrackBundle\Tests\Support\Ports\RecordingTwinPorts;
use Nowo\TimeTrackBundle\ValueObject\TimeTrackUserRef;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use stdClass;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * 1.23.0 (REQ-ARCH-001 rule 3): which interface the bundle asks a host service through.
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
    public function testADeprecatedImplementationIsAskedAsItIs(string $name): void
    {
        $legacy  = $this->createStub(Ports::PORTS[$name][0]);
        $service = Ports::port($name, $legacy);

        self::assertSame($legacy, $service);
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

        // A deprecated provider with the deprecated extras: itself, as in 1.22.
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

        // A twin source with a deprecated extra: the source itself for that extra.
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
        self::assertSame($mixed, Ports::locale($mixedGate));
        self::assertNull(Ports::daysOff($mixedGate));
        self::assertNull(Ports::privacy($mixedGate));
    }

    public function testTheEntryOwnersCapability(): void
    {
        $legacy = $this->createStub(EntryOwnersRepositoryInterface::class);
        self::assertSame($legacy, Ports::entryOwners($legacy));
        self::assertInstanceOf(EntryOwnerRefsGate::class, Ports::entryOwners(new RecordingTwinPorts()));
        self::assertNull(Ports::entryOwners(new stdClass()));
        self::assertNull(Ports::entryOwners(null));
    }
}
