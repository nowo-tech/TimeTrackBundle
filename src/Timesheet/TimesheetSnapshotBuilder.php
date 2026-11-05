<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Timesheet;

use Nowo\TimeTrackBundle\Entity\TimeEntry;
use Nowo\TimeTrackBundle\Repository\TimeEntryRepositoryInterface;
use Nowo\TimeTrackBundle\Support\CanonicalJson;
use Nowo\TimeTrackBundle\Support\StorageTime;
use Nowo\TimeTrackBundle\Support\UserIdResolver;
use Nowo\TimeTrackBundle\WorkingTime\BalanceReport;
use Nowo\TimeTrackBundle\WorkingTime\WorkingTimeBalanceService;
use Symfony\Component\Security\Core\User\UserInterface;

use function count;
use function strcmp;
use function usort;

/**
 * Since 1.13: builds the snapshot of a timesheet period (contract §5.8). An entry belongs to the period of its local
 * start date in the timesheet's zone; imported entries are listed and counted apart (`importedSeconds`), excluded
 * from `taskSeconds`. `entries_hash = sha256(canonical_json(sorted [id, taskId, startedAt UTC, endedAt UTC,
 * durationSeconds, source]))`: notes and descriptions are not covered. `byTask` holds ids only.
 */
final readonly class TimesheetSnapshotBuilder
{
    /**
     * Receives the entry store and (when working time is on) the balance service.
     */
    public function __construct(
        private TimeEntryRepositoryInterface $entries,
        private ?WorkingTimeBalanceService $balance = null,
    ) {
    }

    /**
     * The snapshot of the owner's period in the scope.
     */
    public function build(?UserInterface $owner, string $ownerId, string $scopeKey, Period $period, string $timezone): TimesheetSnapshot
    {
        $entries    = [];
        $unassigned = [];
        $from       = $period->startsAt($timezone);
        $to         = $period->endsBefore($timezone)->modify('-1 second');
        foreach ($this->entries->findByUserAndPeriod($ownerId, $from, $to) as $entry) {
            if (!$period->contains(StorageTime::localDate($entry->getStartedAt(), $timezone))) {
                continue;
            }
            if ($entry->getScopeKey() === $scopeKey) {
                $entries[] = $entry;
            } elseif ($entry->getScopeKey() === null) {
                $unassigned[] = $entry;
            }
        }
        usort($entries, static fn (TimeEntry $a, TimeEntry $b): int => $a->getStartedAt() <=> $b->getStartedAt() ?: strcmp($a->getId(), $b->getId()));

        $taskSeconds = 0;
        $imported    = 0;
        $byTask      = [];
        foreach ($entries as $entry) {
            if (!$entry->getSource()->isLive()) {
                $imported += $entry->getDurationSeconds();
                continue;
            }
            $taskSeconds += $entry->getDurationSeconds();
            $byTask[$entry->getTaskId()] = ($byTask[$entry->getTaskId()] ?? 0) + $entry->getDurationSeconds();
        }
        $tasks = [];
        foreach ($byTask as $taskId => $seconds) {
            $tasks[] = ['taskId' => (string) $taskId, 'seconds' => $seconds];
        }

        $report = $owner instanceof UserInterface && $this->balance?->isEnabled() === true && UserIdResolver::getId($owner) === $ownerId
            ? $this->balance->report($owner, $scopeKey, $period->start, $period->end)
            : null;

        $totals = [
            'periodKey'         => $period->key,
            'timezone'          => $timezone,
            'taskSeconds'       => $taskSeconds,
            'importedSeconds'   => $imported,
            'entries'           => count($entries),
            'unassignedEntries' => count($unassigned),
            'expectedSeconds'   => $report?->totals['expectedSeconds'],
            'workedSeconds'     => $report?->totals['workedSeconds'],
            'diffSeconds'       => $report?->totals['diffSeconds'],
            'overtimeSeconds'   => $report?->totals['overtimeSeconds'],
            'balanceSeconds'    => $report?->balanceSeconds,
            'source'            => $report instanceof BalanceReport ? $report->source : 'task_time',
            'legalRecord'       => $report instanceof BalanceReport && $report->legalRecord,
            'policy'            => $report?->policy,
            'flags'             => $report?->flagCounts() ?? [],
            'byTask'            => $tasks,
        ];

        return new TimesheetSnapshot($entries, $unassigned, $totals, self::hash($entries), $report);
    }

    /**
     * Canonical hash of an entry list (sorted by id).
     *
     * @param list<TimeEntry> $entries
     */
    public static function hash(array $entries): string
    {
        $rows = [];
        foreach ($entries as $entry) {
            $rows[] = [$entry->getId(), $entry->getTaskId(), StorageTime::utc($entry->getStartedAt()), StorageTime::utc($entry->getEndedAt()), $entry->getDurationSeconds(), $entry->getSource()->value];
        }
        usort($rows, static fn (array $a, array $b): int => strcmp($a[0], $b[0]));

        return CanonicalJson::hash($rows);
    }
}
