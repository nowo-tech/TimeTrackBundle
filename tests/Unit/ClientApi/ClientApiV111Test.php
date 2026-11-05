<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Tests\Unit\ClientApi;

use DateTimeImmutable;
use Nowo\TimeTrackBundle\Bridge\StubTaskProvider;
use Nowo\TimeTrackBundle\Client\ClientAuthenticatorInterface;
use Nowo\TimeTrackBundle\Client\ClientAuthResult;
use Nowo\TimeTrackBundle\Client\ClientUserLocaleProviderInterface;
use Nowo\TimeTrackBundle\Client\ClientUserProfileProviderInterface;
use Nowo\TimeTrackBundle\Client\ConfigClientApiSettingsProvider;
use Nowo\TimeTrackBundle\Controller\TimeTrackClientApiController;
use Nowo\TimeTrackBundle\Entity\TimeEntry;
use Nowo\TimeTrackBundle\Enum\ClientType;
use Nowo\TimeTrackBundle\Import\ImportedTimeEntryData;
use Nowo\TimeTrackBundle\Tests\Stub\TestUser;
use Nowo\TimeTrackBundle\Tests\Support\ClientApiStack;
use Nowo\TimeTrackBundle\Tests\Support\InMemoryTimeEntryRepository;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Translation\Loader\ArrayLoader;
use Symfony\Component\Translation\Translator;

use function array_column;
use function json_decode;

use const JSON_THROW_ON_ERROR;

/**
 * 1.11: `locale` on `GET me` (requested by TimeTrack Desktop) and the translated title of masked imported entries in
 * `GET entries` / `GET export`. The stack clock is fixed at 2026-11-02 10:00:00.
 */
final class ClientApiV111Test extends TestCase
{
    public function testMeHasANullLocaleByDefault(): void
    {
        $user                 = new TestUser('u-1', 'u@example.com');
        [$controller, $token] = $this->controller($user);

        $body = $this->body($controller->me($this->request($token, '/api/me')));

        self::assertArrayHasKey('locale', $body);
        self::assertNull($body['locale']);
    }

    /**
     * @return iterable<string, array{0: string|null, 1: string|null}>
     */
    public static function providerLocales(): iterable
    {
        yield 'language' => ['es', 'es'];
        yield 'region' => ['pt_BR', 'pt_BR'];
        yield 'script and region' => ['zh-Hant-TW', 'zh-Hant-TW'];
        yield 'not a locale' => ['es; DROP', null];
        yield 'empty' => ['', null];
        yield 'unknown' => [null, null];
    }

    #[DataProvider('providerLocales')]
    public function testMeSendsTheProfileProviderLocaleWhenItIsOne(?string $provided, ?string $expected): void
    {
        $user                 = new TestUser('u-1', 'u@example.com');
        [$controller, $token] = $this->controller($user, $this->localeProvider($provided));

        self::assertSame($expected, $this->body($controller->me($this->request($token, '/api/me')))['locale']);
    }

    public function testMeFallsBackToTheUsersOwnLocale(): void
    {
        $user = new class implements UserInterface {
            public function getId(): string
            {
                return 'u-2';
            }

            public function getLocale(): string
            {
                return 'fr';
            }

            public function getRoles(): array
            {
                return ['ROLE_USER'];
            }

            public function eraseCredentials(): void
            {
            }

            public function getUserIdentifier(): string
            {
                return 'fr@example.com';
            }
        };
        [$controller, $token] = $this->controller($user);

        self::assertSame('fr', $this->body($controller->me($this->request($token, '/api/me')))['locale']);
    }

    public function testMaskedTitlesAreTranslatedInTheUsersLocale(): void
    {
        $user    = new TestUser('u-1', 'u@example.com');
        $entries = new InMemoryTimeEntryRepository();
        $start   = new DateTimeImmutable('2026-11-01 09:00:00');
        // task-9 is not trackable for the user in the stub provider: its title is masked.
        $entries->save(TimeEntry::imported($user, new ImportedTimeEntryData('clickup:dir-1', 'e-1', 'task-9', 'Secret title', null, $start, $start->modify('+1 hour'), 3600)));
        $entries->save(TimeEntry::imported($user, new ImportedTimeEntryData('clickup:dir-1', 'e-2', 'task-1', 'Visible title', null, $start, $start->modify('+1 hour'), 3600)));
        $translator = new Translator('en');
        $translator->addLoader('array', new ArrayLoader());
        $translator->addResource('array', ['reports.task_not_visible' => 'Tarea no visible'], 'es', 'NowoTimeTrackBundle');
        $translator->addResource('array', ['reports.task_not_visible' => 'Task not visible'], 'en', 'NowoTimeTrackBundle');

        [$controller, $token] = $this->controller($user, $this->localeProvider('es'), $translator, $entries);

        $titles = array_column($this->body($controller->entries($this->request($token, '/api/entries')))['entries'], 'taskTitle');
        self::assertContains('Tarea no visible', $titles);
        self::assertContains('Visible title', $titles);
        self::assertNotContains('Secret title', $titles);

        $csv = (string) $controller->export($this->request($token, '/api/export?format=csv'))->getContent();
        self::assertStringContainsString('Tarea no visible', $csv);
        self::assertStringNotContainsString('Secret title', $csv);

        $json = $this->body($controller->export($this->request($token, '/api/export?format=json')));
        self::assertContains('Tarea no visible', array_column($json['entries'], 'taskTitle'));

        // Without a translation for the key, the English constant is used.
        [$plain, $plainToken] = $this->controller($user, null, new Translator('en'), $entries);
        self::assertContains(TimeEntry::HIDDEN_TASK_TITLE, array_column($this->body($plain->entries($this->request($plainToken, '/api/entries')))['entries'], 'taskTitle'));

        // Without a translator, too.
        [$bare, $bareToken] = $this->controller($user, null, null, $entries);
        self::assertContains(TimeEntry::HIDDEN_TASK_TITLE, array_column($this->body($bare->entries($this->request($bareToken, '/api/entries')))['entries'], 'taskTitle'));
    }

    private function localeProvider(?string $locale): ClientUserProfileProviderInterface
    {
        return new class($locale) implements ClientUserProfileProviderInterface, ClientUserLocaleProviderInterface {
            public function __construct(private readonly ?string $locale)
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

            public function getLocale(UserInterface $user): ?string
            {
                return $this->locale;
            }
        };
    }

    /**
     * @return array{TimeTrackClientApiController, string}
     */
    private function controller(UserInterface $user, ?ClientUserProfileProviderInterface $profiles = null, ?Translator $translator = null, ?InMemoryTimeEntryRepository $entries = null): array
    {
        $authenticator = $this->createStub(ClientAuthenticatorInterface::class);
        $authenticator->method('authenticate')->willReturn(ClientAuthResult::success($user));
        $stack = new ClientApiStack($authenticator, new StubTaskProvider(), $entries, null, new ConfigClientApiSettingsProvider([]));

        return [$stack->apiController(profileProvider: $profiles, translator: $translator), $stack->authService->issueToken($user, ClientType::Desktop)['token']];
    }

    private function request(string $token, string $uri): Request
    {
        $request = Request::create('https://beacon.test' . $uri, 'GET', [], [], [], ['REMOTE_ADDR' => '192.0.2.1']);
        $request->headers->set('Authorization', 'Bearer ' . $token);

        return $request;
    }

    /**
     * @return array<string, mixed>
     */
    private function body(Response $response): array
    {
        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
        /** @var array<string, mixed> $body */
        $body = json_decode((string) $response->getContent(), true, 512, JSON_THROW_ON_ERROR);

        return $body;
    }
}
