<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Tests\Unit\DependencyInjection;

use App\Entity\User;
use Nowo\TimeTrackBundle\Calendar\CalendarConnectionService;
use Nowo\TimeTrackBundle\Calendar\CalendarEventMatcher;
use Nowo\TimeTrackBundle\Calendar\CalendarHttpClient;
use Nowo\TimeTrackBundle\Calendar\CalendarOAuthClients;
use Nowo\TimeTrackBundle\Calendar\CalendarSettings;
use Nowo\TimeTrackBundle\Calendar\CalendarSyncService;
use Nowo\TimeTrackBundle\Calendar\CalendarTokenEndpoint;
use Nowo\TimeTrackBundle\Calendar\GoogleCalendarEventSource;
use Nowo\TimeTrackBundle\Calendar\GoogleCalendarOAuthClient;
use Nowo\TimeTrackBundle\Calendar\MicrosoftCalendarEventSource;
use Nowo\TimeTrackBundle\Calendar\MicrosoftCalendarOAuthClient;
use Nowo\TimeTrackBundle\Calendar\TaskKeyResolverInterface;
use Nowo\TimeTrackBundle\DependencyInjection\TimeTrackExtension;
use Nowo\TimeTrackBundle\Repository\CalendarConnectionRepositoryInterface;
use Nowo\TimeTrackBundle\Repository\CalendarRuleRepositoryInterface;
use Nowo\TimeTrackBundle\Repository\CalendarSuggestionRepositoryInterface;
use Nowo\TimeTrackBundle\Repository\DoctrineOrmCalendarRepository;
use Nowo\TimeTrackBundle\Timesheet\SingleScopeProvider;
use Nowo\TimeTrackBundle\Timesheet\TimesheetScopeProviderInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\SecurityBundle;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\DependencyInjection\Argument\IteratorArgument;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\DependencyInjection\Reference;

use function array_map;

/**
 * Since 1.14 (contract §13): `calendar` configuration, the always-mapped calendar store and the service wiring.
 */
final class CalendarExtensionTest extends TestCase
{
    public function testDefaultsKeepCalendarOffWithTheStoreMapped(): void
    {
        $container = $this->load([]);
        $config    = $container->getParameter('nowo_time_track.calendar');

        self::assertIsArray($config);
        self::assertSame([false, null, null, ['google', 'microsoft'], ['server'], 14, 14, 60, 28_800, 20, 10], [
            $config['enabled'], $config['credential_provider'], $config['task_key_resolver'], $config['providers'], $config['modes'],
            $config['lookback_days'], $config['suggestion_ttl_days'], $config['sync_interval_minutes'], $config['max_event_seconds'], $config['max_rules_per_user'], $config['max_pages'],
        ]);
        foreach ([CalendarConnectionRepositoryInterface::class, CalendarSuggestionRepositoryInterface::class, CalendarRuleRepositoryInterface::class] as $interface) {
            self::assertSame(DoctrineOrmCalendarRepository::class, (string) $container->getAlias($interface));
        }
        self::assertFalse($container->hasDefinition(CalendarEventMatcher::class));
        // Since 1.19: the readers and OAuth helpers are registered for host meeting syncs, but the suggestion sync
        // gets no source while calendar suggestions are off.
        self::assertTrue($container->hasDefinition(CalendarHttpClient::class));
        $sources = $container->getDefinition(CalendarSyncService::class)->getArgument('$sources');
        self::assertInstanceOf(IteratorArgument::class, $sources);
        self::assertSame([], $sources->getValues());
        self::assertFalse($container->hasAlias(TaskKeyResolverInterface::class));
        self::assertFalse($container->hasAlias(TimesheetScopeProviderInterface::class));
        self::assertNull($container->getDefinition(CalendarSyncService::class)->getArgument('$matcher'));
        self::assertNull($container->getDefinition(CalendarConnectionService::class)->getArgument('$scopeProvider'));
        self::assertFalse($container->getDefinition(CalendarSettings::class)->getArgument('$enabled'));
    }

