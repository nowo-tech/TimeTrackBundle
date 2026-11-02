<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Tests\Support;

use DateTimeImmutable;
use Nowo\TimeTrackBundle\Dto\TaskReference;
use Nowo\TimeTrackBundle\Dto\TimeEntryPage;
use Nowo\TimeTrackBundle\Entity\ActiveTimer;
use Nowo\TimeTrackBundle\Entity\TimeEntry;
use Nowo\TimeTrackBundle\Enum\ClientType;
use Nowo\TimeTrackBundle\Enum\TimeEntrySource;
use Nowo\TimeTrackBundle\Form\TimerActionType;
use Nowo\TimeTrackBundle\Tests\Stub\TestUser;
use Nowo\TimeTrackBundle\Ui\TimeTrackPages;
use Symfony\Component\Form\Extension\Csrf\CsrfExtension;
use Symfony\Component\Form\Forms;
use Symfony\Component\Form\FormView;
use Symfony\Component\Security\Csrf\CsrfTokenManager;

use function sprintf;

/**
 * Render contexts of the manage pages for the UiKit page contract test and the layout bridge test: the same
 * variables the controller passes (`page` view model + the 1.x variables).
 */
final class PageFixtures
{
    /**
     * Timer page: three tasks; optionally a running timer; optionally no task at all.
     *
     * @return array<string, mixed>
     */
    public static function timer(bool $running = false, bool $noTasks = false, ?TimeTrackPages $pages = null): array
    {
        $pages ??= new TimeTrackPages();
        $tasks = $noTasks ? [] : [
            new TaskReference('task-1', 'Implement login API', 'board-1', 'Sprint 1'),
            new TaskReference('task-2', 'Write unit tests', 'board-1', 'Sprint 1'),
            new TaskReference('task-3', 'Review pull requests', 'board-2'),
        ];
        $active = $running ? new ActiveTimer(new TestUser('user-1', 'person@example.com'), 'task-1', 'Implement login API', 'board-1', ClientType::Web, null, new DateTimeImmutable('2026-05-04 09:00:00')) : null;

        $startForms = [];
        foreach ($tasks as $task) {
            $startForms[$task->id] = self::form(['action' => 'start', 'task_id' => $task->id], 'start_' . $task->id);
        }
        $stopForm = self::form(['action' => 'stop'], 'stop');

        return [
            'page'         => $pages->timerPage($tasks, $active, $stopForm, $startForms),
            'active_timer' => $active,
            'tasks'        => $tasks,
            'stop_form'    => $stopForm,
            'start_forms'  => $startForms,
        ] + $pages->layoutContext();
    }

    /**
     * Reports page: page 2 of 3 (two entries per page), or an empty period.
     *
     * @return array<string, mixed>
     */
    public static function reports(bool $empty = false, ?TimeTrackPages $pages = null): array
    {
        $pages ??= new TimeTrackPages();
        $user    = new TestUser('user-1', 'person@example.com');
        $entries = [];
        if (!$empty) {
            foreach ([['Implement login API', TimeEntrySource::Desktop, 5400], ['Write unit tests', TimeEntrySource::Extension, 1800]] as $i => [$title, $source, $seconds]) {
                $start     = new DateTimeImmutable(sprintf('2026-05-0%d 09:00:00', $i + 2));
                $entries[] = new TimeEntry($user, 'task-' . $i, $title, null, $start, $start->modify(sprintf('+%d seconds', $seconds)), $seconds, $source);
            }
        }
        $entryPage = new TimeEntryPage($entries, $empty ? 1 : 2, 2, $empty ? 0 : 5);
        $from      = new DateTimeImmutable('2026-04-27');
        $to        = new DateTimeImmutable('2026-05-04');

        return [
            'page'       => $pages->reportsPage($entryPage, $from, $to, 'nowo_time_track_reports', ['from' => '2026-04-27']),
            'entries'    => $entryPage->entries,
            'entry_page' => $entryPage,
            'from'       => $from,
            'to'         => $to,
        ] + $pages->layoutContext();
    }

    /**
     * @param array{action?: string, task_id?: string} $data
     */
    private static function form(array $data, string $idSuffix): FormView
    {
        $factory = Forms::createFormFactoryBuilder()
            ->addExtension(new CsrfExtension(new CsrfTokenManager()))
            ->getFormFactory();

        return $factory->createNamed('', TimerActionType::class, $data, ['id_suffix' => $idSuffix])->createView();
    }
}
