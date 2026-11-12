<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Client;

use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Since 1.11: optional extra of the `client_api.profile_provider` service. When the profile provider also implements
 * this interface, `GET me` sends its value as `locale` (the clients pick their UI language from it). Without it the
 * bundle uses the user's own `getLocale()` when the user class has one, else `null`.
 *
 * @deprecated since 1.23.0, implement {@see ClientUserLocaleSourceInterface} instead (framework-free, REQ-ARCH-001 rule 3); removed in 2.0.
 */
interface ClientUserLocaleProviderInterface
{
    /**
     * Preferred locale of the user, e.g. `es` or `pt_BR` (null = unknown). Values that are not a locale are dropped.
     */
    public function getLocale(UserInterface $user): ?string;
}
