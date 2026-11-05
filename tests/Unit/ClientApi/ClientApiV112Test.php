<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Tests\Unit\ClientApi;

use DateTimeImmutable;
use Nowo\TimeTrackBundle\Bridge\StubTaskProvider;
use Nowo\TimeTrackBundle\Client\ClientAuthenticatorInterface;
use Nowo\TimeTrackBundle\Client\ClientAuthResult;
use Nowo\TimeTrackBundle\Client\ClientUserProfileProviderInterface;
use Nowo\TimeTrackBundle\Client\ConfigClientApiSettingsProvider;
use Nowo\TimeTrackBundle\Client\NullClientUserProfileProvider;
use Nowo\TimeTrackBundle\Enum\ClientType;
use Nowo\TimeTrackBundle\Tests\Stub\DaysOffProfileProvider;
use Nowo\TimeTrackBundle\Tests\Stub\TestUser;
use Nowo\TimeTrackBundle\Tests\Support\ClientApiStack;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Core\User\UserInterface;

use function date_default_timezone_get;
use function json_decode;

use const DATE_ATOM;
use const JSON_THROW_ON_ERROR;

/**
 * 1.12: `daysOff` on `GET me` (ClientUserDaysOffProviderInterface) and `workingHours` normalised to the `slots` shape.
 * The stack clock is fixed at 2026-11-02 10:00:00 UTC.
 */
final class ClientApiV112Test extends TestCase
{
    public function testMeHasNullDaysOffWithoutAProvider(): void
    {
        $profiles = new class implements ClientUserProfileProviderInterface {
            public function getDisplayName(UserInterface $user): ?string
            {
                return null;
            }

            public function getWorkingHours(UserInterface $user): ?array
            {
                return null;
            }
        };

        $body = $this->me($profiles);
        self::assertArrayHasKey('daysOff', $body);
        self::assertNull($body['daysOff']);
        self::assertNull($this->me()['daysOff']);
        self::assertNull((new NullClientUserProfileProvider())->getDaysOff(new TestUser('1', 'a@example.com'), new DateTimeImmutable(), new DateTimeImmutable()));
    }

    public function testMeSendsDaysOffInTheWorkingHoursTimeZone(): void
    {
        // 10:00 UTC on 2026-11-02 is already 2026-11-03 in Pacific/Kiritimati (UTC+14).
        $profiles = $this->provider(['timezone' => 'Pacific/Kiritimati', 'slots' => []], [
            ['date' => '2026-11-02', 'kind' => 'holiday', 'label' => 'yesterday there'],
            ['date' => '2027-01-02', 'kind' => 'vacation', 'label' => null],
            ['date' => '2027-01-03', 'kind' => 'vacation'],
            ['date' => '2026-11-03', 'kind' => 'holiday', 'label' => 'Día <festivo>'],
        ]);

        $body = $this->me($profiles);
        self::assertSame([
            ['date' => '2026-11-03', 'kind' => 'holiday', 'label' => 'Día <festivo>'],
            ['date' => '2027-01-02', 'kind' => 'vacation', 'label' => null],
        ], $body['daysOff']);
        self::assertSame('2026-11-03T00:00:00+14:00', $profiles->from?->format(DATE_ATOM));
        self::assertSame('2027-01-02T23:59:59+14:00', $profiles->to?->format(DATE_ATOM));
    }

    public function testDaysOffUseThePhpTimeZoneWithoutWorkingHoursAndFailClosed(): void
    {
        $today = (new DateTimeImmutable('2026-11-02 10:00:00'))->setTime(0, 0);

        $profiles = $this->provider(null, [['date' => $today->format('Y-m-d'), 'kind' => 'holiday']]);
        self::assertSame([['date' => $today->format('Y-m-d'), 'kind' => 'holiday', 'label' => null]], $this->me($profiles)['daysOff']);
        self::assertSame($today->format(DATE_ATOM), $profiles->from?->format(DATE_ATOM));
        self::assertSame($today->modify('+60 days')->setTime(23, 59, 59)->format(DATE_ATOM), $profiles->to?->format(DATE_ATOM));
        self::assertSame(date_default_timezone_get(), $profiles->from->getTimezone()->getName());

        self::assertNull($this->me($this->provider(null, [['date' => '2026-11-02', 'kind' => 'sick']]))['daysOff']);
        self::assertNull($this->me($this->provider(null, null))['daysOff']);
    }

    public function testMeNormalisesBothWorkingHoursShapes(): void
    {
        $expected = ['timezone' => 'Europe/Madrid', 'slots' => [['day' => 1, 'from' => '09:00', 'to' => '17:00']]];

        self::assertSame($expected, $this->me($this->provider(['timezone' => 'Europe/Madrid', 'days' => ['mon' => [['09:00', '17:00']]]], null))['workingHours']);
        self::assertSame($expected, $this->me($this->provider(['timezone' => 'Europe/Madrid', 'slots' => [['day' => 1, 'from' => '09:00', 'to' => '17:00']]], null))['workingHours']);
        self::assertNull($this->me($this->provider(['timezone' => 'Europe/Madrid', 'days' => ['mon' => [['17:00', '17:00']]]], null))['workingHours']);
    }

    /**
     * @param array<mixed>|null $hours
     * @param list<array{date: string, kind: string, label?: string|null}>|null $daysOff
     */
    private function provider(?array $hours, ?array $daysOff): DaysOffProfileProvider
    {
        return new DaysOffProfileProvider($hours, $daysOff);
    }

    /**
     * @return array<string, mixed>
     */
    private function me(?ClientUserProfileProviderInterface $profiles = null): array
    {
        $user          = new TestUser('42', 'person@example.com');
        $authenticator = $this->createStub(ClientAuthenticatorInterface::class);
        $authenticator->method('authenticate')->willReturn(ClientAuthResult::success($user));
        $stack      = new ClientApiStack($authenticator, new StubTaskProvider(), null, null, new ConfigClientApiSettingsProvider([]));
        $controller = $stack->apiController(profileProvider: $profiles);

        $request = Request::create('https://beacon.test/api/me', 'GET', [], [], [], ['REMOTE_ADDR' => '192.0.2.1']);
        $request->headers->set('Authorization', 'Bearer ' . $stack->authService->issueToken($user, ClientType::Desktop)['token']);
        $response = $controller->me($request);
        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());

        /** @var array<string, mixed> $body */
        $body = json_decode((string) $response->getContent(), true, 512, JSON_THROW_ON_ERROR);

        return $body;
    }
}
