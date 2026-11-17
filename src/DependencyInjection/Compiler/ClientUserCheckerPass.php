<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\DependencyInjection\Compiler;

use LogicException;
use Nowo\TimeTrackBundle\Client\ClientAccountChecker;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;

use function array_unshift;
use function implode;
use function in_array;
use function is_array;
use function is_string;
use function sprintf;

/**
 * Since 1.29 (`client_api.user_checker` without `service`): points {@see ClientAccountChecker} at the firewall user
 * checker. SecurityBundle registers `security.user_checker.<firewall>` for every firewall with security enabled (the
 * firewall `user_checker`, or its chain extended by bundles such as UserKit); the global UserCheckerInterface alias is
 * the no-op InMemoryUserChecker (or missing), so it is only the fallback. Order: the configured `firewall` (must
 * exist), else `main`, else the first firewall with security. Same resolution as VaultBundle's browser extension.
 */
final class ClientUserCheckerPass implements CompilerPassInterface
{
    public const TAG = 'nowo_time_track.client_api.auto_user_checker';

    private const DEFAULT_FIREWALL = 'main';

    /**
     * Resolves the checker of the tagged account checker definition.
     */
    public function process(ContainerBuilder $container): void
    {
        if (!$container->hasDefinition(ClientAccountChecker::class)) {
            return;
        }

        $definition = $container->getDefinition(ClientAccountChecker::class);
        $tags       = $definition->getTag(self::TAG);
        if ($tags === []) {
            return;
        }
        $definition->clearTag(self::TAG);

        $firewall  = $tags[0]['firewall'] ?? null;
        $firewalls = self::firewallNames($container);

        if (is_string($firewall) && $firewall !== '') {
            $checkerId = 'security.user_checker.' . $firewall;
            if (!$container->has($checkerId)) {
                throw new LogicException(sprintf('nowo_time_track.client_api.user_checker.firewall "%s" is not a firewall with security enabled (known firewalls: %s).', $firewall, $firewalls === [] ? 'none' : implode(', ', $firewalls)));
            }
            $definition->setArgument('$userChecker', new Reference($checkerId));

            return;
        }

        if (in_array(self::DEFAULT_FIREWALL, $firewalls, true)) {
            array_unshift($firewalls, self::DEFAULT_FIREWALL);
        }

        foreach ($firewalls as $name) {
            $checkerId = 'security.user_checker.' . $name;
            if ($container->has($checkerId)) {
                $definition->setArgument('$userChecker', new Reference($checkerId));

                return;
            }
        }
    }

    /**
     * @return list<string>
     */
    private static function firewallNames(ContainerBuilder $container): array
    {
        $names = $container->hasParameter('security.firewalls') ? $container->getParameter('security.firewalls') : [];
        if (!is_array($names)) {
            return [];
        }

        $result = [];
        foreach ($names as $name) {
            if (is_string($name)) {
                $result[] = $name;
            }
        }

        return $result;
    }
}
