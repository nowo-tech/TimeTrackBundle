<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Tests\Stub;

use Nowo\TimeTrackBundle\Calendar\CalendarCredential;
use Nowo\TimeTrackBundle\Calendar\CalendarCredentialProviderInterface;
use Nowo\TimeTrackBundle\Entity\CalendarConnection;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Test credential provider: a fake token per connection (never a real one), records revocations.
 */
final class StubCalendarCredentialProvider implements CalendarCredentialProviderInterface
{
    /** @var list<string> connection ids */
    public array $revoked = [];

    public bool $hasToken = true;

    /** @var array<string, string|null> provider => connect URL */
    public array $connectUrls = ['google' => 'https://beacon.example/account/calendars/connect/google', 'microsoft' => 'javascript:alert(1)'];

    public function credentialFor(UserInterface $user, CalendarConnection $connection): ?CalendarCredential
    {
        return $this->hasToken ? new CalendarCredential('fake-token-' . $connection->getProvider()->value) : null;
    }

    public function revoke(UserInterface $user, CalendarConnection $connection): void
    {
        $this->revoked[] = $connection->getId();
    }

    public function connectUrl(UserInterface $user, string $provider, string $scopeKey): ?string
    {
        return $this->connectUrls[$provider] ?? null;
    }
}
