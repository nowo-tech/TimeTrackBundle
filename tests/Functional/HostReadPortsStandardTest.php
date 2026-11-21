<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Tests\Functional;

use Nowo\TimeTrackBundle\Client\ClientTokenRevoker;
use Nowo\TimeTrackBundle\PersonalData\TimeEntryErasure;
use Nowo\TimeTrackBundle\Query\TimeEntryCriteria;
use Nowo\TimeTrackBundle\Tests\Fixtures\FunctionalEntity\AppUser;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * 1.35.0 (DDD-host checklist B7, B10): the read and personal-data ports on a standard host: attribute-mapped bundle
 * entities with Doctrine relations to the host's `AppUser` entity (`user_mapping: relation`), on the application's
 * entity manager (or the private one when the suite runs with it).
 */
final class HostReadPortsStandardTest extends HostReadPortsTestCase
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
     * Integer user ids: an id the owner column cannot hold owns nothing and never reaches the SQL (PostgreSQL rejects
     * `'nobody'` for an integer column; MySQL would cast it).
     */
    public function testAnIdTheIntegerOwnerColumnCannotHoldOwnsNothing(): void
    {
        $ana = $this->person('ana');
        $this->entry($ana, 'task-1', '2026-11-02 09:00', 60);
        $this->seedPersonalData($ana);

        foreach (['nobody', '0' . $this->idOf($ana), '99999999999'] as $id) {
            self::assertSame([], $this->query()->list(TimeEntryCriteria::ofOwner($id)), $id);
            self::assertSame(0, $this->query()->count(new TimeEntryCriteria(ownerIds: [$id], taskIds: ['task-1'])));
            self::assertSame([], $this->clientAccess()->sessionsOf($id));
            self::assertSame([], $this->clientAccess()->deviceSignInsOf($id));
            $export = $this->personalData()->export($id);
            self::assertSame([[], [], [], []], [$export['entries'], $export['monitoringAcknowledgements'], $export['timesheets'], $export['calendarConnections'] ?? []]);
            $erased = $this->personalData()->erase($id);
            self::assertSame([0, 0, 0], [$erased->clientSessions, $erased->entriesAnonymised, $erased->calendarConnectionsKept]);
            self::assertSame(0, $this->personalData()->erase($id, TimeEntryErasure::FreeText)->entriesCleared);
            $revoker = self::getContainer()->get(ClientTokenRevoker::class);
            self::assertInstanceOf(ClientTokenRevoker::class, $revoker);
            self::assertSame(0, $revoker->revokeAllForUser($id));
        }
        self::assertSame(1, $this->query()->count(TimeEntryCriteria::ofOwner($this->idOf($ana))), 'the owner keeps the entry');
        self::assertSame(1, $this->query()->count(new TimeEntryCriteria(ownerIds: ['nobody', $this->idOf($ana)])), 'the storable ids still match');
        self::assertCount(1, $this->clientAccess()->sessionsOf($this->idOf($ana)));
    }
}
