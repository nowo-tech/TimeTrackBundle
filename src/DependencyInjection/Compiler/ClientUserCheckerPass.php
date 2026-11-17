<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\DependencyInjection\Compiler;

use LogicException;
use Nowo\TimeTrackBundle\Client\ClientAccountChecker;
use Symfony\Component\DependencyInjection\Argument\TaggedIteratorArgument;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\Security\Core\User\ChainUserChecker;
use Symfony\Component\Security\Core\User\InMemoryUserChecker;

use function array_unshift;
use function implode;
use function in_array;
use function is_a;
use function is_array;
use function is_string;
use function sprintf;

/**
 * Since 1.29 (`client_api.user_checker`): resolves and vets the checker of {@see ClientAccountChecker}.
 *
 * Without `service`, it points the account checker at the firewall user checker. SecurityBundle registers
 * `security.user_checker.<firewall>` for every firewall with security enabled (the firewall `user_checker`, or its
 * chain extended by bundles such as UserKit); the global UserCheckerInterface alias is the no-op InMemoryUserChecker (or
 * missing), so it is only the fallback. Order: the configured `firewall` (must exist), else `main`, else the first
 * firewall with security. Same resolution as VaultBundle's browser extension.
 *
 * Audit SO-M1: the resolved checker refuses nobody when there is none, when it is Symfony's default
 * `security.user_checker` (InMemoryUserChecker, which ignores every user that is not an InMemoryUser), or when it is an
 * empty ChainUserChecker. Then the pass writes it to the container compiler log and leaves
 * `%nowo_time_track.client_api.user_checker.effective%` false; with `required: true` the build fails instead.
 */
final class ClientUserCheckerPass implements CompilerPassInterface
{
    public const TAG = 'nowo_time_track.client_api.auto_user_checker';

    /** Whether the configured account check can refuse anyone (false when off or when it is a no-op). */
    public const EFFECTIVE_PARAMETER = 'nowo_time_track.client_api.user_checker.effective';

    private const DEFAULT_FIREWALL = 'main';

    /** Symfony's default firewall checker (InMemoryUserChecker). */
    private const SYMFONY_DEFAULT_CHECKER = 'security.user_checker';

    /**
     * Resolves the checker of the tagged account checker definition and records whether it is effective.
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
        $tag = $tags[0];

        if (($tag['auto'] ?? true) !== false) {
            $resolved = $this->resolve($container, $tag['firewall'] ?? null);
            if ($resolved instanceof Reference) {
                $definition->setArgument('$userChecker', $resolved);
            }
        }

        $checker = $definition->getArgument('$userChecker');
        $noOp    = $this->noOpReason($container, $checker instanceof Reference ? $checker : null);
        if ($noOp === null) {
            $container->setParameter(self::EFFECTIVE_PARAMETER, true);

            return;
        }

        $message = sprintf('nowo_time_track.client_api.user_checker is on but refuses nobody: %s. Disabled, locked or expired accounts keep using their client API tokens; configure a user_checker on your firewall or set client_api.user_checker.service.', $noOp);
        if (($tag['required'] ?? false) === true) {
            throw new LogicException($message . ' (client_api.user_checker.required is true)');
        }
        $container->log($this, $message);
        $container->setParameter(self::EFFECTIVE_PARAMETER, false);
    }

    /**
     * The firewall checker to use, or null to keep the fallback.
     */
    private function resolve(ContainerBuilder $container, mixed $firewall): ?Reference
    {
        $firewalls = self::firewallNames($container);

        if (is_string($firewall) && $firewall !== '') {
            $checkerId = 'security.user_checker.' . $firewall;
            if (!$container->has($checkerId)) {
                throw new LogicException(sprintf('nowo_time_track.client_api.user_checker.firewall "%s" is not a firewall with security enabled (known firewalls: %s).', $firewall, $firewalls === [] ? 'none' : implode(', ', $firewalls)));
            }

            return new Reference($checkerId);
        }

        if (in_array(self::DEFAULT_FIREWALL, $firewalls, true)) {
            array_unshift($firewalls, self::DEFAULT_FIREWALL);
        }

        foreach ($firewalls as $name) {
            $checkerId = 'security.user_checker.' . $name;
            if ($container->has($checkerId)) {
                return new Reference($checkerId);
            }
        }

        return null;
    }

    /**
     * Why the checker refuses nobody (null when it may refuse someone).
     */
    private function noOpReason(ContainerBuilder $container, ?Reference $checker): ?string
    {
        if (!$checker instanceof Reference) {
            return 'no user checker was found';
        }

        $id = (string) $checker;
        for ($depth = 0; $depth < 20 && $id !== self::SYMFONY_DEFAULT_CHECKER && $container->hasAlias($id); ++$depth) {
            $id = (string) $container->getAlias($id);
        }
        if ($id === self::SYMFONY_DEFAULT_CHECKER) {
            return 'it resolves to Symfony\'s default security.user_checker (InMemoryUserChecker), which ignores every user that is not an InMemoryUser';
        }
        if (!$container->hasDefinition($id)) {
            return sprintf('the service "%s" does not exist', $id);
        }

        $definition = $container->getDefinition($id);
        $class      = $container->getParameterBag()->resolveValue($definition->getClass() ?? $id);
        if (is_string($class) && is_a($class, InMemoryUserChecker::class, true)) {
            return sprintf('"%s" is an InMemoryUserChecker, which ignores every user that is not an InMemoryUser', $id);
        }
        if (is_string($class) && is_a($class, ChainUserChecker::class, true)) {
            $checkers = $definition->getArguments()[0] ?? null;
            if ($checkers instanceof TaggedIteratorArgument && $container->findTaggedServiceIds((string) $checkers->getTag()) === []) {
                return sprintf('"%s" is a ChainUserChecker with no checker tagged "%s"', $id, (string) $checkers->getTag());
            }
        }

        return null;
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
