<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Tests\Unit\ClientApi;

use DateTimeImmutable;
use Nowo\TimeTrackBundle\Bridge\StubTaskProvider;
use Nowo\TimeTrackBundle\Client\ClientAuthenticatorInterface;
use Nowo\TimeTrackBundle\Client\ClientAuthResult;
use Nowo\TimeTrackBundle\Client\ClientUserDaysOffProviderInterface;
use Nowo\TimeTrackBundle\Client\ClientUserDaysOffSourceInterface;
use Nowo\TimeTrackBundle\Client\ClientUserLocaleProviderInterface;
use Nowo\TimeTrackBundle\Client\ClientUserLocaleSourceInterface;
use Nowo\TimeTrackBundle\Client\ClientUserPrivacyProviderInterface;
use Nowo\TimeTrackBundle\Client\ClientUserPrivacySourceInterface;
use Nowo\TimeTrackBundle\Client\ClientUserProfileProviderInterface;
use Nowo\TimeTrackBundle\Client\ClientUserProfileSourceInterface;
use Nowo\TimeTrackBundle\Client\ConfigClientApiSettingsProvider;
use Nowo\TimeTrackBundle\Enum\ClientType;
use Nowo\TimeTrackBundle\Port\Ports;
use Nowo\TimeTrackBundle\Tests\Stub\TestUser;
use Nowo\TimeTrackBundle\Tests\Support\ClientApiStack;
use Nowo\TimeTrackBundle\Tests\Support\Legacy\Legacy1221MeDecisions;
use Nowo\TimeTrackBundle\ValueObject\TimeTrackUserRef;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Core\User\UserInterface;

use function array_key_exists;
use function json_decode;
use function sprintf;

use const JSON_THROW_ON_ERROR;

/**
 * 1.23.0 equivalence (REQ-ARCH-001 rule 3): the `privacy`, `locale` and `daysOff` of `GET me` for a profile provider
 * implementing the deprecated extras, their 1.23 twins (behind the gate the container builds), both, or none, equal
 * what the LITERAL v1.22.1 decisions ({@see Legacy1221MeDecisions}) give for the deprecated provider with the same data.
 */
final class ProfileExtrasEquivalenceTest extends TestCase
{
    private const LOCALE = 'pt_BR';

    private const PRIVACY = ['url' => 'https://beacon.test/privacy', 'version' => '4'];

    private const DAYS_OFF = [['date' => '2026-11-05', 'kind' => 'holiday', 'label' => 'Fiesta']];

    public function testMeMatches1221ForEveryKindOfProvider(): void
    {
        $user   = new TestUser('u-1', 'u@example.com');
        $legacy = $this->legacy();
        $now    = new DateTimeImmutable('2026-11-02 10:00:00');

        foreach (['legacy' => $legacy, 'twin' => Ports::port('profile_provider', $this->twin()), 'both' => $this->both(), 'none' => $this->none()] as $kind => $provider) {
            $reference = $kind === 'none' ? $this->none() : $legacy;
            self::assertInstanceOf(ClientUserProfileProviderInterface::class, $provider);
            $body = $this->me($user, $provider);

            self::assertSame(Legacy1221MeDecisions::privacy($reference, $user, null), [array_key_exists('privacy', $body), $body['privacy'] ?? null], sprintf('%s: privacy', $kind));
            self::assertSame(Legacy1221MeDecisions::userLocale($reference, $user), $body['locale'] ?? null, sprintf('%s: locale', $kind));
            self::assertSame(Legacy1221MeDecisions::daysOff($reference, $user, null, $now, false), $body['daysOff'] ?? null, sprintf('%s: daysOff', $kind));
        }

        self::assertSame([true, self::PRIVACY], Legacy1221MeDecisions::privacy($legacy, $user, null), 'the data reaches the body');
        self::assertSame(self::LOCALE, Legacy1221MeDecisions::userLocale($legacy, $user));
        self::assertSame(self::DAYS_OFF, Legacy1221MeDecisions::daysOff($legacy, $user, null, $now, false));
    }

    /**
     * @return array<string, mixed>
     */
    private function me(UserInterface $user, ClientUserProfileProviderInterface $profiles): array
    {
        $authenticator = $this->createStub(ClientAuthenticatorInterface::class);
        $authenticator->method('authenticate')->willReturn(ClientAuthResult::success($user));
        $stack      = new ClientApiStack($authenticator, new StubTaskProvider(), null, null, new ConfigClientApiSettingsProvider([]));
        $controller = $stack->apiController(profileProvider: $profiles);
        $request    = Request::create('https://beacon.test/api/me', 'GET', [], [], [], ['REMOTE_ADDR' => '192.0.2.1']);
        $request->headers->set('Authorization', 'Bearer ' . $stack->authService->issueToken($user, ClientType::Desktop)['token']);
        $response = $controller->me($request);
        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
        /** @var array<string, mixed> $body */
        $body = json_decode((string) $response->getContent(), true, 512, JSON_THROW_ON_ERROR);

        return $body;
    }

