<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Tests\Functional;

use Nowo\TimeTrackBundle\Tests\Fixtures\FunctionalEntity\AppUser;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * 1.35.0 (DDD-host checklist B7, B10): the read and personal-data ports on a standard host: attribute-mapped bundle
 * entities with Doctrine relations to the host's `AppUser` entity (`user_mapping: relation`), on the application's
 * entity manager (or the private one when the suite runs with it).
 */
final class HostReadPortsStandardTest extends HostReadPortsTestCase
{
    protected function person(string $name): UserInterface
    {
        return $name === 'ana' ? $this->user : $this->createUser($name . '@example.com');
    }

    protected function idOf(UserInterface $user): string
    {
        self::assertInstanceOf(AppUser::class, $user);

        return (string) $user->getId();
    }
}
