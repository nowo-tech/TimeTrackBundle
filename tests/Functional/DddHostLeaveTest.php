<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Tests\Functional;

use Nowo\TimeTrackBundle\Enum\ClientScope;
use Nowo\TimeTrackBundle\Enum\ClientType;
use Nowo\TimeTrackBundle\Service\ClientAuthService;
use Nowo\TimeTrackBundle\Tests\Fixtures\DddHost\HostUser;
use Nowo\TimeTrackBundle\Tests\Fixtures\DddHost\SecurityUser;
use Nowo\TimeTrackBundle\Tests\Stub\StubLeaveProvider;
use Nowo\TimeTrackBundle\Tests\Stub\StubWorkdayProvider;
use Nowo\TimeTrackBundle\Tests\Support\FunctionalKernel;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\HttpKernel\KernelInterface;

/**
 * Since 1.22 (REQ-ARCH-001, the DDD host case, spec 204): an XML-mapped host user, a wrapper security user and a
 * framework-free leave adapter (user id string in, bundle value object out). `GET workday` sends the leave of the
 * wrapper's id, medical leave as *Absent*, and the container passes `lint:container` with the new port wired.
 */
final class DddHostLeaveTest extends FunctionalTestCase
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
            'client_api'            => ['workday_provider' => StubWorkdayProvider::class, 'leave_provider' => StubLeaveProvider::class, 'links_leave_route' => 'nowo_time_track_index'],
        ];
    }

    public function testAHexagonalHostReportsLeaveByUserId(): void
    {
        $host = new HostUser('ana@example.com');
        $this->entityManager()->persist($host);
        $this->entityManager()->flush();
        $id = $host->getId();
        self::assertNotNull($id);
        $this->entityManager()->clear();

        $leaves = self::getContainer()->get(StubLeaveProvider::class);
        self::assertInstanceOf(StubLeaveProvider::class, $leaves);
        $leaves->records = [(string) $id => ['type' => 'Absence – medical (justified)', 'medical' => true, 'comment' => 'Gripe', 'attachment' => 'note.pdf', 'until' => '2026-11-09']];

        $auth = self::getContainer()->get(ClientAuthService::class);
        self::assertInstanceOf(ClientAuthService::class, $auth);
        $token = $auth->issueToken(new SecurityUser($id, 'ana@example.com'), ClientType::Extension, null, [ClientScope::WorkdayRead])['token'];

        $workday = $this->api('GET', '/api/time-track/workday', null, $token);
        self::assertResponseIsSuccessful();
        self::assertSame(['type' => 'Absent', 'until' => '2026-11-09'], $workday['leave'] ?? null);
        self::assertSame((string) $id, $leaves->calls[0][0] ?? null, 'the port receives the id string, never a user object');

        $me = $this->api('GET', '/api/time-track/me', null, $token);
        self::assertTrue($me['features']['leave'] ?? null);
        self::assertArrayHasKey('leave', $me['links'] ?? []);
    }

    public function testTheContainerLints(): void
    {
        $kernel = self::$kernel;
        self::assertInstanceOf(KernelInterface::class, $kernel);
        $tester = new CommandTester((new Application($kernel))->find('lint:container'));

        self::assertSame(0, $tester->execute([]), $tester->getDisplay());
        self::assertStringContainsString('The container was linted successfully', $tester->getDisplay());
    }
}
