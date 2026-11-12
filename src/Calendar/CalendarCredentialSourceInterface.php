<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Calendar;

use Nowo\TimeTrackBundle\ValueObject\CalendarConnectionRef;
use Nowo\TimeTrackBundle\ValueObject\TimeTrackUserRef;

/**
 * Since 1.23.0: framework-free twin of {@see CalendarCredentialProviderInterface} (REQ-ARCH-001 rules 1 and 3), same
 * `calendar.credential_provider` option: the user is a {@see TimeTrackUserRef}, the connection a
 * {@see CalendarConnectionRef} snapshot instead of the Doctrine entity.
 */
interface CalendarCredentialSourceInterface
{
    /**
     * A valid access token for the connection, or null when there is none. MUST be scope-safe: only the connection
     * owner's token.
     */
    public function credentialOf(TimeTrackUserRef $user, CalendarConnectionRef $connection): ?CalendarCredential;

    /**
     * Deletes the stored token and revokes the grant at the provider when possible.
     */
    public function revokeFor(TimeTrackUserRef $user, CalendarConnectionRef $connection): void;

    /**
     * Absolute https URL where the user starts the host OAuth flow for a provider and scope, or null.
     */
    public function connectUrlFor(TimeTrackUserRef $user, string $provider, string $scopeKey): ?string;
}
