<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Calendar;

use Nowo\TimeTrackBundle\Entity\CalendarConnection;
use Nowo\TimeTrackBundle\Enum\CalendarConnectionMode;
use Nowo\TimeTrackBundle\Enum\CalendarConnectionStatus;
use Nowo\TimeTrackBundle\Enum\CalendarProvider;
use Nowo\TimeTrackBundle\Event\CalendarConnectionChangedEvent;
use Nowo\TimeTrackBundle\Event\TimeTrackEvents;
use Nowo\TimeTrackBundle\Integration\PassThroughUserReferenceResolver;
use Nowo\TimeTrackBundle\Integration\UserReferenceResolverInterface;
use Nowo\TimeTrackBundle\Repository\CalendarConnectionRepositoryInterface;
use Nowo\TimeTrackBundle\Support\UserIdResolver;
use Nowo\TimeTrackBundle\Timesheet\TimesheetScope;
use Nowo\TimeTrackBundle\Timesheet\TimesheetScopeProviderInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\Clock\NativeClock;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

use function is_string;
use function preg_match;
use function strlen;

/**
 * Since 1.14 (contract §6.1, §12.2): the user's calendar connections — opt-in per user, scope and provider with a
 * dated consent; revoking is as easy as connecting and deletes everything derived. The host calls {@see self::connect()}
 * after its OAuth flow (server mode, with its `credential_ref`) or when the desktop reports a local connection;
 * {@see self::revoke()} asks the host to delete the token and revoke the grant first, then deletes the connection, its
 * suggestions and the owner's rules in that scope. Owner only: nobody else can list or change a connection.
 */
