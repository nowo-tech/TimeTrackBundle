<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Tests\Functional;

use Nowo\TimeTrackBundle\Entity\ClientToken;
use Nowo\TimeTrackBundle\Entity\DeviceAuthorization;
use Nowo\TimeTrackBundle\Repository\ClientTokenRepositoryInterface;
use Nowo\TimeTrackBundle\Service\ClientAuthService;
use Nowo\TimeTrackBundle\Tests\Support\SwitchableUserChecker;
use ReflectionClassConstant;
use Throwable;

use function explode;
use function in_array;
use function strlen;
use function strtr;
use function substr;

/**
 * Since 1.29 (`client_api.user_checker`, audit SO probes P2): a disabled account on every Bearer route by both paths
 * (refused in checkPreAuth() and in checkPostAuth()), no token for a disabled account at password login or device
 * exchange (SO-L1), logout still revokes, and a revoke racing another request is harmless.
 */
final class ClientApiUserCheckerEveryRouteTest extends FunctionalTestCase
{
    /** Routes without a Bearer check (logout revokes whatever account the token has). */
    private const UNAUTHENTICATED = ['login', 'logout', 'device_code', 'device_token'];

    protected static function timeTrackConfig(): array
    {
        return ['client_api' => ['device_flow' => ['enabled' => true]]];
    }

    public function testEveryBearerRouteRefusesADisabledAccount(): void
    {
        $refused = 0;
        foreach ($this->routeTable() as [, $legacyPath, $v1Name, $v1Path, $methods]) {
            if (in_array(substr($v1Name, strlen('nowo_time_track_api_v1_')), self::UNAUTHENTICATED, true)) {
                continue;
            }
            $method = explode('|', $methods)[0];
            foreach ([$legacyPath, $v1Path] as $path) {
                foreach (['pre', 'post'] as $phase) {
                    $this->checker()->allow('person@example.com');
                    $token = $this->login();
                    $this->checker()->refuse('person@example.com', $phase);
                    $body = $this->api($method, $this->uri($path), ['taskId' => 'task-1'], $token);
                    if (($body['error'] ?? null) === 'not_found') {
                        // Feature off in this kernel (meetings, monitoring): 404 before authentication, for everyone.
                        $this->checker()->allow('person@example.com');
                        $this->api($method, $this->uri($path), ['taskId' => 'task-1'], $token);
                        self::assertResponseStatusCodeSame(404, $path);
                        $this->entityManager()->getConnection()->executeStatement('DELETE FROM time_track_client_tokens');
                        continue;
                    }
                    self::assertResponseStatusCodeSame(401, $method . ' ' . $path . ' ' . $phase);
                    self::assertSame('invalid_token', $body['error'] ?? null, $path);
                    self::assertSame(0, $this->tokenCount(), $path . ' token deleted');
                    ++$refused;
                }
            }
        }
        self::assertSame(92, $refused, '23 Bearer routes x 2 paths x 2 phases');
    }

    public function testADisabledAccountGetsNoTokenAtLogin(): void
    {
        $this->checker()->refuse('person@example.com');

        foreach (['/api/time-track/login', '/api/time-track/v1/login'] as $path) {
            $body = $this->api('POST', $path, ['username' => 'person@example.com', 'password' => 'secret', 'clientType' => 'desktop']);
            self::assertResponseStatusCodeSame(401);
            self::assertSame('invalid_credentials', $body['error'] ?? null);
        }
        self::assertSame(0, $this->tokenCount());

        // Two failed attempts reached the login throttle of this kernel (max_attempts 2): sign in from another address.
        $this->checker()->allow('person@example.com');
        $this->login(server: ['REMOTE_ADDR' => '198.51.100.9']);
        self::assertSame(1, $this->tokenCount());
    }

    public function testADeviceApprovedBeforeTheAccountWasDisabledGetsNoToken(): void
    {
        $grant = $this->api('POST', '/api/time-track/v1/device/code', ['clientType' => 'desktop', 'clientName' => 'Tray', 'scopes' => ['timer:read']]);
        self::assertResponseIsSuccessful();
        self::assertIsArray($grant);
        $this->client->loginUser($this->user, 'main');
        $crawler = $this->client->request('GET', '/tools/time-track/device?code=' . $grant['userCode']);
        $crawler = $this->client->submit($crawler->filter('form')->form());
        $this->client->submit($crawler->filter('[data-testid="time-track-device-approve"]')->form());
        self::assertResponseIsSuccessful();

        $this->checker()->refuse('person@example.com');
        $body = $this->api('POST', '/api/time-track/v1/device/token', ['deviceCode' => $grant['deviceCode']]);
        self::assertResponseStatusCodeSame(400);
        self::assertSame('access_denied', $body['error'] ?? null);
        self::assertSame(0, $this->tokenCount());
        self::assertSame(0, $this->entityManager()->getRepository(DeviceAuthorization::class)->count([]), 'the approval is consumed');

        $this->checker()->allow('person@example.com');
        $this->api('POST', '/api/time-track/device/token', ['deviceCode' => $grant['deviceCode']]);
        self::assertResponseStatusCodeSame(400);
        self::assertSame(0, $this->tokenCount());
    }

    public function testLogoutStillRevokesTheTokenOfADisabledAccount(): void
    {
        $token = $this->login();
        $this->checker()->refuse('person@example.com');

        $this->api('POST', '/api/time-track/v1/logout', null, $token);
        self::assertResponseStatusCodeSame(204);
        self::assertSame(0, $this->tokenCount());
    }

    public function testARevokeRacingAnotherRequestIsHarmless(): void
    {
        $token = $this->login();
        $repo  = self::getContainer()->get(ClientTokenRepositoryInterface::class);
        self::assertInstanceOf(ClientTokenRepositoryInterface::class, $repo);
        $entity = $repo->findValidByTokenHash(ClientAuthService::hashToken($token));
        self::assertInstanceOf(ClientToken::class, $entity);
        // Another request deleted it first.
        $this->entityManager()->getConnection()->executeStatement('DELETE FROM time_track_client_tokens');
        $error = null;
        try {
            $repo->remove($entity);
        } catch (Throwable $error) {
        }
        self::assertNull($error);

        $second = $this->login();
        $this->checker()->refuse('person@example.com');
        $this->api('GET', '/api/time-track/me', null, $second);
        self::assertResponseStatusCodeSame(401);
        $this->api('GET', '/api/time-track/v1/me', null, $second);
        self::assertResponseStatusCodeSame(401);
    }

    private function checker(): SwitchableUserChecker
    {
        $checker = self::getContainer()->get(SwitchableUserChecker::class);
        self::assertInstanceOf(SwitchableUserChecker::class, $checker);

        return $checker;
    }

    private function tokenCount(): int
    {
        return $this->entityManager()->getRepository(ClientToken::class)->count([]);
    }

    /**
     * @return list<array{string, string, string, string, string}>
     */
    private function routeTable(): array
    {
        /** @var list<array{string, string, string, string, string}> $routes */
        $routes = (new ReflectionClassConstant(ClientApiV1AliasTest::class, 'ROUTES'))->getValue();

        return $routes;
    }

    private function uri(string $path): string
    {
        return strtr($path, ['{action}' => 'clock-in', '{periodKey}' => 'current', '{id}' => 'unknown-id', '{uploadId}' => '0b6b3c2e-4f1a-4c1e-9d55-1f6e2a7b9c10', '{screenshotId}' => 'shot-1']);
    }
}
