<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Tests\Functional;

use Nowo\TimeTrackBundle\Client\ClientTokenRevoker;
use Nowo\TimeTrackBundle\Enum\ClientType;
use Nowo\TimeTrackBundle\Query\TimeEntryCriteria;
use Nowo\TimeTrackBundle\Retention\FormerPersonTimeEntries;
use Nowo\TimeTrackBundle\Tests\Fixtures\FunctionalEntity\StringIdUser;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * 1.35.0 (audit YI L1): the read and personal-data ports on a standard host whose user entity has string ids, in the
 * relation mapping. The bundle's `user_id` columns then keep the database's default collation: case-insensitive on
 * MySQL / MariaDB, so `'ABC'` matches `'abc'` in SQL. Every read is checked again and every write goes by id: a
 * look-alike id never exports, deletes or anonymises another user's rows.
 */
final class HostReadPortsStringIdRelationTest extends HostReadPortsTestCase
{
    protected static function timeTrackConfig(): array
    {
        return ['user_class' => StringIdUser::class];
    }

    public function testALookAlikeIdNeverReachesAnotherUsersRows(): void
    {
        $lower = $this->person('abc');
        $this->entry($lower, 'task-1', '2026-11-02 09:00', 60, metadata: ['note' => 'Mine']);
        $this->seedPersonalData($lower);
        $this->timers()->start($lower, 'task-1', ClientType::Web);
        $this->timers()->stop($lower);
        $this->bundleManager()->clear();

        self::assertSame([], $this->query()->list(TimeEntryCriteria::ofOwner('ext-ABC')));
        self::assertNull($this->timerQuery()->runningOf('ext-ABC'));
        self::assertSame([], $this->clientAccess()->sessionsOf('ext-ABC'));
        $export = $this->personalData()->export('ext-ABC');
        self::assertSame([[], [], [], [], []], [$export['entries'], $export['clientSessions'], $export['monitoringUploads'], $export['balanceLedgerLines'], $export['timesheetEvents']]);

        $erased = $this->personalData()->erase('ext-ABC');
        self::assertSame([0, 0, 0, 0, 0, 0], [$erased->clientSessions, $erased->deviceSignIns, $erased->monitoringUploads, $erased->monitoringAcknowledgements, $erased->entriesAnonymised, $erased->calendarConnectionsKept]);
        $revoker = self::getContainer()->get(ClientTokenRevoker::class);
        self::assertInstanceOf(ClientTokenRevoker::class, $revoker);
        self::assertSame(0, $revoker->revokeAllForUser('ext-ABC'));
        $former = self::getContainer()->get(FormerPersonTimeEntries::class);
        self::assertInstanceOf(FormerPersonTimeEntries::class, $former);
        self::assertSame(0, $former->anonymiseUser(new StringIdUser('ext-ABC')), 'the store method of the former-person flow too');

        self::assertSame(2, $this->query()->count(TimeEntryCriteria::ofOwner('ext-abc')), 'abc keeps every entry');
        self::assertCount(1, $this->clientAccess()->sessionsOf('ext-abc'));
        $own = $this->personalData()->export('ext-abc');
        foreach (['monitoringUploads', 'monitoringAcknowledgements', 'deviceSignIns', 'balanceLedgerLines', 'timesheetEvents'] as $section) {
            self::assertIsArray($own[$section]);
            self::assertCount(1, $own[$section], $section);
        }
        self::assertSame(1, $revoker->revokeAllForUser('ext-abc'));
    }

    /**
     * @param non-empty-string $name
     */
    protected function person(string $name): UserInterface
    {
        $user = new StringIdUser('ext-' . $name);
        $this->entityManager()->persist($user);
        $this->entityManager()->flush();

        return $user;
    }

    protected function idOf(UserInterface $user): string
    {
        self::assertInstanceOf(StringIdUser::class, $user);

        return $user->getId();
    }
}