    private function legacy(): ClientUserProfileProviderInterface
    {
        return new class implements ClientUserProfileProviderInterface, ClientUserLocaleProviderInterface, ClientUserDaysOffProviderInterface, ClientUserPrivacyProviderInterface {
            public function getDisplayName(UserInterface $user): ?string
            {
                return null;
            }

            public function getWorkingHours(UserInterface $user): ?array
            {
                return null;
            }

            public function getLocale(UserInterface $user): string
            {
                return ProfileExtrasEquivalenceTest::locale();
            }

            public function getDaysOff(UserInterface $user, DateTimeImmutable $from, DateTimeImmutable $to): array
            {
                return ProfileExtrasEquivalenceTest::daysOff();
            }

            public function getPrivacy(UserInterface $user, ?string $locale): array
            {
                return ProfileExtrasEquivalenceTest::privacyBlock();
            }
        };
    }

    private function twin(): ClientUserProfileSourceInterface
    {
        return new class implements ClientUserProfileSourceInterface, ClientUserLocaleSourceInterface, ClientUserDaysOffSourceInterface, ClientUserPrivacySourceInterface {
            public function displayNameOf(TimeTrackUserRef $user): ?string
            {
                return null;
            }

            public function workingHoursOf(TimeTrackUserRef $user): ?array
            {
                return null;
            }

            public function localeOf(TimeTrackUserRef $user): string
            {
                return ProfileExtrasEquivalenceTest::locale();
            }

            public function daysOffOf(TimeTrackUserRef $user, DateTimeImmutable $from, DateTimeImmutable $to): array
            {
                return ProfileExtrasEquivalenceTest::daysOff();
            }

            public function privacyOf(TimeTrackUserRef $user, ?string $locale): array
            {
                return ProfileExtrasEquivalenceTest::privacyBlock();
            }
        };
    }

    private function both(): ClientUserProfileProviderInterface
    {
        return new class implements ClientUserProfileProviderInterface, ClientUserLocaleProviderInterface, ClientUserDaysOffProviderInterface, ClientUserPrivacyProviderInterface, ClientUserProfileSourceInterface, ClientUserLocaleSourceInterface, ClientUserDaysOffSourceInterface, ClientUserPrivacySourceInterface {
            public function getDisplayName(UserInterface $user): ?string
            {
                return null;
            }

            public function getWorkingHours(UserInterface $user): ?array
            {
                return null;
            }

            public function getLocale(UserInterface $user): string
            {
                return ProfileExtrasEquivalenceTest::locale();
            }

            public function getDaysOff(UserInterface $user, DateTimeImmutable $from, DateTimeImmutable $to): array
            {
                return ProfileExtrasEquivalenceTest::daysOff();
            }

            public function getPrivacy(UserInterface $user, ?string $locale): array
            {
                return ProfileExtrasEquivalenceTest::privacyBlock();
            }

            // The twin methods answer differently: the deprecated interface must be the one asked.
            public function displayNameOf(TimeTrackUserRef $user): string
            {
                return 'twin';
            }

            public function workingHoursOf(TimeTrackUserRef $user): ?array
            {
                return null;
            }

            public function localeOf(TimeTrackUserRef $user): string
            {
                return 'de';
            }

            public function daysOffOf(TimeTrackUserRef $user, DateTimeImmutable $from, DateTimeImmutable $to): array
            {
                return [];
            }

            public function privacyOf(TimeTrackUserRef $user, ?string $locale): ?array
            {
                return null;
            }
        };
    }

    private function none(): ClientUserProfileProviderInterface
    {
        return new class implements ClientUserProfileProviderInterface {
            public function getDisplayName(UserInterface $user): ?string
            {
                return null;
            }

            public function getWorkingHours(UserInterface $user): ?array
            {
                return null;
            }
        };
    }

    /** @internal shared answer of the providers */
    public static function locale(): string
    {
        return self::LOCALE;
    }

    /**
     * @internal shared answer of the providers
     *
     * @return list<array{date: string, kind: string, label: string}>
     */
    public static function daysOff(): array
    {
        return self::DAYS_OFF;
    }

    /**
     * @internal shared answer of the providers
     *
     * @return array<string, string>
     */
    public static function privacyBlock(): array
    {
        return self::PRIVACY;
    }
}
