<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Tests\Functional;

use Nowo\TimeTrackBundle\Bridge\NullTeamContextProvider;
use Nowo\TimeTrackBundle\Bridge\StubTaskProvider;
use Nowo\TimeTrackBundle\Client\ClientCredentialIssuerInterface;
use Nowo\TimeTrackBundle\Client\ClientUserProfileProviderInterface;
use Nowo\TimeTrackBundle\Client\NullClientUserProfileProvider;
use Nowo\TimeTrackBundle\Client\OpaqueClientCredentialIssuer;
use Nowo\TimeTrackBundle\Doctrine\DoctrineUserReferenceResolver;
use Nowo\TimeTrackBundle\Integration\TaskProviderInterface;
use Nowo\TimeTrackBundle\Integration\TeamContextProviderInterface;
use Nowo\TimeTrackBundle\Integration\UserReferenceResolverInterface;
use Nowo\TimeTrackBundle\Security\TaskAccessCheckerInterface;
use Nowo\TimeTrackBundle\Security\TaskProviderTaskAccessChecker;

/**
 * 1.23.0 (REQ-ARCH-001 rule 3, defaults unchanged): with the default configuration every port is the built-in service
 * itself, asked through the deprecated interface exactly as in 1.22 (no gate, no factory in between).
 */
final class DefaultPortsWiringTest extends FunctionalTestCase
{
    public function testTheBuiltInPortsAreServedAsTheyAre(): void
    {
        $container = self::getContainer();

        foreach ([
            TaskProviderInterface::class              => StubTaskProvider::class,
            TeamContextProviderInterface::class       => NullTeamContextProvider::class,
            UserReferenceResolverInterface::class     => DoctrineUserReferenceResolver::class,
            TaskAccessCheckerInterface::class         => TaskProviderTaskAccessChecker::class,
            ClientUserProfileProviderInterface::class => NullClientUserProfileProvider::class,
            ClientCredentialIssuerInterface::class    => OpaqueClientCredentialIssuer::class,
        ] as $port => $builtIn) {
            self::assertSame($builtIn, $container->get($port)::class, $port);
        }
    }
}
