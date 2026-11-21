<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Tests\Functional;

use Nowo\TimeTrackBundle\Entity\ClientToken;
use Nowo\TimeTrackBundle\Tests\Support\FunctionalKernel;
use Nowo\TimeTrackBundle\Tests\Support\SwitchableUserChecker;
use ReflectionClassConstant;
use Symfony\Component\HttpKernel\KernelInterface;

use function explode;
use function str_contains;
use function strtr;

/**
 * Since 1.29 (audit SO, probe P1): a host whose stateless API firewall was written for the unversioned paths only
 * (`^/api/time-track/(?!v1)`), so the v1 paths fall into the stateful `main` firewall. Every v1 route still refuses
 * exactly like its unversioned twin (no token, bad token, disabled account), preflights stay a bare 204, no session is
 * started, and a session alone never authenticates a v1 call: the bundle's Bearer check does not depend on the
 * host firewall.
 */
final class ClientApiV1HostFirewallTest extends FunctionalTestCase
{
    protected static function createKernel(array $options = []): KernelInterface
    {
        return new FunctionalKernel(self::timeTrackConfig(), securityConfig: ['firewalls' => ['time_track_api' => ['pattern' => '^/api/time-track/(?!v1)']]]);
    }

    protected static function timeTrackConfig(): array
    {
        return ['client_api' => ['device_flow' => ['enabled' => true]]];
    }

    public function testEveryV1RouteRefusesLikeItsUnversionedTwin(): void
    {
        foreach ($this->routeTable() as [, $legacyPath, $v1Name, $v1Path, $methods]) {
            $method = explode('|', $methods)[0];
            foreach (['no token' => null, 'bad token' => 'not-a-real-token'] as $kind => $token) {
                $this->api($method, $this->uri($legacyPath), [], $token);
                $legacy = $this->client->getResponse()->getStatusCode();
                $this->api($method, $this->uri($v1Path), [], $token);
                self::assertSame($legacy, $this->client->getResponse()->getStatusCode(), $v1Name . ' ' . $kind);
            }
            if (str_contains($methods, 'OPTIONS')) {
                $this->api('OPTIONS', $this->uri($v1Path), null, null, ['HTTP_ORIGIN' => 'chrome-extension://functional', 'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => $method]);
                self::assertResponseStatusCodeSame(204, $v1Name . ' preflight');
                self::assertSame([], $this->client->getResponse()->headers->getCookies(), $v1Name . ' no session cookie');
            }
        }

        $token = $this->login();
        $this->api('GET', '/api/time-track/v1/me', null, $token);
        self::assertResponseIsSuccessful();
        self::assertSame([], $this->client->getResponse()->headers->getCookies(), 'no session started by the main firewall');

        $checker = self::getContainer()->get(SwitchableUserChecker::class);
        self::assertInstanceOf(SwitchableUserChecker::class, $checker);
        $checker->refuse('person@example.com');
        $this->api('GET', '/api/time-track/v1/me', null, $token);
        self::assertResponseStatusCodeSame(401);
        self::assertSame(0, $this->entityManager()->getRepository(ClientToken::class)->count([]));
    }

    public function testASessionWithoutABearerTokenGetsNothingOnV1(): void
    {
        $this->client->loginUser($this->user, 'main');

        $this->api('GET', '/api/time-track/v1/me');
        self::assertResponseStatusCodeSame(401);
        $this->api('POST', '/api/time-track/v1/timer/start', ['taskId' => 'task-1']);
        self::assertResponseStatusCodeSame(401);
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
