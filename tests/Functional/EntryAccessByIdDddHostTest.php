<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Tests\Functional;

use Nowo\TimeTrackBundle\Tests\Fixtures\DddHost\SecurityUser;
use Nowo\TimeTrackBundle\Tests\Fixtures\FunctionalEntity\AppUser;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * 1.37.0 (DDD-host checklist B1/B8): the id-based entry access port on a DDD host: a security user that is no Doctrine
 * entity, `user_mapping: id` (string ids compared byte for byte), the shipped XML mapping and the private entity manager.
 */
final class EntryAccessByIdDddHostTest extends EntryAccessByIdTestCase
{
    protected static function timeTrackConfig(): array
    {
        return [
            'user_class'            => SecurityUser::class,
            'accepted_user_classes' => [AppUser::class],
            'user_mapping'          => 'id',
            'database'              => ['mapping_type' => 'xml'],
        ];
    }

    public function testALookAlikeUserIdNeverReachesAnotherUsersEntry(): void
    {
        $entry = $this->entry(new SecurityUser('abc', 'lower'), 'task-1');

        self::assertTrue($this->access()->canEditEntry('abc', $entry));
        foreach (['ABC', 'abc ', 'àbc', "abc\u{200B}"] as $lookAlike) {
            self::assertFalse($this->access()->canViewEntry($lookAlike, $entry), $lookAlike);
            self::assertFalse($this->access()->canEditEntry($lookAlike, $entry), $lookAlike);
        }
    }

    /**
     * @param non-empty-string $name
     */
    protected function person(string $name): UserInterface
    {
        return new SecurityUser('ext-' . $name, $name);
    }

    protected function idOf(UserInterface $user): string
    {
        self::assertInstanceOf(SecurityUser::class, $user);
        $id = $user->getId();
        self::assertIsString($id);

        return $id;
    }
}