    public function testTheReadersAndOAuthHelpersFollowTheConfiguration(): void
    {
        $off = $this->load([]);
        self::assertSame(['Calendars.ReadBasic', '', '', '', '', 'common'], [
            $off->getDefinition(MicrosoftCalendarEventSource::class)->getArgument('$scope'),
            $off->getDefinition(GoogleCalendarOAuthClient::class)->getArgument('$clientId'),
            $off->getDefinition(GoogleCalendarOAuthClient::class)->getArgument('$clientSecret'),
            $off->getDefinition(MicrosoftCalendarOAuthClient::class)->getArgument('$clientId'),
            $off->getDefinition(MicrosoftCalendarOAuthClient::class)->getArgument('$clientSecret'),
            $off->getDefinition(MicrosoftCalendarOAuthClient::class)->getArgument('$tenant'),
        ]);

        $on = $this->load(['calendar' => [
            'max_pages'       => 4,
            'microsoft_scope' => 'Calendars.Read',
            'oauth'           => [
                'google'    => ['client_id' => 'g-id', 'client_secret' => '%env(GOOGLE_CALENDAR_SECRET)%'],
                'microsoft' => ['client_id' => 'm-id', 'client_secret' => '%env(MS_CALENDAR_SECRET)%', 'tenant' => 'organizations'],
            ],
        ]]);
        self::assertSame([4, 'Calendars.Read'], [$on->getDefinition(MicrosoftCalendarEventSource::class)->getArgument('$maxPages'), $on->getDefinition(MicrosoftCalendarEventSource::class)->getArgument('$scope')]);
        self::assertSame(4, $on->getDefinition(GoogleCalendarEventSource::class)->getArgument('$maxPages'));
        self::assertSame(['g-id', 'm-id', 'organizations', 'Calendars.Read'], [
            $on->getDefinition(GoogleCalendarOAuthClient::class)->getArgument('$clientId'),
            $on->getDefinition(MicrosoftCalendarOAuthClient::class)->getArgument('$clientId'),
            $on->getDefinition(MicrosoftCalendarOAuthClient::class)->getArgument('$tenant'),
            $on->getDefinition(MicrosoftCalendarOAuthClient::class)->getArgument('$scope'),
        ]);
        self::assertSame(CalendarTokenEndpoint::class, (string) $on->getDefinition(GoogleCalendarOAuthClient::class)->getArgument('$endpoint'));
        $parameter = $on->getParameter('nowo_time_track.calendar');
        self::assertIsArray($parameter);
        self::assertArrayNotHasKey('oauth', $parameter, 'the OAuth secrets stay out of the parameter bag');
        self::assertSame('Calendars.Read', $parameter['microsoft_scope']);
    }

    public function testAnAppProviderReplacesTheStaticApps(): void
    {
        $off = $this->load([]);
        self::assertNull($off->getDefinition(GoogleCalendarOAuthClient::class)->getArgument('$appProvider'));
        self::assertNull($off->getDefinition(MicrosoftCalendarOAuthClient::class)->getArgument('$appProvider'));
        self::assertSame([GoogleCalendarOAuthClient::class, MicrosoftCalendarOAuthClient::class], [
            (string) $off->getDefinition(CalendarOAuthClients::class)->getArgument('$google'),
            (string) $off->getDefinition(CalendarOAuthClients::class)->getArgument('$microsoft'),
        ]);

        $on = $this->load(['calendar' => ['oauth' => [
            'app_provider' => 'app.calendar_oauth_apps',
            'google'       => ['client_id' => 'g-id', 'client_secret' => '%env(GOOGLE_CALENDAR_SECRET)%'],
            'microsoft'    => ['client_id' => 'm-id', 'client_secret' => '%env(MS_CALENDAR_SECRET)%', 'tenant' => 'organizations'],
        ]]]);
        foreach ([GoogleCalendarOAuthClient::class, MicrosoftCalendarOAuthClient::class] as $client) {
            $definition = $on->getDefinition($client);
            self::assertSame('app.calendar_oauth_apps', (string) $definition->getArgument('$appProvider'));
            self::assertSame(['', ''], [$definition->getArgument('$clientId'), $definition->getArgument('$clientSecret')], 'no static secret is compiled in');
        }
        self::assertSame('organizations', $on->getDefinition(MicrosoftCalendarOAuthClient::class)->getArgument('$tenant'), 'the default tenant of an app without one');

        self::assertNull($this->load(['calendar' => ['oauth' => ['app_provider' => null]]])->getDefinition(GoogleCalendarOAuthClient::class)->getArgument('$appProvider'));
    }

