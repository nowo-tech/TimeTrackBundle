<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Tests\Functional;

use Nowo\TimeTrackBundle\Tests\Fixtures\FunctionalEntity\AppUser;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * 1.37.0 (DDD-host checklist B1/B8): the id-based entry access port on a standard host (`user_mapping: relation`,
 * integer user ids, the application's entity manager or the private one when the suite runs with it).
 */
final class EntryAccessByIdStandardTest extends EntryAccessByIdTestCase
{
    /**
     * @param non-empty-string $name
     */
    protected function person(string $name): UserInterface
    {
        return $name === 'ana' ? $this->user : $this->createUser($name . '@example.com');
    }

    protected function idOf(UserInterface $user): string
    {
        self::assertInstanceOf(AppUser::class, $user);

        return (string) $user->getId();
    }

    /**
     * An integer id is the same user; a look-alike integer (leading zero) is not.
     */
    public function testIntegerIds(): void
    {
        $entry = $this->entry($this->user, 'task-1');
        $id    = $this->user->getId();
        self::assertIsInt($id);

        self::assertTrue($this->access()->canViewEntry($id, $entry));
        self::assertFalse($this->access()->canViewEntry('0' . $id, $entry));
        self::assertFalse($this->access()->canViewEntry($id + 1000, $entry));
    }
}
