<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\DependencyInjection\Compiler;

use Doctrine\DBAL\Driver\Connection;
use Nowo\TimeTrackBundle\DependencyInjection\TimeTrackExtension;
use Nowo\TimeTrackBundle\Doctrine\PrivateEntityManagerMiddleware;
use Nowo\TimeTrackBundle\Doctrine\TimeTrackEntityManager;
use ReflectionMethod;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;

use function interface_exists;
use function is_string;
use function preg_match;
use function sprintf;

/**
 * Private entity manager wiring (1.23.0, REQ-ARCH-001 rule 6, `database.private_entity_manager: true`): attaches
 * {@see PrivateEntityManagerMiddleware} only to the connection of the source manager (`database.entity_manager`, the
 * first argument of its definition; the default connection when it cannot be read). The host's other connections are
 * never wrapped, and the middleware and the private manager ({@see TimeTrackEntityManager}) always see the same
 * connection.
 *
 * Runs before DoctrineBundle's MiddlewaresPass. Nothing happens without the option (the services are not registered).
 *
 * @internal
 */
final class PrivateEntityManagerPass implements CompilerPassInterface
{
    /**
     * The rollback middleware is written against the doctrine/dbal 4 driver API; with DBAL 3 the private manager runs
     * without it (no replacement after a rollback, see docs/CONFIGURATION.md).
     */
    public static function supportsRollbackMiddleware(): bool
    {
        if (!interface_exists(Connection::class)) {
            return false; // @codeCoverageIgnore
        }

        return (string) (new ReflectionMethod(Connection::class, 'rollBack'))->getReturnType() === 'void';
    }

    /**
     * Attaches the rollback middleware to the source manager's connection.
     */
    public function process(ContainerBuilder $container): void
    {
        if (!$container->hasDefinition(PrivateEntityManagerMiddleware::class) || !$container->hasParameter(TimeTrackExtension::PRIVATE_ENTITY_MANAGER_SOURCE_PARAMETER)) {
            return;
        }

        $source     = $container->getParameter(TimeTrackExtension::PRIVATE_ENTITY_MANAGER_SOURCE_PARAMETER);
        $manager    = is_string($source) ? $source : 'default';
        $id         = sprintf('doctrine.orm.%s_entity_manager', $manager);
        $argument   = $container->hasDefinition($id) ? ($container->getDefinition($id)->getArguments()[0] ?? null) : null;
        $connection = $argument instanceof Reference && preg_match('/^doctrine\.dbal\.(.+)_connection$/D', (string) $argument, $match) === 1 ? $match[1] : null;
        if ($connection === null) {
            $default    = $container->hasParameter('doctrine.default_connection') ? $container->getParameter('doctrine.default_connection') : null;
            $connection = is_string($default) && $default !== '' ? $default : 'default';
        }

        $container->getDefinition(PrivateEntityManagerMiddleware::class)
            ->clearTag('doctrine.middleware')
            ->addTag('doctrine.middleware', ['connection' => $connection]);
    }
}
