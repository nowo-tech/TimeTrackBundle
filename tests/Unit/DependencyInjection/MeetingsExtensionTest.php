<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Tests\Unit\DependencyInjection;

use App\Entity\User;
use Nowo\TimeTrackBundle\DependencyInjection\TimeTrackExtension;
use Nowo\TimeTrackBundle\Meeting\MeetingContext;
use Nowo\TimeTrackBundle\Meeting\MeetingLinkDetector;
use Nowo\TimeTrackBundle\Meeting\MeetingSettings;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\SecurityBundle;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;

/**
 * Since 1.14 (contract §22.9): `meetings` configuration and wiring.
 */
final class MeetingsExtensionTest extends TestCase
{
    public function testDefaultsAndWiring(): void
    {
        $off = $this->load([]);
        self::assertSame(['enabled' => false, 'provider' => null, 'max_range_days' => 14, 'max_past_days' => 1, 'default_limit' => 100, 'refresh_after_seconds' => 300, 'rate_limit' => ['per_hour' => 60, 'per_minute' => 6], 'jitsi_hosts' => [], 'links_calendars_route' => null], $off->getParameter('nowo_time_track.meetings'));
        self::assertNull($off->getDefinition(MeetingContext::class)->getArgument('$provider'));
        self::assertSame('/api/time-track/me/meetings', $off->getParameter('nowo_time_track.clients.routes')['me_meetings']['path'] ?? null);

        $on       = $this->load(['meetings' => ['enabled' => true, 'provider' => 'app.meetings', 'max_past_days' => 7, 'jitsi_hosts' => ['jitsi.example.org'], 'links_calendars_route' => 'app_calendars', 'rate_limit' => ['per_minute' => 3]]]);
        $provider = $on->getDefinition(MeetingContext::class)->getArgument('$provider');
        self::assertInstanceOf(Reference::class, $provider);
        self::assertSame('app.meetings', (string) $provider);
        self::assertSame(['jitsi.example.org'], $on->getDefinition(MeetingLinkDetector::class)->getArgument('$jitsiHosts'));
        self::assertSame([7, 3, 60, 'app_calendars'], [
            $on->getDefinition(MeetingSettings::class)->getArgument('$maxPastDays'),
            $on->getDefinition(MeetingSettings::class)->getArgument('$perMinute'),
            $on->getDefinition(MeetingSettings::class)->getArgument('$perHour'),
            $on->getDefinition(MeetingSettings::class)->getArgument('$calendarsRoute'),
        ]);
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function invalidConfigs(): iterable
    {
        yield 'no provider' => [['enabled' => true], 'meetings.provider is required'];
        yield 'bad jitsi host' => [['jitsi_hosts' => ['https://jitsi.example.org']], 'host names only'];
        yield 'range over 14 days' => [['max_range_days' => 15], 'max_range_days'];
        yield 'past over 7 days' => [['max_past_days' => 8], 'max_past_days'];
        yield 'refresh under 60' => [['refresh_after_seconds' => 30], 'refresh_after_seconds'];
    }

    /**
     * @param array<string, mixed> $meetings
     */
    #[DataProvider('invalidConfigs')]
    public function testIncoherentConfigurationsAreRefused(array $meetings, string $message): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage($message);

        $this->load(['meetings' => $meetings]);
    }

    /**
     * @param array<string, mixed> $config
     */
    private function load(array $config): ContainerBuilder
    {
        $container = new ContainerBuilder();
        $container->setParameter('kernel.environment', 'test');
        $container->setParameter('kernel.bundles', ['SecurityBundle' => SecurityBundle::class]);
        (new TimeTrackExtension())->load([['user_class' => User::class] + $config], $container);

        return $container;
    }
}
