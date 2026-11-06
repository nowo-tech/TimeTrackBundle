<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Tests\Stub;

use Nowo\TimeTrackBundle\Client\ClientUserPrivacyProviderInterface;
use Nowo\TimeTrackBundle\Client\ClientUserProfileProviderInterface;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Profile provider with a fixed `privacy` block; records the locales it was asked in.
 */
final class StubPrivacyProfileProvider implements ClientUserProfileProviderInterface, ClientUserPrivacyProviderInterface
{
    /** @var list<string|null> */
    public array $locales = [];

    /**
     * @param array<string, mixed>|null $block
     */
    public function __construct(private readonly ?array $block)
    {
    }

    public function getDisplayName(UserInterface $user): ?string
    {
        return null;
    }

    public function getWorkingHours(UserInterface $user): ?array
    {
        return null;
    }

    public function getPrivacy(UserInterface $user, ?string $locale): ?array
    {
        $this->locales[] = $locale;

        return $this->block;
    }
}
