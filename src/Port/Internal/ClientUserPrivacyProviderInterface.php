<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Port\Internal;

use Nowo\TimeTrackBundle\Client\ClientPrivacyNormalizer;
use Nowo\TimeTrackBundle\Client\ClientUserPrivacySourceInterface;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Since 1.15: optional extra of the `client_api.profile_provider` service. When the profile provider also implements
 * this interface, `GET me` sends its value as `privacy`; without it the key is absent.
 *
 * @internal since 2.0 (REQ-ARCH-001 rule 3): the shape the bundle asks this port through; the 1.x public interface
 * `Nowo\TimeTrackBundle\Client\ClientUserPrivacyProviderInterface` (deprecated since 1.23.0) is removed in 2.0. Hosts implement {@see ClientUserPrivacySourceInterface} (a gate adapts it).
 */
interface ClientUserPrivacyProviderInterface
{
    /**
     * The privacy notice the clients show (usually `url`, `version`, `controllerName`, `dpoContact`, `noticeSummary`;
     * null = none). A `url` must be absolute `http(s)`: anything else is sent as null ({@see ClientPrivacyNormalizer}).
     *
     * @param string|null $locale best match of the request's `Accept-Language` among `framework.enabled_locales`; null without the header (use the user's own)
     *
     * @return array<string, mixed>|null
     */
    public function getPrivacy(UserInterface $user, ?string $locale): ?array;
}
