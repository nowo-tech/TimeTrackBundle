<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Tests\Functional;

use LogicException;
use Nowo\TimeTrackBundle\DependencyInjection\Compiler\ClientUserCheckerPass;
use Nowo\TimeTrackBundle\Tests\Support\FunctionalKernel;
use Nowo\TimeTrackBundle\Tests\Support\SwitchableUserChecker;
use Symfony\Component\HttpKernel\KernelInterface;

/**
 * Since 1.29 (audit SO-M1, probe P3): the host's `main` firewall keeps Symfony's default user checker
 * (`security.user_checker`, InMemoryUserChecker), which ignores entity users. The account check is on but refuses
 * nobody, so the build says so: a compiler log line (`debug:container --show-arguments` / `var/cache/*Compiler.log`) and `%nowo_time_track.client_api.user_checker.effective%` false.
 * With `client_api.user_checker.required: true` the same host fails to build.
 */
final class ClientApiUserCheckerInMemoryTest extends FunctionalTestCase
{
    private const SECURITY = ['firewalls' => ['main' => ['user_checker' => 'security.user_checker']]];

    protected static function createKernel(array $options = []): KernelInterface
    {
        return new FunctionalKernel(self::timeTrackConfig(), securityConfig: self::SECURITY);
    }

    public function testTheBuildFlagsANoOpChecker(): void
    {
        self::assertFalse(self::getContainer()->getParameter(ClientUserCheckerPass::EFFECTIVE_PARAMETER));

        // The compiler log line itself is pinned by ClientUserCheckerPassTest (the kernel writes Compiler.log outside PHPUnit only).

        // The host's own checker is not the firewall one here, so disabling the account changes nothing.
        $token   = $this->login();
        $checker = self::getContainer()->get(SwitchableUserChecker::class);
        self::assertInstanceOf(SwitchableUserChecker::class, $checker);
        $checker->refuse('person@example.com');
        $this->api('GET', '/api/time-track/v1/me', null, $token);
        self::assertResponseIsSuccessful();
    }

    public function testRequiredFailsTheBuild(): void
    {
        $kernel = new FunctionalKernel(['client_api' => ['user_checker' => ['required' => true]]], securityConfig: self::SECURITY);

        try {
            $kernel->boot();
            self::fail('The build must fail.');
        } catch (LogicException $exception) {
            self::assertStringContainsString('(client_api.user_checker.required is true)', $exception->getMessage());
        } finally {
            $kernel->shutdown();
        }
    }

    public function testTheDefaultHostCheckerIsEffective(): void
    {
        $kernel = new FunctionalKernel(['client_api' => ['user_checker' => ['required' => true]]]);
        $kernel->boot();

        try {
            self::assertTrue($kernel->getContainer()->getParameter(ClientUserCheckerPass::EFFECTIVE_PARAMETER));
        } finally {
            $kernel->shutdown();
        }
    }
}
