<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Port\Internal;

use Nowo\TimeTrackBundle\Client\ClientUserLocaleSourceInterface;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Since 1.11: optional extra of the `client_api.profile_provider` service. When the profile provider also implements
 * this interface, `GET me` sends its value as `locale` (the clients pick their UI language from it). Without it the
 * bundle uses the user's own `getLocale()` when the user class has one, else `null`.
 *
 * @internal since 2.0 (REQ-ARCH-001 rule 3): the shape the bundle asks this port through; the 1.x public interface
 * `Nowo\TimeTrackBundle\Client\ClientUserLocaleProviderInterface` (deprecated since 1.23.0) is removed in 2.0. Hosts implement {@see ClientUserLocaleSourceInterface} (a gate adapts it).
 */
interface ClientUserLocaleProviderInterface
{
    /**
     * Preferred locale of the user, e.g. `es` or `pt_BR` (null = unknown). Values that are not a locale are dropped.
     */
    public function getLocale(UserInterface $user): ?string;
}
