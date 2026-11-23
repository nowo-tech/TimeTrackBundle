<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Tests\Functional;

use DateTimeImmutable;
use Nowo\TimeTrackBundle\Entry\ManualTimeEntryDraft;
use Nowo\TimeTrackBundle\Tests\Fixtures\DddHost\SecurityUser;
use Nowo\TimeTrackBundle\Tests\Fixtures\FunctionalEntity\AppUser;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * 1.40.0: the id-based operations on a DDD host: a security user that is no Doctrine entity, `user_mapping: id` (string
 * ids compared byte for byte), the shipped XML mapping and the private entity manager.
 */
final class EntryOperationsDddHostTest extends EntryOperationsTestCase
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

    /**
     * A user the bundle never saw gets its host-user row with the first entry; a look-alike id is another user.
     */
    public function testTheFirstEntryOfANewUserCreatesItsRow(): void
    {
        $start  = (new DateTimeImmutable('today -1 day'))->setTime(8, 0);
        $record = $this->operations()->createManual('ext-new', new ManualTimeEntryDraft('task-1', $start, $start->modify('+10 minutes')));
        $other  = $this->operations()->createManual('EXT-NEW', new ManualTimeEntryDraft('task-1', $start, $start->modify('+10 minutes')));

        self::assertSame(['ext-new', 'EXT-NEW'], [$record->entry->userId, $other->entry->userId], 'no overlap between two users');
        self::assertSame(1, (int) $this->bundleManager()->getConnection()->fetchOne('SELECT COUNT(*) FROM time_track_host_users WHERE id = ?', ['ext-new']));
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

    protected function unknownId(): string
    {
        return "ext-\u{200B}ana";
    }
}