final readonly class CalendarConnectionService
{
    private UserReferenceResolverInterface $userReferences;

    /** `consent_version` characters. */
    public const CONSENT_VERSION_PATTERN = '/^[A-Za-z0-9._-]{1,16}$/D';

    /** `credential_ref` characters (an opaque host id). */
    public const CREDENTIAL_REF_PATTERN = '/^[\x21-\x7E]{1,128}$/D';

    private ClockInterface $clock;

    /**
     * Receives the store, the settings, the host scope provider and (server mode) the host credential provider.
     */
    public function __construct(
        private CalendarConnectionRepositoryInterface $connections,
        private CalendarSettings $settings,
        private ?TimesheetScopeProviderInterface $scopeProvider = null,
        private ?CalendarCredentialProviderInterface $credentials = null,
        private ?EventDispatcherInterface $eventDispatcher = null,
        ?ClockInterface $clock = null,
        ?UserReferenceResolverInterface $userReferences = null,
    ) {
        $this->clock          = $clock ?? new NativeClock();
        $this->userReferences = $userReferences ?? new PassThroughUserReferenceResolver();
    }

    /**
     * Whether calendar suggestions are enabled.
     */
    public function isEnabled(): bool
    {
        return $this->settings->enabled && $this->scopeProvider instanceof TimesheetScopeProviderInterface;
    }

    /**
     * The user's scope for a request: the requested key when it is one of the user's scopes, else (no request) the
     * first scope; null when there is none or the feature is off.
     */
    public function scopeOf(UserInterface $user, ?string $requested): ?TimesheetScope
    {
        if (!$this->isEnabled()) {
            return null;
        }
        $scopes = $this->scopeProvider?->scopesFor($user) ?? [];
        if ($requested === null) {
            return $scopes[0] ?? null;
        }
        foreach ($scopes as $scope) {
            if ($scope->key === $requested) {
                return $scope;
            }
        }

        return null;
    }

    /**
     * Records (or renews) the user's consent for a provider in a scope.
     *
     * @param string|null $credentialRef Host id of the encrypted token: required in server mode, null in local mode
     *
     * @throws CalendarException `calendar_disabled`, `invalid_scope`, `provider_not_allowed`, `mode_not_allowed`,
     *                           `invalid_credential_ref`, `invalid_consent_version`
     */
    public function connect(UserInterface $user, string $scopeKey, CalendarProvider $provider, CalendarConnectionMode $mode, ?string $credentialRef, string $consentVersion): CalendarConnection
    {
        if (!$this->isEnabled()) {
            throw new CalendarException('calendar_disabled');
        }
        if (!$this->scopeOf($user, $scopeKey) instanceof TimesheetScope) {
            throw new CalendarException('invalid_scope');
        }
        if (!$this->settings->allowsProvider($provider)) {
            throw new CalendarException('provider_not_allowed');
        }
        if (!$this->settings->allowsMode($mode)) {
            throw new CalendarException('mode_not_allowed');
        }
        $server = $mode === CalendarConnectionMode::Server;
        if ($server !== is_string($credentialRef) || (is_string($credentialRef) && preg_match(self::CREDENTIAL_REF_PATTERN, $credentialRef) !== 1)) {
            throw new CalendarException('invalid_credential_ref');
        }
        if (preg_match(self::CONSENT_VERSION_PATTERN, $consentVersion) !== 1) {
            throw new CalendarException('invalid_consent_version');
        }

        $now        = $this->clock->now();
        $connection = $this->connections->findConnectionFor(UserIdResolver::getId($user), $scopeKey, $provider);
        $from       = $connection?->getStatus()->value;
        if ($connection instanceof CalendarConnection) {
            $connection->reconnect($mode, $credentialRef, $consentVersion, $now);
        } else {
            $connection = new CalendarConnection($this->userReferences->reference($user), $scopeKey, $provider, $mode, $credentialRef, $consentVersion, $now);
        }
        $this->connections->saveConnection($connection);
        $this->changed($connection, $from, $connection->getStatus());

        return $connection;
    }

    /**
     * The user's connections (one scope or all).
     *
     * @return list<CalendarConnection>
     */
    public function connectionsOf(UserInterface $user, ?string $scopeKey = null): array
    {
        return $this->isEnabled() ? $this->connections->findConnectionsByUser(UserIdResolver::getId($user), $scopeKey) : [];
    }

    /**
     * One of the user's connections (null for another user's id: no existence oracle).
     */
    public function connectionOf(UserInterface $user, string $connectionId): ?CalendarConnection
    {
        $connection = $this->isEnabled() && strlen($connectionId) <= 36 ? $this->connections->findConnection($connectionId) : null;
        $owner      = $connection?->getUser();

        return $owner instanceof UserInterface && UserIdResolver::getId($owner) === UserIdResolver::getId($user) ? $connection : null;
    }

    /**
     * Pauses (no sync) or resumes one of the user's connections; false when it is not the user's.
     */
    public function setPaused(UserInterface $user, string $connectionId, bool $paused): bool
    {
        $connection = $this->connectionOf($user, $connectionId);
        if (!$connection instanceof CalendarConnection) {
            return false;
        }
        $from = $connection->getStatus();
        $to   = $paused ? CalendarConnectionStatus::Paused : CalendarConnectionStatus::Active;
        if ($from !== $to) {
            $connection->changeStatus($to, $this->clock->now());
            $this->connections->saveConnection($connection);
            $this->changed($connection, $from->value, $to);
        }

        return true;
    }

    /**
     * Revokes one of the user's connections: the host deletes the token and revokes the grant (server mode), then the
     * connection, its suggestions and the user's rules in its scope are deleted. False when it is not the user's.
     */
    public function revoke(UserInterface $user, string $connectionId): bool
    {
        $connection = $this->connectionOf($user, $connectionId);
        if (!$connection instanceof CalendarConnection) {
            return false;
        }
        if ($connection->getMode() === CalendarConnectionMode::Server) {
            $this->credentials?->revoke($user, $connection);
        }
        $from = $connection->getStatus()->value;
        $this->connections->deleteConnection($connection);
        $this->changed($connection, $from, CalendarConnectionStatus::Revoked);

        return true;
    }

    /**
     * Since the GG fixes (GG-I8): revokes every connection of the user (account erasure in one call: the host revoke
     * runs for each server-mode connection first). Returns how many were revoked.
     */
    public function revokeAllFor(UserInterface $user): int
    {
        $revoked = 0;
        foreach ($this->connectionsOf($user) as $connection) {
            $revoked += $this->revoke($user, $connection->getId()) ? 1 : 0;
        }

        return $revoked;
    }

    /**
     * The host *Connect* links per enabled provider for a scope (absolute https URLs only; null when the host has none).
     *
     * @return array<string, string|null>
     */
    public function connectUrls(UserInterface $user, string $scopeKey): array
    {
        $urls = [];
        foreach (CalendarProvider::cases() as $provider) {
            if ($this->settings->allowsProvider($provider)) {
                $url                    = $this->credentials?->connectUrl($user, $provider->value, $scopeKey);
                $urls[$provider->value] = is_string($url) && preg_match('#^https://[^\s/?\#@]+(/[^\s]*)?$#D', $url) === 1 ? $url : null;
            }
        }

        return $urls;
    }

    private function changed(CalendarConnection $connection, ?string $from, CalendarConnectionStatus $to): void
    {
        $owner = $connection->getUser();
        $this->eventDispatcher?->dispatch(new CalendarConnectionChangedEvent(
            $connection->getId(),
            $owner instanceof UserInterface ? UserIdResolver::getId($owner) : '',
            $connection->getScopeKey(),
            $connection->getProvider()->value,
            $connection->getMode()->value,
            $from,
            $to->value,
            $connection->getConsentVersion(),
        ), TimeTrackEvents::CALENDAR_CONNECTION_CHANGED);
    }
}
