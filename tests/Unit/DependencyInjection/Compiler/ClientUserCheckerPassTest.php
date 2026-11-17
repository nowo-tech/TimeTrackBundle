<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Tests\Unit\DependencyInjection\Compiler;

use LogicException;
use Nowo\TimeTrackBundle\Client\ClientAccountChecker;
use Nowo\TimeTrackBundle\DependencyInjection\Compiler\ClientUserCheckerPass;
use Nowo\TimeTrackBundle\Tests\Support\SwitchableUserChecker;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\Argument\TaggedIteratorArgument;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\Security\Core\User\ChainUserChecker;
use Symfony\Component\Security\Core\User\InMemoryUserChecker;

use function implode;

/**
 * Since 1.29: `client_api.user_checker` without `service` resolves `security.user_checker.<firewall>`; a checker that
 * refuses nobody (none, Symfony's default InMemoryUserChecker, an empty chain) is logged and flagged
 * `effective: false`, or fails the build with `required` (audit SO-M1).
 */
final class ClientUserCheckerPassTest extends TestCase
{
    public function testNoOpWithoutTheDefinitionOrTheTag(): void
    {
        $container = new ContainerBuilder();
        (new ClientUserCheckerPass())->process($container);
        self::assertFalse($container->hasDefinition(ClientAccountChecker::class));

        $container  = $this->container(['main'], tagged: false);
        $definition = $container->getDefinition(ClientAccountChecker::class);
        (new ClientUserCheckerPass())->process($container);
        self::assertSame('fallback', (string) $definition->getArgument('$userChecker'));
        self::assertFalse($container->hasParameter(ClientUserCheckerPass::EFFECTIVE_PARAMETER));
    }

    public function testPrefersTheMainFirewall(): void
    {
        $container = $this->container(['api', 'main']);
        (new ClientUserCheckerPass())->process($container);

        $definition = $container->getDefinition(ClientAccountChecker::class);
        self::assertSame('security.user_checker.main', (string) $definition->getArgument('$userChecker'));
        self::assertFalse($definition->hasTag(ClientUserCheckerPass::TAG));
        self::assertTrue($container->getParameter(ClientUserCheckerPass::EFFECTIVE_PARAMETER));
        self::assertSame([], $container->getCompiler()->getLog());
    }

    public function testFallsBackToTheFirstFirewallWithSecurity(): void
    {
        $container = $this->container(['dev', 'admin'], withChecker: ['admin']);
        (new ClientUserCheckerPass())->process($container);

        self::assertSame('security.user_checker.admin', (string) $container->getDefinition(ClientAccountChecker::class)->getArgument('$userChecker'));
    }

    public function testWithoutAnyCheckerTheBuildLogsANoOp(): void
    {
        $container = $this->container([]);
        $container->setParameter('security.firewalls', 'not-a-list');
        (new ClientUserCheckerPass())->process($container);

        self::assertSame('fallback', (string) $container->getDefinition(ClientAccountChecker::class)->getArgument('$userChecker'));
        self::assertFalse($container->getParameter(ClientUserCheckerPass::EFFECTIVE_PARAMETER));
        self::assertStringContainsString('refuses nobody: the service "fallback" does not exist', implode("\n", $container->getCompiler()->getLog()));
    }

    public function testANullCheckerIsANoOp(): void
    {
        $container = $this->container([]);
        $container->getDefinition(ClientAccountChecker::class)->setArgument('$userChecker', null);
        (new ClientUserCheckerPass())->process($container);

        self::assertStringContainsString('refuses nobody: no user checker was found', implode("\n", $container->getCompiler()->getLog()));
    }

    public function testSymfonysDefaultCheckerIsANoOp(): void
    {
        // A firewall without `user_checker`: security.user_checker.main → security.user_checker (InMemoryUserChecker).
        $container = $this->container(['main'], withChecker: []);
        $container->setDefinition('security.user_checker', new Definition(InMemoryUserChecker::class));
        $container->setAlias('security.user_checker.main', 'security.user_checker');
        (new ClientUserCheckerPass())->process($container);

        self::assertFalse($container->getParameter(ClientUserCheckerPass::EFFECTIVE_PARAMETER));
        self::assertStringContainsString('refuses nobody: it resolves to Symfony\'s default security.user_checker (InMemoryUserChecker)', implode("\n", $container->getCompiler()->getLog()));
    }