    public function testTheCalendarHttpClientCanBeReplaced(): void
    {
        // Audit ML-L1: a host can send the token calls through a client the profiler does not trace.
        $default = $this->load([]);
        foreach ([CalendarTokenEndpoint::class, CalendarHttpClient::class] as $service) {
            $reference = $default->getDefinition($service)->getArgument('$httpClient');
            self::assertInstanceOf(Reference::class, $reference);
            self::assertSame(['http_client', ContainerInterface::NULL_ON_INVALID_REFERENCE], [(string) $reference, $reference->getInvalidBehavior()]);
        }
        self::assertSame('logger', (string) $default->getDefinition(GoogleCalendarOAuthClient::class)->getArgument('$logger'));

        $custom = $this->load(['calendar' => ['http_client' => 'app.untraced_http_client']]);
        foreach ([CalendarTokenEndpoint::class, CalendarHttpClient::class] as $service) {
            $reference = $custom->getDefinition($service)->getArgument('$httpClient');
            self::assertInstanceOf(Reference::class, $reference);
            self::assertSame(['app.untraced_http_client', ContainerInterface::EXCEPTION_ON_INVALID_REFERENCE], [(string) $reference, $reference->getInvalidBehavior()]);
        }
        self::assertSame('logger', (string) $custom->getDefinition(MicrosoftCalendarOAuthClient::class)->getArgument('$logger'));
    }

    public function testABlankAppProviderIsRefused(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('calendar.oauth.app_provider must be a service id.');

        $this->load(['calendar' => ['oauth' => ['app_provider' => ' ']]]);
    }

    public function testAnUnknownMicrosoftScopeIsRefused(): void
    {
        $this->expectException(InvalidConfigurationException::class);

        $this->load(['calendar' => ['microsoft_scope' => 'Calendars.ReadWrite']]);
    }

    public function testServerModeWiresTheAdapters(): void
    {
        $container = $this->load(['calendar' => ['enabled' => true, 'task_key_resolver' => 'app.keys', 'credential_provider' => 'app.calendar_tokens', 'providers' => ['google'], 'max_pages' => 3]]);

        self::assertSame('app.keys', (string) $container->getAlias(TaskKeyResolverInterface::class));
        self::assertSame(SingleScopeProvider::class, (string) $container->getAlias(TimesheetScopeProviderInterface::class), 'one scope "" without a host scope provider');
        self::assertTrue($container->hasDefinition(CalendarEventMatcher::class));
        self::assertSame(3, $container->getDefinition(GoogleCalendarEventSource::class)->getArgument('$maxPages'));
        $sources = $container->getDefinition(CalendarSyncService::class)->getArgument('$sources');
        self::assertInstanceOf(IteratorArgument::class, $sources);
        self::assertSame([GoogleCalendarEventSource::class, MicrosoftCalendarEventSource::class], array_map(static fn (mixed $r): string => (string) $r, $sources->getValues()));
        $credentials = $container->getDefinition(CalendarSyncService::class)->getArgument('$credentials');
        self::assertInstanceOf(Reference::class, $credentials);
        self::assertSame('app.calendar_tokens', (string) $credentials);
        self::assertSame(['google'], $container->getDefinition(CalendarSettings::class)->getArgument('$providers'));
        self::assertTrue($container->getDefinition(CalendarConnectionService::class)->isPublic());
    }

    public function testLocalModeNeedsNoCredentialProvider(): void
    {
        $container = $this->load(['calendar' => ['enabled' => true, 'task_key_resolver' => 'app.keys', 'modes' => ['local']]]);

        // Since 1.19: the readers and OAuth helpers are registered for host meeting syncs, but the suggestion sync
        // gets no source while calendar suggestions are off.
        self::assertTrue($container->hasDefinition(CalendarHttpClient::class));
        $sources = $container->getDefinition(CalendarSyncService::class)->getArgument('$sources');
        self::assertInstanceOf(IteratorArgument::class, $sources);
        self::assertSame([], $sources->getValues());
        $sources = $container->getDefinition(CalendarSyncService::class)->getArgument('$sources');
        self::assertInstanceOf(IteratorArgument::class, $sources);
        self::assertSame([], $sources->getValues());
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function invalidConfigs(): iterable
    {
        yield 'no resolver' => [['enabled' => true, 'credential_provider' => 'x'], 'calendar.task_key_resolver is required'];
        yield 'server without credentials' => [['enabled' => true, 'task_key_resolver' => 'x'], 'calendar.credential_provider is required'];
        yield 'unknown provider' => [['providers' => ['icloud']], 'icloud'];
        yield 'lookback too long' => [['lookback_days' => 15], 'lookback_days'];
    }

    /**
     * @param array<string, mixed> $calendar
     */
    #[DataProvider('invalidConfigs')]
    public function testIncoherentConfigurationsAreRefused(array $calendar, string $message): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage($message);

        $this->load(['calendar' => $calendar]);
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
