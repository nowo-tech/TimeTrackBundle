<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Tests\Support;

use Nowo\TimeTrackBundle\Client\AlwaysActiveClientCredentialState;
use Nowo\TimeTrackBundle\Client\ClientApiAuditor;
use Nowo\TimeTrackBundle\Client\ClientApiGuard;
use Nowo\TimeTrackBundle\Client\ClientApiRateLimiter;
use Nowo\TimeTrackBundle\Client\ClientAuthenticatorInterface;
use Nowo\TimeTrackBundle\Client\ClientCredentialIssuerInterface;
use Nowo\TimeTrackBundle\Client\ClientCredentialStateInterface;
use Nowo\TimeTrackBundle\Client\ClientLoginRateLimiter;
use Nowo\TimeTrackBundle\Client\ClientMinSupportedVersionProviderInterface;
use Nowo\TimeTrackBundle\Client\ClientResponseFactory;
use Nowo\TimeTrackBundle\Client\ConfigClientApiSettingsProvider;
use Nowo\TimeTrackBundle\Client\NullClientUserProfileProvider;
use Nowo\TimeTrackBundle\Client\OpaqueClientCredentialIssuer;
use Nowo\TimeTrackBundle\Controller\TimeTrackClientApiController;
use Nowo\TimeTrackBundle\Controller\TimeTrackClientDeviceController;
use Nowo\TimeTrackBundle\Integration\TaskProviderInterface;
use Nowo\TimeTrackBundle\Repository\ActiveTimerRepositoryInterface;
use Nowo\TimeTrackBundle\Repository\TimeEntryRepositoryInterface;
use Nowo\TimeTrackBundle\Security\TaskAccessCheckerInterface;
use Nowo\TimeTrackBundle\Security\TaskProviderTaskAccessChecker;
use Nowo\TimeTrackBundle\Service\ClientAuthService;
use Nowo\TimeTrackBundle\Service\DeviceAuthorizationService;
use Nowo\TimeTrackBundle\Service\TeamAccessGuard;
use Nowo\TimeTrackBundle\Service\TimerService;
use Nowo\TimeTrackBundle\Tests\Stub\StubTeamContextProvider;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * In-memory client API stack of the unit tests (no kernel): auth service, guard, auditor, timer service, controllers.
 */
final readonly class ClientApiStack
{
    public MockClock $clock;

    public ArrayAdapter $cache;

    public EventDispatcher $dispatcher;

    public InMemoryClientTokenRepository $tokens;

    public InMemoryDeviceAuthorizationRepository $devices;

    public ClientAuthService $authService;

    public ClientApiRateLimiter $rateLimiter;

    public ClientApiAuditor $auditor;

    public ClientApiGuard $guard;

    public ClientResponseFactory $responses;

    public TimerService $timerService;

    public DeviceAuthorizationService $deviceService;

    public function __construct(
        ClientAuthenticatorInterface $authenticator,
        TaskProviderInterface $taskProvider,
        ?TimeEntryRepositoryInterface $entries = null,
        ?ClientCredentialStateInterface $credentialState = null,
        ?ConfigClientApiSettingsProvider $settings = null,
        ?ClientCredentialIssuerInterface $issuer = null,
        ?ActiveTimerRepositoryInterface $timers = null,
        ?TeamAccessGuard $teamGuard = null,
    ) {
        $this->clock       = new MockClock('2026-11-02 10:00:00');
        $this->cache       = new ArrayAdapter();
        $this->dispatcher  = new EventDispatcher();
        $this->tokens      = new InMemoryClientTokenRepository($this->clock);
        $this->devices     = new InMemoryDeviceAuthorizationRepository($this->clock);
        $this->authService = new ClientAuthService($authenticator, $this->tokens, 3600, null, $this->clock);
        $this->rateLimiter = new ClientApiRateLimiter($this->cache, $this->clock);
        $this->auditor     = new ClientApiAuditor($this->dispatcher, $this->rateLimiter, 60, $this->clock);
        $settings ??= new ConfigClientApiSettingsProvider(['chrome-extension://unit']);
        $this->responses     = new ClientResponseFactory([], 'prod', false, $settings);
        $this->guard         = new ClientApiGuard($this->authService, $this->responses, $this->rateLimiter, $settings, $credentialState ?? new AlwaysActiveClientCredentialState(), $this->auditor);
        $this->timerService  = new TimerService($taskProvider, $timers ?? new InMemoryActiveTimerRepository(), $entries ?? new InMemoryTimeEntryRepository(), $this->dispatcher, $this->clock);
        $this->deviceService = new DeviceAuthorizationService($this->devices, $issuer ?? new OpaqueClientCredentialIssuer(), $this->authService, $this->auditor, $this->rateLimiter, 600, 5, 3, 900, $this->clock);
        $this->taskProvider  = $taskProvider;
        $this->teamGuard     = $teamGuard ?? new TeamAccessGuard(new StubTeamContextProvider(), new EventDispatcher(), ['ROLE_ADMIN'], true, true);
    }

    private TaskProviderInterface $taskProvider;

    private TeamAccessGuard $teamGuard;

    public function apiController(?TaskAccessCheckerInterface $taskAccess = null, bool $allowMetadata = false, ?ClientMinSupportedVersionProviderInterface $minSupportedVersions = null): TimeTrackClientApiController
    {
        return new TimeTrackClientApiController(
            $this->authService,
            $this->timerService,
            $this->teamGuard,
            $this->responses,
            new ClientLoginRateLimiter(new ArrayAdapter(), 5, 60, true),
            300,
            $this->guard,
            $taskAccess ?? new TaskProviderTaskAccessChecker($this->taskProvider),
            new NullClientUserProfileProvider(),
            $this->auditor,
            $allowMetadata,
            $this->clock,
            minSupportedVersionProvider: $minSupportedVersions,
        );
    }

    public function deviceController(UrlGeneratorInterface $urls, ?string $verificationUri = null): TimeTrackClientDeviceController
    {
        return new TimeTrackClientDeviceController($this->deviceService, $this->responses, $this->guard, $urls, $verificationUri, 'nowo_time_track_device_verify');
    }
}
