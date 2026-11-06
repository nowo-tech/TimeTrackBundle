<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Tests\Functional;

use DateTimeImmutable;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Nowo\TimeTrackBundle\Entity\TimeEntry;
use Nowo\TimeTrackBundle\Enum\TimeEntrySource;
use Nowo\TimeTrackBundle\Import\ImportedTimeEntryData;

/**
 * Since 1.15 (HA-M5): `retention.anonymise_on_user_delete`: the hours stay and the person goes.
 */
final class FormerPeopleAnonymiseOnDeleteTest extends FunctionalTestCase
{
    protected static function timeTrackConfig(): array
    {
        return ['retention' => ['anonymise_on_user_delete' => true]];
    }

    public function testDeletingAUserKeepsTheHoursAndNotThePerson(): void
    {
        $em   = $this->entityManager();
        $conn = $em->getConnection();
        if ($conn->getDatabasePlatform() instanceof SQLitePlatform) {
            $conn->executeStatement('PRAGMA foreign_keys = ON');
        }
        $stored = new TimeEntry($this->user, 'task-1', 'Onboarding of Ana', 'board-1', new DateTimeImmutable('2026-01-01 10:00:00'), new DateTimeImmutable('2026-01-01 11:00:00'), 3600, TimeEntrySource::Web, ['note' => 'Secret']);
        $em->persist($stored);
        $em->flush();
        $storedId = $stored->getId();
        $em->clear();
        $user = $em->find($this->user::class, $this->user->getId());
        self::assertNotNull($user);
        $new = new TimeEntry($user, 'task-2', 'Inserted in the same flush', null, new DateTimeImmutable('2026-01-02 10:00:00'), new DateTimeImmutable('2026-01-02 11:00:00'), 1800, TimeEntrySource::Web, ['note' => 'Secret']);
        $em->persist($new);
        $newId    = $new->getId();
        $start    = new DateTimeImmutable('2026-01-03 10:00:00');
        $imported = TimeEntry::imported($user, new ImportedTimeEntryData('clickup:dir-1', 'e-1', 'task-3', 'Imported', null, $start, $start->modify('+1 hour'), 900));
        $em->persist($imported);
        $importedId = $imported->getId();

        $em->remove($user);
        $em->flush();
        $em->clear();

        foreach ([$storedId => 3600, $newId => 1800, $importedId => 900] as $id => $seconds) {
            $entry = $em->find(TimeEntry::class, $id);
            self::assertInstanceOf(TimeEntry::class, $entry);
            self::assertNull($entry->getUser());
            self::assertTrue($entry->isAnonymised());
            self::assertNull($entry->getPersonRef());
            self::assertNull($entry->getMetadata());
            self::assertSame('', $entry->getTaskTitleSnapshot());
            self::assertSame($seconds, $entry->getDurationSeconds());
        }
        self::assertSame(TimeEntry::anonymisedExternalId('clickup:dir-1', 'e-1'), $em->find(TimeEntry::class, $importedId)?->getExternalId());
    }
}
