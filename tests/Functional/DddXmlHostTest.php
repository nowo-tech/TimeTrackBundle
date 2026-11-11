<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Tests\Functional;

use Nowo\TimeTrackBundle\Entity\TimeEntry;
use Nowo\TimeTrackBundle\Enum\ClientType;
use Nowo\TimeTrackBundle\Service\ClientAuthService;
use Nowo\TimeTrackBundle\Service\TimerService;
use Nowo\TimeTrackBundle\Tests\Fixtures\DddHost\HostUser;
use Nowo\TimeTrackBundle\Tests\Fixtures\DddHost\SecurityUser;
use Nowo\TimeTrackBundle\Tests\Support\FunctionalKernel;
use Symfony\Component\HttpKernel\KernelInterface;

use function array_column;

/**
 * Since 1.19 (REQ-ARCH-001, the DDD host case): the `user_class` is a plain PHP class mapped by the host in **XML**
 * (no Doctrine attribute, no bundle base class or trait) and the firewall user is a wrapper that only carries the id
 * (`accepted_user_classes`). The bundle boots, persists and reads through its services and the client API.
 */
final class DddXmlHostTest extends FunctionalTestCase
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
        return ['accepted_user_classes' => [SecurityUser::class]];
    }

    public function testAnXmlMappedHostUserDrivesTheServicesAndTheClientApi(): void
    {
        $host = new HostUser('ana@example.com');
        $this->entityManager()->persist($host);
        $this->entityManager()->flush();
        $id = $host->getId();
        self::assertNotNull($id);
        $this->entityManager()->clear();

        // Through the services, with the host's wrapper security user.
        $security = new SecurityUser($id, 'ana@example.com');
        $timers   = self::getContainer()->get(TimerService::class);
        self::assertInstanceOf(TimerService::class, $timers);
        $timer = $timers->start($security, 'task-1', ClientType::Web);
        self::assertInstanceOf(HostUser::class, $timer->getUser());
        $entry = $timers->stop($security);
        self::assertInstanceOf(TimeEntry::class, $entry);
        self::assertSame((string) $id, $entry->getUserId());

        // Through the client API, with a token issued to the wrapper.
        $auth = self::getContainer()->get(ClientAuthService::class);
        self::assertInstanceOf(ClientAuthService::class, $auth);
        $token = $auth->issueToken($security, ClientType::Desktop)['token'];
        $this->entityManager()->clear();

        $me = $this->api('GET', '/api/time-track/me', null, $token);
        self::assertResponseIsSuccessful();
        self::assertSame([(string) $id, 'ana@example.com'], [$me['userId'] ?? null, $me['identifier'] ?? null]);
        $entries = $this->api('GET', '/api/time-track/entries', null, $token);
        self::assertResponseIsSuccessful();
        self::assertSame([$entry->getId()], array_column($entries['entries'] ?? [], 'id'));
    }
}
