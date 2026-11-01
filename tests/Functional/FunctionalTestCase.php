<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Tests\Functional;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Nowo\TimeTrackBundle\Tests\Fixtures\FunctionalEntity\AppUser;
use Nowo\TimeTrackBundle\Tests\Support\FunctionalKernel;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpKernel\KernelInterface;

use function json_decode;
use function json_encode;

use const JSON_THROW_ON_ERROR;

/**
 * Boots {@see FunctionalKernel} (variant from {@see self::timeTrackConfig()}), creates the schema in the in-memory
 * SQLite database and a signed-up user `person@example.com` / `secret`. The browser never reboots the kernel, so the
 * database lives for the whole test.
 */
abstract class FunctionalTestCase extends WebTestCase
{
    protected KernelBrowser $client;

    protected AppUser $user;

    /**
     * @param array<string, mixed> $options
     */
    protected static function createKernel(array $options = []): KernelInterface
    {
        return new FunctionalKernel(static::timeTrackConfig());
    }

    /**
     * Overrides merged into the `nowo_time_track` configuration of the kernel.
     *
     * @return array<string, mixed>
     */
    protected static function timeTrackConfig(): array
    {
        return [];
    }

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot();

        /** @var CacheItemPoolInterface $throttle */
        $throttle = static::getContainer()->get('cache.time_track_test');
        $throttle->clear();

        $em = $this->entityManager();
        (new SchemaTool($em))->createSchema($em->getMetadataFactory()->getAllMetadata());

        $this->user = $this->createUser('person@example.com');
    }

    protected function entityManager(): EntityManagerInterface
    {
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine.orm.entity_manager');

        return $em;
    }

    /**
     * @param non-empty-string $email
     * @param list<string> $roles
     */
    protected function createUser(string $email, array $roles = ['ROLE_USER']): AppUser
    {
        $user = new AppUser($email, 'secret', $roles);
        $this->entityManager()->persist($user);
        $this->entityManager()->flush();

        return $user;
    }

    /**
     * @param array<string, mixed>|null $payload
     * @param array<string, string> $server
     *
     * @return array<mixed>|null decoded JSON body (null when empty)
     */
    protected function api(string $method, string $uri, ?array $payload = null, ?string $token = null, array $server = []): ?array
    {
        if ($token !== null) {
            $server['HTTP_AUTHORIZATION'] = 'Bearer ' . $token;
        }
        $server['CONTENT_TYPE'] = 'application/json';

        $this->client->request($method, $uri, [], [], $server, $payload === null ? '' : json_encode($payload, JSON_THROW_ON_ERROR));
        $content = (string) $this->client->getResponse()->getContent();

        /** @var array<mixed>|null $decoded */
        $decoded = $content === '' ? null : json_decode($content, true, 512, JSON_THROW_ON_ERROR);

        return $decoded;
    }

    protected function login(string $username = 'person@example.com', string $password = 'secret', string $clientType = 'desktop'): string
    {
        $body = $this->api('POST', '/api/time-track/login', ['username' => $username, 'password' => $password, 'clientType' => $clientType]);
        self::assertResponseIsSuccessful();
        self::assertIsArray($body);
        self::assertIsString($body['token'] ?? null);

        return $body['token'];
    }
}
