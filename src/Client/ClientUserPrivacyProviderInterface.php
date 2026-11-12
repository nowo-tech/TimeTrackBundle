<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Client;

use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Since 1.15: optional extra of the `client_api.profile_provider` service. When the profile provider also implements
 * this interface, `GET me` sends its value as `privacy`; without it the key is absent.
 *
 * @deprecated since 1.23.0, implement {@see ClientUserPrivacySourceInterface} instead (framework-free, REQ-ARCH-001 rule 3); removed in 2.0.
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
