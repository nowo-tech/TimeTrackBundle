<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Port;

use Nowo\TimeTrackBundle\Client\ClientUserPrivacyProviderInterface;
use Nowo\TimeTrackBundle\Client\ClientUserPrivacySourceInterface;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Asks a host {@see ClientUserPrivacySourceInterface} (1.23.0) where the bundle asks the deprecated
 * {@see ClientUserPrivacyProviderInterface}. Exceptions propagate. Built by {@see Ports}; stateless.
 *
 * @internal
 */
final readonly class ClientUserPrivacySourceGate implements ClientUserPrivacyProviderInterface
{
    /**
     * Wraps the host source.
     */
    public function __construct(private ClientUserPrivacySourceInterface $source)
    {
    }

    /**
     * The source's privacy notice for the user's reference.
     */
    public function getPrivacy(UserInterface $user, ?string $locale): ?array
    {
        return $this->source->privacyOf(TimeTrackUserRefs::fromUser($user), $locale);
    }
}
