<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Calendar;

use Nowo\TimeTrackBundle\Entity\CalendarConnection;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Since 1.14 (host, contract §8, Q-B9): the calendar OAuth side. The host runs the OAuth flows (Google, Microsoft app
 * registrations), keeps the tokens encrypted at rest (Beacon: Halite / AEAD store, later Vault) and refreshes them;
 * the bundle asks for a short-lived access token per sync and never stores it. Configured with
 * `calendar.credential_provider`; without it server mode is off.
 *
 * @deprecated since 1.23.0, implement {@see CalendarCredentialSourceInterface} instead (framework-free, REQ-ARCH-001 rule 3); removed in 2.0.
 */
interface CalendarCredentialProviderInterface
{
    /**
     * A valid access token for the connection (`credential_ref`), or null when there is none (never connected, refresh
     * failed: the sync records `token_expired`). MUST be scope-safe: only the connection owner's token.
     */
    public function credentialFor(UserInterface $user, CalendarConnection $connection): ?CalendarCredential;

    /**
     * Deletes the stored token and revokes the grant at the provider when possible. Called when the user revokes the
     * connection; the host also calls its own revoke on account erasure.
     */
    public function revoke(UserInterface $user, CalendarConnection $connection): void;

    /**
     * Absolute https URL where the user starts the host OAuth flow for a provider and scope (the clients' *Connect*
     * link), or null when the host offers none.
     */
    public function connectUrl(UserInterface $user, string $provider, string $scopeKey): ?string;
}
