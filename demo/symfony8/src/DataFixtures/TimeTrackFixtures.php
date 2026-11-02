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

use function sprintf;

/**
 * Time entries of the demo user over the last days (web, browser extension, desktop app), so the reports page has
 * realistic data. Idempotent: skipped when the user already has entries (`make up` appends fixtures).
 */
final class TimeTrackFixtures extends Fixture implements DependentFixtureInterface
{
    /** [task title, days ago, start hour, minutes, source] */
    private const ENTRIES = [
        ['Implement login API', 1, 9, 95, TimeEntrySource::Web],
        ['Review pull requests', 1, 14, 40, TimeEntrySource::Extension],
        ['Write unit tests', 2, 10, 120, TimeEntrySource::Desktop],
        ['Implement login API', 3, 9, 75, TimeEntrySource::Web],
        ['Review pull requests', 4, 16, 25, TimeEntrySource::Extension],
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
        foreach (self::ENTRIES as [$title, $daysAgo, $hour, $minutes, $source]) {
            $task = $tasks[$title] ?? null;
            if (!$task instanceof Task) {
                continue;
            }
            $start = $today->modify(sprintf('-%d days', $daysAgo))->setTime($hour, 0);
            $manager->persist(new TimeEntry(
                $demo,
                $task->getId(),
                $title,
                $task->getBoard()->getId(),
                $start,
                $start->modify(sprintf('+%d minutes', $minutes)),
                $minutes * 60,
                $source,
            ));
        }

        $manager->flush();
    }

    public function getDependencies(): array
    {
        return [TaskBoardFixtures::class];
    }
}
