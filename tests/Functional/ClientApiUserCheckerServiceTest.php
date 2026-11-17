<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Tests\Functional;

use Nowo\TimeTrackBundle\Entity\ClientToken;
use Nowo\TimeTrackBundle\Enum\ClientType;
use Nowo\TimeTrackBundle\Service\ClientAuthService;
use Nowo\TimeTrackBundle\Tests\Fixtures\DddHost\HostUser;
use Nowo\TimeTrackBundle\Tests\Fixtures\DddHost\SecurityUser;
use Nowo\TimeTrackBundle\Tests\Support\FunctionalKernel;
use Nowo\TimeTrackBundle\Tests\Support\SwitchableUserChecker;
use Symfony\Component\HttpKernel\KernelInterface;

/**
 * Since 1.29: `client_api.user_checker: <service id>` (string shorthand) on a DDD host (plain PHP `user_class` mapped
 * in XML, wrapper security user). The checker receives the token's user (the host entity, refreshed from the
 * database) on every use; a refused account gets 401 and its token is deleted.
 */
final class ClientApiUserCheckerServiceTest extends FunctionalTestCase
{
    /**
     * @param array<string, mixed> $options
     */
    protected static function createKernel(array $options = []): KernelInterface
    {
        return new FunctionalKernel(self::timeTrackConfig(), false, true);
    }

    protected static function timeTrackConfig(): array
    {
        return [
            'accepted_user_classes' => [SecurityUser::class],
            'client_api'            => ['user_checker' => SwitchableUserChecker::class],
        ];
    }

    public function testTheConfiguredCheckerRefusesADisabledHostAccount(): void
    {
        $host = new HostUser('ana@example.com');
        $this->entityManager()->persist($host);
        $this->entityManager()->flush();
        $id = $host->getId();
        self::assertNotNull($id);
        $this->entityManager()->clear();

        $auth = self::getContainer()->get(ClientAuthService::class);
        self::assertInstanceOf(ClientAuthService::class, $auth);
        $token = $auth->issueToken(new SecurityUser($id, 'ana@example.com'), ClientType::Desktop)['token'];
        $this->entityManager()->clear();

        $checker = self::getContainer()->get(SwitchableUserChecker::class);
        self::assertInstanceOf(SwitchableUserChecker::class, $checker);
        $checker->checked = [];

        $this->api('GET', '/api/time-track/v1/me', null, $token);
        self::assertResponseIsSuccessful();
        self::assertSame(['pre:ana@example.com', 'post:ana@example.com'], $checker->checked);

        $checker->refuse('ana@example.com');
        $body = $this->api('GET', '/api/time-track/me', null, $token);
        self::assertResponseStatusCodeSame(401);
        self::assertSame('invalid_token', $body['error'] ?? null);
        self::assertSame(0, $this->entityManager()->getRepository(ClientToken::class)->count([]));
    }
}
