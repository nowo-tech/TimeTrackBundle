<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Client;

use Nowo\TimeTrackBundle\ValueObject\TimeTrackUserRef;

/**
 * Since 1.23.0: framework-free twin of {@see ClientUserLocaleProviderInterface} (REQ-ARCH-001 rule 3), an optional
 * extra of the `client_api.profile_provider` service.
 */
interface ClientUserLocaleSourceInterface
{
    /**
     * Preferred locale of the user, e.g. `es` or `pt_BR` (null = unknown). Values that are not a locale are dropped.
     */
    public function localeOf(TimeTrackUserRef $user): ?string;
}
