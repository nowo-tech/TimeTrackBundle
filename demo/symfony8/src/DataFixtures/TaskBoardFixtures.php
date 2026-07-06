<?php

declare(strict_types=1);

namespace App\DataFixtures;

use App\Entity\User;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Common\DataFixtures\DependentFixtureInterface;
use Doctrine\Persistence\ObjectManager;
use Nowo\TaskBoardBundle\Entity\BoardColumn;
use Nowo\TaskBoardBundle\Entity\Task;
use Nowo\TaskBoardBundle\Entity\TaskBoard;
use Nowo\TaskBoardBundle\Entity\TaskMember;
use Nowo\TaskBoardBundle\Entity\Team;
use Nowo\TaskBoardBundle\Entity\TeamMember;
use Nowo\TaskBoardBundle\Enum\TaskMemberRole;
use Nowo\TaskBoardBundle\Enum\TaskPriority;
use Nowo\TaskBoardBundle\Enum\TeamRole;

final class TaskBoardFixtures extends Fixture implements DependentFixtureInterface
{
    public function load(ObjectManager $manager): void
    {
        $demo = $manager->getRepository(User::class)->findOneBy(['email' => 'demo@example.com']);
        if (!$demo instanceof User) {
            return;
        }

        $team = new Team('Engineering');
        $manager->persist($team);
        $manager->persist(new TeamMember($team, $demo, TeamRole::Manager));

        $board = new TaskBoard('Sprint 1', 'sprint-1', $demo, 'Integrated demo board for time tracking.');
        $board->setTeam($team);
        $todo     = new BoardColumn($board, 'To do', 0, '#94a3b8');
        $progress = new BoardColumn($board, 'In progress', 1, '#3b82f6');
        $done     = new BoardColumn($board, 'Done', 2, '#22c55e');
        $board->addColumn($todo)->addColumn($progress)->addColumn($done);
        $manager->persist($board);
        foreach ([$todo, $progress, $done] as $column) {
            $manager->persist($column);
        }

        $tasks = [
            ['Implement login API', $progress, TaskPriority::High],
            ['Write unit tests', $todo, TaskPriority::Normal],
            ['Review pull requests', $todo, TaskPriority::Low],
        ];

        foreach ($tasks as [$title, $column, $priority]) {
            $task = new Task($board, $title, $demo, column: $column, priority: $priority);
            $task->addMember(new TaskMember($task, $demo, TaskMemberRole::Assignee));
            $manager->persist($task);
        }

        $manager->flush();
    }

    public function getDependencies(): array
    {
        return [AppFixtures::class];
    }
}
