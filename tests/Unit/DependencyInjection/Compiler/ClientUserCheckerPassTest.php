<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Tests\Unit\DependencyInjection\Compiler;

use LogicException;
use Nowo\TimeTrackBundle\Client\ClientAccountChecker;
use Nowo\TimeTrackBundle\DependencyInjection\Compiler\ClientUserCheckerPass;
use PHPUnit\Framework\TestCase;
use stdClass;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;

/**
 * Since 1.29: `client_api.user_checker` without `service` resolves `security.user_checker.<firewall>`.
 */
final class ClientUserCheckerPassTest extends TestCase
{
    public function testNoOpWithoutTheDefinitionOrTheTag(): void
    {
        $container = new ContainerBuilder();
        (new ClientUserCheckerPass())->process($container);
        self::assertFalse($container->hasDefinition(ClientAccountChecker::class));

        $container  = $this->container(['main'], null, false);
        $definition = $container->getDefinition(ClientAccountChecker::class);
        (new ClientUserCheckerPass())->process($container);
        self::assertSame('fallback', (string) $definition->getArgument('$userChecker'));
    }

    public function testPrefersTheMainFirewall(): void
    {
        $container = $this->container(['api', 'main']);
        (new ClientUserCheckerPass())->process($container);

        $definition = $container->getDefinition(ClientAccountChecker::class);
        self::assertSame('security.user_checker.main', (string) $definition->getArgument('$userChecker'));
        self::assertFalse($definition->hasTag(ClientUserCheckerPass::TAG));
    }

    public function testFallsBackToTheFirstFirewallWithSecurity(): void
    {
        $container = $this->container(['dev', 'admin'], null, true, ['admin']);
        (new ClientUserCheckerPass())->process($container);

        self::assertSame('security.user_checker.admin', (string) $container->getDefinition(ClientAccountChecker::class)->getArgument('$userChecker'));
    }

    public function testKeepsTheFallbackWithoutAnyFirewallChecker(): void
    {
        $container = $this->container([], null);
        $container->setParameter('security.firewalls', 'not-a-list');
        (new ClientUserCheckerPass())->process($container);

        self::assertSame('fallback', (string) $container->getDefinition(ClientAccountChecker::class)->getArgument('$userChecker'));
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
     * @param list<int|string> $firewalls
     * @param list<string>|null $withChecker firewalls with a user checker service (default: all)
     */
    private function container(array $firewalls, ?string $firewall = null, bool $tagged = true, ?array $withChecker = null): ContainerBuilder
    {
        $container = new ContainerBuilder();
        $container->setParameter('security.firewalls', [...$firewalls, 42]);
        foreach ($withChecker ?? $firewalls as $name) {
            $container->setDefinition('security.user_checker.' . $name, new Definition(stdClass::class));
        }
        $definition = (new Definition(ClientAccountChecker::class))->setArgument('$userChecker', new Reference('fallback'));
        if ($tagged) {
            $definition->addTag(ClientUserCheckerPass::TAG, ['firewall' => $firewall]);
        }
        $container->setDefinition(ClientAccountChecker::class, $definition);

        return $container;
    }
}
