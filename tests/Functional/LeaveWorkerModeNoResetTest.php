<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Tests\Functional;

use Nowo\TimeTrackBundle\Enum\ClientScope;
use Nowo\TimeTrackBundle\Enum\ClientType;
use Nowo\TimeTrackBundle\Service\ClientAuthService;
use Nowo\TimeTrackBundle\Tests\Stub\StubLeaveProvider;
use Nowo\TimeTrackBundle\Tests\Stub\StubWorkdayProvider;
use Nowo\TimeTrackBundle\Tests\Support\FunctionalKernel;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Component\HttpKernel\TerminableInterface;
use Symfony\Component\Security\Core\User\UserInterface;

use function array_column;
use function array_key_exists;
use function assert;
use function count;
use function is_array;
use function json_decode;
use function sprintf;

use const JSON_THROW_ON_ERROR;

/**
 * Since 1.22 (REQ-RUNTIME-002, spec 204): one kept-alive kernel, **no services resetter**, three people in turn — one
 * on medical leave, one on vacation, one at work. 300 rounds × 3 people × (`GET workday` + `GET me`) = 1,800 requests
 * straight through `http_kernel`: every answer holds only the caller's leave, the medical one is always *Absent*, and
 * the host port is asked with the caller's id every time (no memo between requests or users).
 */
final class LeaveWorkerModeNoResetTest extends FunctionalTestCase
{
    /**
     * A production-like kernel (debug off).
     *
     * @param array<string, mixed> $options
     */
    protected static function createKernel(array $options = []): KernelInterface
    {
        return new FunctionalKernel(self::timeTrackConfig(), false, false, false);
    }

    protected static function timeTrackConfig(): array
    {
        return ['client_api' => [
            'rate_limit'       => ['per_token' => 0, 'per_ip' => 0],
            'workday_provider' => StubWorkdayProvider::class,
            'leave_provider'   => StubLeaveProvider::class,
        ]];
    }

    public function testNoLeaveLeaksBetweenPeopleWithoutTheServicesResetter(): void
    {
        $vacation = $this->createUser('vacation@example.com');
        $working  = $this->createUser('working@example.com');
        $leaves   = self::getContainer()->get(StubLeaveProvider::class);
        self::assertInstanceOf(StubLeaveProvider::class, $leaves);
        $register = self::getContainer()->get(StubWorkdayProvider::class);
        self::assertInstanceOf(StubWorkdayProvider::class, $register);
        $register->reminder = 'clock_in';

        $sick            = (string) $this->user->getId();
        $leaves->records = [
            $sick                       => ['type' => 'Absence – medical (justified)', 'medical' => true, 'comment' => 'Gripe', 'attachment' => 'note.pdf', 'until' => '2026-11-06'],
            (string) $vacation->getId() => ['type' => 'Vacaciones', 'medical' => false, 'comment' => 'Lisboa', 'attachment' => null, 'until' => '2026-11-20'],
        ];
        $people = [
            $sick                       => ['token' => $this->token($this->user), 'leave' => ['type' => 'Absent', 'until' => '2026-11-06'], 'reminder' => null],
            (string) $vacation->getId() => ['token' => $this->token($vacation), 'leave' => ['type' => 'Vacaciones', 'until' => '2026-11-20'], 'reminder' => null],
            (string) $working->getId()  => ['token' => $this->token($working), 'leave' => null, 'reminder' => 'clock_in'],
        ];

        $asked = [];
        for ($round = 0; $round < 300; ++$round) {
            foreach ($people as $id => $person) {
                $workday = $this->call('GET', '/api/time-track/workday', $person['token']);
                self::assertSame($person['leave'], array_key_exists('leave', $workday) ? $workday['leave'] : 'missing', sprintf('round %d: user %s sees only their own leave', $round, $id));
                self::assertSame($person['reminder'], $workday['reminder'] ?? null, sprintf('round %d: user %s reminder', $round, $id));
                $asked[] = (string) $id;

                $me = $this->call('GET', '/api/time-track/me', $person['token']);
                self::assertSame((string) $id, $me['userId'] ?? null);
                self::assertTrue($me['features']['leave'] ?? null);
            }
        }

        self::assertCount(count($asked), $leaves->calls, 'the port is asked on every GET workday, never answered from a memo');
        self::assertSame($asked, array_column($leaves->calls, 0), 'each time with the caller\'s id');
    }

    private function token(UserInterface $user): string
    {
        $auth = self::getContainer()->get(ClientAuthService::class);
        self::assertInstanceOf(ClientAuthService::class, $auth);

        return (string) $auth->issueToken($user, ClientType::Desktop, null, [ClientScope::WorkdayRead])['token'];
    }

    /**
     * One request straight through the container's `http_kernel` (no services resetter); returns the JSON body.
     *
     * @return array<mixed>
     */
    private function call(string $method, string $uri, string $token): array
    {
        $kernel = self::getContainer()->get('http_kernel');
        assert($kernel instanceof HttpKernelInterface && $kernel instanceof TerminableInterface);
        $request  = Request::create($uri, $method, [], [], [], ['HTTP_AUTHORIZATION' => 'Bearer ' . $token, 'REMOTE_ADDR' => '203.0.113.7']);
        $response = $kernel->handle($request);
        $kernel->terminate($request, $response);
        self::assertSame(200, $response->getStatusCode(), $method . ' ' . $uri . ': ' . $response->getContent());
        $body = json_decode((string) $response->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertTrue(is_array($body));

        return $body;
    }
}
