<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Client;

use Nowo\TimeTrackBundle\ValueObject\TimeTrackUserRef;

/**
 * Since 1.23.0: framework-free twin of the 1.x `ClientUserPrivacyProviderInterface` (REQ-ARCH-001 rule 3), an optional
 * extra of the `client_api.profile_provider` service.
 */
interface ClientUserPrivacySourceInterface
{
    /**
     * The privacy notice the clients show (see the 1.x `ClientUserPrivacyProviderInterface::getPrivacy()`; null = none).
     *
     * @param string|null $locale best match of the request's `Accept-Language` among `framework.enabled_locales`; null without the header
     *
     * @return array<string, mixed>|null
     */
    public function privacyOf(TimeTrackUserRef $user, ?string $locale): ?array;
}
