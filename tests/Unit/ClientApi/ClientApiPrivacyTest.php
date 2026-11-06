<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Tests\Unit\ClientApi;

use Nowo\TimeTrackBundle\Bridge\StubTaskProvider;
use Nowo\TimeTrackBundle\Client\ClientAuthenticatorInterface;
use Nowo\TimeTrackBundle\Client\ClientAuthResult;
use Nowo\TimeTrackBundle\Client\ClientPrivacyNormalizer;
use Nowo\TimeTrackBundle\Client\ClientUserPrivacyProviderInterface;
use Nowo\TimeTrackBundle\Client\ClientUserProfileProviderInterface;
use Nowo\TimeTrackBundle\Client\ConfigClientApiSettingsProvider;
use Nowo\TimeTrackBundle\Enum\ClientType;
use Nowo\TimeTrackBundle\Tests\Stub\StubPrivacyProfileProvider;
use Nowo\TimeTrackBundle\Tests\Stub\TestUser;
use Nowo\TimeTrackBundle\Tests\Support\ClientApiStack;
use Nowo\TimeTrackBundle\Tests\Support\RecordingLogger;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpFoundation\Request;

use function json_decode;
use function json_encode;

use const JSON_THROW_ON_ERROR;

/**
 * 1.15 (TT-1): `privacy` on `GET me` when the profile provider also implements
 * {@see ClientUserPrivacyProviderInterface}, in the language of the request.
 */
final class ClientApiPrivacyTest extends TestCase
{
    public function testMeHasNoPrivacyKeyByDefault(): void
    {
        self::assertArrayNotHasKey('privacy', $this->me(null));
    }

    public function testMeSendsTheProvidersBlock(): void
    {
        $block = ['url' => 'https://beacon.test/privacy#time-tracking', 'version' => '3', 'controllerName' => null, 'dpoContact' => null, 'noticeSummary' => 'Summary'];

        self::assertSame($block, $this->me($this->provider($block))['privacy']);
    }

    public function testAProviderMayHaveNothingToSay(): void
    {
        $body = $this->me($this->provider(null));

        self::assertArrayHasKey('privacy', $body);
        self::assertNull($body['privacy']);
    }

    /**
     * @return iterable<string, array{0: string|null, 1: list<string>, 2: string|null}>
     */
    public static function providerLanguages(): iterable
    {
        yield 'no header' => [null, ['es', 'en'], null];
        yield 'blank header' => ['  ', ['es', 'en'], null];
        yield 'best match among the enabled locales' => ['fr, en;q=0.8', ['es', 'en'], 'en'];
        yield 'region of an enabled locale' => ['es-ES,en;q=0.5', ['es', 'en'], 'es'];
        yield 'no match: the first enabled locale' => ['fr', ['es', 'en'], 'es'];
        yield 'no list: the first language' => ['fr-CA, en', [], 'fr_CA'];
        yield 'not a locale' => ['%%%', [], null];
    }

    /**
     * @param list<string> $enabled
     */
    #[DataProvider('providerLanguages')]
    public function testTheProviderIsAskedInTheLanguageOfTheRequest(?string $header, array $enabled, ?string $expected): void
    {
        $provider = $this->provider(['version' => '3']);

        $this->me($provider, $header, $enabled);

        self::assertSame([$expected], $provider->locales);
    }

    /**
     * @return iterable<string, array{0: mixed, 1: mixed}>
     */
    public static function providerUrls(): iterable
    {
        yield 'https' => ['https://beacon.test/privacy', 'https://beacon.test/privacy'];
        yield 'http' => ['http://localhost:8000/privacy', 'http://localhost:8000/privacy'];
        yield 'upper-case scheme' => ['HTTPS://beacon.test/', 'HTTPS://beacon.test/'];
        yield 'none' => [null, null];
        yield 'javascript' => ['javascript:alert(1)', null];
        yield 'javascript hidden by case and blanks' => [' JaVaScRiPt:alert(1)', null];
        yield 'relative' => ['/privacy', null];
        yield 'protocol-relative' => ['//evil.test/', null];
        yield 'no host' => ['https:///privacy', null];
        yield 'not a string' => [['x'], null];
    }

    #[DataProvider('providerUrls')]
    public function testOnlyAnAbsoluteWebUrlIsSent(mixed $url, mixed $expected): void
    {
        self::assertSame(['url' => $expected, 'version' => '3'], ClientPrivacyNormalizer::normalize(['url' => $url, 'version' => '3'], new NullLogger()));
    }

    public function testADroppedUrlIsLoggedWithoutItsValue(): void
    {
        $logger = new RecordingLogger();

        ClientPrivacyNormalizer::normalize(['url' => 'javascript:alert(1)'], $logger);

        self::assertCount(1, $logger->records);
        self::assertStringNotContainsString('alert', (string) json_encode($logger->records));
    }

    public function testABlockWithoutAUrlIsPassedAsIs(): void
    {
        self::assertNull(ClientPrivacyNormalizer::normalize(null, new NullLogger()));
        self::assertSame(['version' => '3'], ClientPrivacyNormalizer::normalize(['version' => '3'], new NullLogger()));
    }

    /**
     * @param array<string, mixed>|null $block
     */
    private function provider(?array $block): StubPrivacyProfileProvider
    {
        return new StubPrivacyProfileProvider($block);
    }

    /**
     * @param list<string> $enabledLocales
     *
     * @return array<string, mixed>
     */
    private function me(?ClientUserProfileProviderInterface $profiles, ?string $language = null, array $enabledLocales = []): array
    {
        $user          = new TestUser('u-1', 'u@example.com');
        $authenticator = $this->createStub(ClientAuthenticatorInterface::class);
        $authenticator->method('authenticate')->willReturn(ClientAuthResult::success($user));
        $stack      = new ClientApiStack($authenticator, new StubTaskProvider(), null, null, new ConfigClientApiSettingsProvider([]));
        $controller = $stack->apiController(profileProvider: $profiles, enabledLocales: $enabledLocales);
        $request    = Request::create('https://beacon.test/api/me', 'GET', [], [], [], ['REMOTE_ADDR' => '192.0.2.1']);
        $request->headers->set('Authorization', 'Bearer ' . $stack->authService->issueToken($user, ClientType::Desktop)['token']);
        $request->headers->set('Accept-Language', $language);
        $response = $controller->me($request);
        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
        /** @var array<string, mixed> $body */
        $body = json_decode((string) $response->getContent(), true, 512, JSON_THROW_ON_ERROR);

        return $body;
    }
}
