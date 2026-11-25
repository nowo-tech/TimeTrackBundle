<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Port;

use Nowo\TimeTrackBundle\Client\ClientUserPrivacySourceInterface;
use Nowo\TimeTrackBundle\Port\Internal\ClientUserPrivacyProviderInterface;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Asks a host {@see ClientUserPrivacySourceInterface} (1.23.0) where the bundle asks its internal shape
 * {@see ClientUserPrivacyProviderInterface} (the 1.x interface of that name was removed in 2.0). Exceptions propagate. Built by {@see Ports}; stateless.
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
