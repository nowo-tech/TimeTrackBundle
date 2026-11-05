<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Timesheet;

use Nowo\TimeTrackBundle\Entity\Timesheet;
use Nowo\TimeTrackBundle\Entity\TimesheetEvent;
use Nowo\TimeTrackBundle\Repository\BalanceLedgerRepositoryInterface;
use Nowo\TimeTrackBundle\Repository\TimesheetEventRepositoryInterface;
use Nowo\TimeTrackBundle\Repository\TimesheetRepositoryInterface;
use Nowo\TimeTrackBundle\Repository\TimesheetRetentionRepositoryInterface;

use function array_map;
use function count;

use const DATE_ATOM;

/**
 * Since 1.13 (contract §12.4, audit GB-L6): the personal data the bundle keeps about a person in the working-time
 * tables, for the host's GDPR art. 15 / 20 export: every timesheet they own (with its audit trail) and every ledger line
 * of their balance, across scopes, by `owner_ref` (so records kept after an account erasure are found too). Other
 * people's identities are left out: approvers and actors appear only as a role (`owner`, `approver`, `admin`,
 * `system`), never by id. Time entries are exported by the host as before (`GET export` / its own exporter).
 */
final readonly class TimesheetPersonalDataExporter
{
    /** Timesheets read per query. */
    public const BATCH = 200;

    /**
     * Receives the stores.
     */
    public function __construct(
        private TimesheetRepositoryInterface $timesheets,
        private TimesheetRetentionRepositoryInterface $retention,
        private TimesheetEventRepositoryInterface $events,
        private BalanceLedgerRepositoryInterface $ledger,
    ) {
    }

    /**
     * The person's timesheets (with their audit rows) and ledger lines.
     *
     * @return array{timesheets: list<array<string, mixed>>, ledger: list<array<string, mixed>>}
     */
    public function export(string $ownerRef): array
    {
        $timesheets = [];
        $after      = '';
        do {
            $ids = $this->retention->timesheetIds(null, $ownerRef, $after, self::BATCH);
            foreach ($ids as $id) {
                $after     = $id;
                $timesheet = $this->timesheets->find($id);
                if ($timesheet instanceof Timesheet) {
                    $timesheets[] = $this->timesheet($timesheet);
                }
            }
        } while (count($ids) === self::BATCH);

        $ledger = [];
        foreach ($this->retention->ledgerChains(null, $ownerRef) as $chain) {
            foreach ($this->ledger->findByOwner($ownerRef, $chain['scopeKey']) as $line) {
                $ledger[] = $line->toArray();
            }
        }

        return ['timesheets' => $timesheets, 'ledger' => $ledger];
    }

    /**
     * @return array<string, mixed>
     */
    private function timesheet(Timesheet $timesheet): array
    {
        $period = $timesheet->getPeriod();

        return [
            'id'              => $timesheet->getId(),
            'scopeKey'        => $timesheet->getScopeKey(),
            'period'          => ['key' => $period->key, 'kind' => $period->kind->value, 'start' => $period->start, 'end' => $period->end, 'timezone' => $timesheet->getTimezone()],
            'status'          => $timesheet->getStatus()->value,
            'lockedAt'        => $timesheet->getLockedAt()?->format(DATE_ATOM),
            'lockReason'      => $timesheet->getLockReason()?->value,
            'submittedAt'     => $timesheet->getSubmittedAt()?->format(DATE_ATOM),
            'decidedAt'       => $timesheet->getDecidedAt()?->format(DATE_ATOM),
            'decisionComment' => $timesheet->getDecisionComment(),
            'totals'          => $timesheet->getTotals(),
            'createdAt'       => $timesheet->getCreatedAt()->format(DATE_ATOM),
            'events'          => array_map(static fn (TimesheetEvent $e): array => [
                'action'     => $e->getAction()->value,
                'fromStatus' => $e->getFromStatus()->value,
                'toStatus'   => $e->getToStatus()->value,
                'actorRole'  => $e->getActorRole()->value,
                'client'     => $e->getClient()->value,
                'occurredAt' => $e->getOccurredAt()->format(DATE_ATOM),
                'comment'    => $e->getComment(),
            ], $this->events->findByTimesheet($timesheet->getId())),
        ];
    }
}
