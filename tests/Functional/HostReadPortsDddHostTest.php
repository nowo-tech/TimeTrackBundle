<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Tests\Functional;

use Nowo\TimeTrackBundle\Enum\ClientType;
use Nowo\TimeTrackBundle\Query\TimeEntryCriteria;
use Nowo\TimeTrackBundle\Tests\Fixtures\DddHost\SecurityUser;
use Nowo\TimeTrackBundle\Tests\Fixtures\FunctionalEntity\AppUser;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * 1.35.0 (DDD-host checklist B7, B10): the read and personal-data ports on a DDD host: a security user that is no
 * Doctrine entity, `user_mapping: id` (bundle-owned host user rows, string ids), the shipped XML mapping and the private
 * entity manager. Ids compare byte for byte: a look-alike never reads, exports or erases another user's rows.
 */
final class HostReadPortsDddHostTest extends HostReadPortsTestCase
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

    public function testALookAlikeIdNeverReachesAnotherUsersRows(): void
    {
        $lower = new SecurityUser('abc', 'lower');
        $this->entry($lower, 'task-1', '2026-11-02 09:00', 60, metadata: ['note' => 'Mine']);
        $this->seedPersonalData($lower);
        $this->timers()->start($lower, 'task-1', ClientType::Web);
        $this->timers()->stop($lower);

        self::assertSame([], $this->query()->list(TimeEntryCriteria::ofOwner('ABC')));
        self::assertSame(0, $this->query()->count(TimeEntryCriteria::ofOwner('ABC')));
        self::assertNull($this->timerQuery()->runningOf('ABC'));
        self::assertSame([], $this->clientAccess()->sessionsOf('ABC'));
        $export = $this->personalData()->export('ABC');
        self::assertSame([[], [], []], [$export['entries'], $export['clientSessions'], $export['monitoringUploads']]);

        $erased = $this->personalData()->erase('ABC');
        self::assertSame([0, 0, 0], [$erased->clientSessions, $erased->entriesAnonymised, $erased->monitoringAcknowledgements]);
        self::assertSame(2, $this->query()->count(TimeEntryCriteria::ofOwner('abc')), 'abc keeps every entry');
        self::assertCount(1, $this->clientAccess()->sessionsOf('abc'));
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
