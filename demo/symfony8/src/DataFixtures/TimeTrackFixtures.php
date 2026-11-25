<?php

declare(strict_types=1);

namespace App\DataFixtures;

use App\Entity\User;
use DateTimeImmutable;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Common\DataFixtures\DependentFixtureInterface;
use Doctrine\Persistence\ObjectManager;
use Nowo\TaskBoardBundle\Entity\Task;
use Nowo\TimeTrackBundle\Entity\TimeEntry;
use Nowo\TimeTrackBundle\Enum\TimeEntrySource;
use Nowo\TimeTrackBundle\Subject\TimeSubjectRef;

use function sprintf;

/**
 * Time entries of the demo user over the last days (web, browser extension, desktop app), so the reports page has
 * realistic data (since TimeTrack 2.1 also customers as subjects and activity types). Idempotent: skipped when the user already has entries (`make up` appends fixtures).
 */
final class TimeTrackFixtures extends Fixture implements DependentFixtureInterface
{
    /** [task title (null = no task), days ago, start hour, minutes, source, customer (2.1 subject), activity type] */
    private const ENTRIES = [
        ['Implement login API', 1, 9, 95, TimeEntrySource::Web, 'acme', 'development'],
        ['Review pull requests', 1, 14, 40, TimeEntrySource::Extension, null, null],
        [null, 1, 16, 30, TimeEntrySource::Manual, 'globex', 'support_call'],
        ['Write unit tests', 2, 10, 120, TimeEntrySource::Desktop, null, 'development'],
        ['Implement login API', 3, 9, 75, TimeEntrySource::Web, 'acme', 'development'],
        ['Review pull requests', 4, 16, 25, TimeEntrySource::Extension, null, 'internal_meeting'],
    ];

    public function load(ObjectManager $manager): void
    {
        $demo = $manager->getRepository(User::class)->findOneBy(['email' => 'demo@example.com']);
        if (!$demo instanceof User || $manager->getRepository(TimeEntry::class)->count(['user' => $demo]) > 0) {
            return;
        }

        $tasks = [];
        foreach ($manager->getRepository(Task::class)->findAll() as $task) {
            $tasks[$task->getTitle()] = $task;
        }

        $today = new DateTimeImmutable('today');
        foreach (self::ENTRIES as [$title, $daysAgo, $hour, $minutes, $source, $customer, $activity]) {
            $task = $title === null ? null : ($tasks[$title] ?? null);
            if ($title !== null && !$task instanceof Task) {
                continue;
            }
            $start = $today->modify(sprintf('-%d days', $daysAgo))->setTime($hour, 0);
            $entry = new TimeEntry(
                $demo,
                $task?->getId(),
                $title ?? '',
                $task?->getBoard()->getId(),
                $start,
                $start->modify(sprintf('+%d minutes', $minutes)),
                $minutes * 60,
                $source,
            );
            // TimeTrack 2.1 (Beacon spec 256): customer time on a task or alone, and an activity type. Seed data only:
            // the bundle's write services check canLog and the activity type on real writes.
            $entry->allocate($customer === null ? null : new TimeSubjectRef('customer', $customer), $activity);
            $manager->persist($entry);
        }

        $manager->flush();
    }

    public function getDependencies(): array
    {
        return [TaskBoardFixtures::class];
    }
}