    public function testAnInMemoryCheckerUnderAnotherIdIsANoOp(): void
    {
        $container = $this->container([], explicit: 'app.checker');
        $container->setParameter('app.checker_class', InMemoryUserChecker::class);
        $container->setDefinition('app.checker', new Definition('%app.checker_class%'));
        (new ClientUserCheckerPass())->process($container);

        self::assertSame('app.checker', (string) $container->getDefinition(ClientAccountChecker::class)->getArgument('$userChecker'), 'an explicit service is kept');
        self::assertStringContainsString('"app.checker" is an InMemoryUserChecker', implode("\n", $container->getCompiler()->getLog()));
    }

    public function testAnEmptyChainIsANoOpAndAFilledOneIsEffective(): void
    {
        $container = $this->container([], explicit: 'security.user_checker.chain.main');
        $container->setDefinition('security.user_checker.chain.main', new Definition(ChainUserChecker::class, [new TaggedIteratorArgument('security.user_checker.main')]));
        (new ClientUserCheckerPass())->process($container);
        self::assertStringContainsString('is a ChainUserChecker with no checker tagged "security.user_checker.main"', implode("\n", $container->getCompiler()->getLog()));

        $container = $this->container([], explicit: 'security.user_checker.chain.main');
        $container->setDefinition('security.user_checker.chain.main', new Definition(ChainUserChecker::class, [new TaggedIteratorArgument('security.user_checker.main')]));
        $container->setDefinition('app.checker', (new Definition(SwitchableUserChecker::class))->addTag('security.user_checker.main'));
        (new ClientUserCheckerPass())->process($container);
        self::assertTrue($container->getParameter(ClientUserCheckerPass::EFFECTIVE_PARAMETER));
    }

    public function testAnExplicitCheckerIsEffective(): void
    {
        $container = $this->container(['main'], explicit: 'app.checker');
        $container->setDefinition('app.checker', new Definition(SwitchableUserChecker::class));
        (new ClientUserCheckerPass())->process($container);

        self::assertSame('app.checker', (string) $container->getDefinition(ClientAccountChecker::class)->getArgument('$userChecker'));
        self::assertTrue($container->getParameter(ClientUserCheckerPass::EFFECTIVE_PARAMETER));
    }

    public function testRequiredFailsTheBuildOnANoOp(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('(client_api.user_checker.required is true)');

        (new ClientUserCheckerPass())->process($this->container([], required: true));
    }

    public function testUsesTheConfiguredFirewall(): void
    {
        $container = $this->container(['main', 'api'], 'api');
        (new ClientUserCheckerPass())->process($container);

        self::assertSame('security.user_checker.api', (string) $container->getDefinition(ClientAccountChecker::class)->getArgument('$userChecker'));
    }

    public function testAnUnknownConfiguredFirewallFails(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('nowo_time_track.client_api.user_checker.firewall "nope" is not a firewall with security enabled (known firewalls: main).');

        (new ClientUserCheckerPass())->process($this->container(['main'], 'nope'));
    }

    public function testAnUnknownConfiguredFirewallWithoutFirewallsFails(): void
    {
        $this->expectExceptionMessage('(known firewalls: none)');

        (new ClientUserCheckerPass())->process($this->container([], 'nope'));
    }

    /**
     * @param list<string> $firewalls
     * @param list<string>|null $withChecker firewalls with a user checker service (default: all)
     */
    private function container(array $firewalls, ?string $firewall = null, bool $tagged = true, ?array $withChecker = null, ?string $explicit = null, bool $required = false): ContainerBuilder
    {
        $container = new ContainerBuilder();
        $container->setParameter('security.firewalls', [...$firewalls, 42]);
        foreach ($withChecker ?? $firewalls as $name) {
            $container->setDefinition('security.user_checker.' . $name, new Definition(SwitchableUserChecker::class));
        }
        $definition = (new Definition(ClientAccountChecker::class))->setArgument('$userChecker', $explicit !== null ? new Reference($explicit) : new Reference('fallback', ContainerInterface::NULL_ON_INVALID_REFERENCE));
        if ($tagged) {
            $definition->addTag(ClientUserCheckerPass::TAG, ['firewall' => $firewall, 'auto' => $explicit === null, 'required' => $required]);
        }
        $container->setDefinition(ClientAccountChecker::class, $definition);

        return $container;
    }
}
