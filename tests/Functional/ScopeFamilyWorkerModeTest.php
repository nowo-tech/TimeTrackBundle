<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Tests\Functional;

use Nowo\TimeTrackBundle\Client\ClientFamilyPrincipal;
use Nowo\TimeTrackBundle\Client\ClientFamilyTokenAuthenticator;
use Nowo\TimeTrackBundle\Client\ClientTokenRevoker;
use Nowo\TimeTrackBundle\Enum\ClientType;
use Nowo\TimeTrackBundle\Service\ClientAuthService;
use Nowo\TimeTrackBundle\Tests\Stub\RecordingFamilyCredentialMinter;
use Nowo\TimeTrackBundle\Tests\Stub\StubClientContextPolicy;
use Nowo\TimeTrackBundle\Tests\Support\FunctionalKernel;
use Nowo\TimeTrackBundle\Tests\Support\RecordingLogger;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Component\HttpKernel\TerminableInterface;

use function assert;
use function gc_collect_cycles;
use function is_array;
use function json_decode;
use function memory_get_usage;
use function sprintf;

use const JSON_THROW_ON_ERROR;

/**
 * Since 2.2.0 (Beacon Desktop R7, REQ-RUNTIME-002): scope families and the tenant context in FrankenPHP worker mode, the
 * kernel kept alive and **not reset**. Two people in two contexts take turns on one kernel — one with a `time` token on
 * the time API, the other with a `chat` token through the family authenticator; the chat token is revoked mid-run.
 * Every answer holds only the caller's own context and family, a revoked token stops at its next use, and the memory
 * stays flat.
 */
final class ScopeFamilyWorkerModeTest extends FunctionalTestCase
{
    /**
     * A production-like kernel (debug off): the framework's own profilers are not the bundle's memory.
     *
     * @param array<string, mixed> $options
     */
    protected static function createKernel(array $options = []): KernelInterface
    {
        return new FunctionalKernel(self::timeTrackConfig(), false, false, false);
    }

    protected static function timeTrackConfig(): array
    {
        return [
            'client_api' => [
                'rate_limit'        => ['per_token' => 0, 'per_ip' => 0],
                'device_flow'       => ['enabled' => true, 'scope_families' => ['chat' => ['label' => 'Chat', 'scopes' => ['chat:read' => 'Read']]]],
                'context'           => ['policy' => StubClientContextPolicy::class, 'header' => 'X-Beacon-Context'],
                'credential_issuer' => RecordingFamilyCredentialMinter::class,
            ],
        ];
    }

    public function testTwoPeopleTwoContextsTwoFamiliesAndARevocationInOneKernel(): void
    {
        $other = $this->createUser('other@example.com');
        $this->policy()->reset();
        $time = $this->login();
        $chat = $this->auth()->issueFamilyToken($other, ClientType::Desktop, 'cred-chat', 'chat', ['chat:read'], 'dev-o', 'Phone');

        for ($round = 0; $round < 250; ++$round) {
            $me = $this->me($time, 'org:acme');
            self::assertSame('org:acme', $me['context'] ?? null, sprintf('round %d', $round));
            self::assertSame('person@example.com', $me['identifier'] ?? null);

            $answer = $this->authenticator()->authenticate('chat', 'Bearer ' . $chat['token'], 'chat:read', '203.0.113.8', $round % 2 === 0 ? 'personal' : 'org:acme');
            if ($round < 125) {
                // The other person is no member of org:acme: refused there, allowed in personal; never the first one's context.
                self::assertSame($round % 2 === 0 ? 'personal' : null, $answer->principal?->contextKey, sprintf('round %d', $round));
                self::assertSame($round % 2 === 0 ? null : 'context_forbidden', $answer->error);
                if ($answer->principal instanceof ClientFamilyPrincipal) {
                    self::assertSame((string) $other->getId(), $answer->principal->userId);
                }
            } else {
                self::assertSame('invalid_token', $answer->error, sprintf('round %d: revoked', $round));
            }
            if ($round === 124) {
                $revoker = self::getContainer()->get(ClientTokenRevoker::class);
                self::assertInstanceOf(ClientTokenRevoker::class, $revoker);
                self::assertSame(1, $revoker->revokeByCredential('cred-chat'));
            }
        }
    }

    public function testTheFamilyPathsKeepAFlatMemoryWithoutTheServicesResetter(): void
    {
        $other = $this->createUser('other@example.com');
        $this->policy()->reset();
        $time   = $this->login();
        $chat   = $this->auth()->issueFamilyToken($other, ClientType::Desktop, 'cred-chat', 'chat', ['chat:read'])['token'];
        $logger = self::getContainer()->get('logger');
        self::assertInstanceOf(RecordingLogger::class, $logger);
        $policy = $this->policy();
        $step   = function (int $i) use ($time, $chat, $logger, $policy): void {
            $this->me($time, $i % 2 === 0 ? 'personal' : 'org:acme');
            $this->authenticator()->authenticate('chat', 'Bearer ' . $chat, 'chat:read', '203.0.113.8', 'personal');
            $logger->records = [];
            $policy->asked   = [];
        };

        for ($i = 0; $i < 100; ++$i) {
            $step($i);
        }
        gc_collect_cycles();
        $start = memory_get_usage();
        for ($i = 0; $i < 1_000; ++$i) {
            $step($i);
        }
        gc_collect_cycles();
        $grown = memory_get_usage() - $start;

        self::assertLessThan(64 * 1024, $grown, sprintf('1,000 steps grew the memory by %d bytes', $grown));
    }

    /**
     * `GET me` straight through the container's `http_kernel` (no services resetter).
     *
     * @return array<mixed>
     */
    private function me(string $token, string $context): array
    {
        $kernel = self::getContainer()->get('http_kernel');
        assert($kernel instanceof HttpKernelInterface && $kernel instanceof TerminableInterface);
        $request = Request::create('/api/time-track/v1/me', 'GET', [], [], [], [
            'HTTP_AUTHORIZATION'    => 'Bearer ' . $token,
            'HTTP_X_BEACON_CONTEXT' => $context,
            'REMOTE_ADDR'           => '203.0.113.7',
        ]);
        $response = $kernel->handle($request);
        $kernel->terminate($request, $response);
        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
        $body = json_decode((string) $response->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertTrue(is_array($body));

        return $body;
    }

    private function policy(): StubClientContextPolicy
    {
        $policy = self::getContainer()->get(StubClientContextPolicy::class);
        self::assertInstanceOf(StubClientContextPolicy::class, $policy);

        return $policy;
    }

    private function auth(): ClientAuthService
    {
        $auth = self::getContainer()->get(ClientAuthService::class);
        self::assertInstanceOf(ClientAuthService::class, $auth);

        return $auth;
    }

    private function authenticator(): ClientFamilyTokenAuthenticator
    {
        $authenticator = self::getContainer()->get(ClientFamilyTokenAuthenticator::class);
        self::assertInstanceOf(ClientFamilyTokenAuthenticator::class, $authenticator);

        return $authenticator;
    }
}
