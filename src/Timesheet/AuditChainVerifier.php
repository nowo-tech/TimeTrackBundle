<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Timesheet;

use Nowo\TimeTrackBundle\Entity\Timesheet;
use Nowo\TimeTrackBundle\Repository\BalanceLedgerRepositoryInterface;
use Nowo\TimeTrackBundle\Repository\TimesheetEventRepositoryInterface;
use Nowo\TimeTrackBundle\Repository\TimesheetRepositoryInterface;
use Nowo\TimeTrackBundle\Repository\TimesheetRetentionRepositoryInterface;

use function count;
use function sprintf;

/**
 * Since 1.13: recomputes the audit chains (contract §3.3, §3.4) and names the first broken link of each: timesheet
 * rows must be numbered 1, 2, … with `prev_hash` = the previous row's hash, every stored hash must match its row, and
 * the last hash must be the timesheet's head; ledger lines of an owner and scope must form one chain from a line
 * without `prev_hash`, each hash matching its line.
 */
final readonly class AuditChainVerifier
{
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
     * Problems found (empty = every chain is intact), optionally for one scope and one owner.
     *
     * @return list<string>
     */
    public function verify(?string $scopeKey = null, ?string $ownerRef = null): array
    {
        $problems = [];
        $after    = '';
        do {
            $ids = $this->retention->timesheetIds($scopeKey, $ownerRef, $after, 200);
            foreach ($ids as $id) {
                $after   = $id;
                $problem = $this->verifyTimesheet($id);
                if ($problem !== null) {
                    $problems[] = $problem;
                }
            }
        } while (count($ids) === 200);

        foreach ($this->retention->ledgerChains($scopeKey, $ownerRef) as $chain) {
            $problem = $this->verifyLedger($chain['ownerRef'], $chain['scopeKey']);
            if ($problem !== null) {
                $problems[] = $problem;
            }
        }

        return $problems;
    }

    /**
     * The first broken link of a timesheet's audit rows, or null.
     */
    public function verifyTimesheet(string $timesheetId): ?string
    {
        $timesheet = $this->timesheets->find($timesheetId);
        if (!$timesheet instanceof Timesheet) {
            return null;
        }
        $previous = null;
        $seq      = 0;
        foreach ($this->events->findByTimesheet($timesheetId) as $event) {
            ++$seq;
            if ($event->getSeq() !== $seq || $event->getPrevHash() !== $previous || $event->computeHash() !== $event->getHash()) {
                return sprintf('timesheet %s: audit row %d (%s) is broken', $timesheetId, $event->getSeq(), $event->getId());
            }
            $previous = $event->getHash();
        }

        return $timesheet->getHeadHash() === $previous ? null : sprintf('timesheet %s: head hash does not match the last audit row', $timesheetId);
    }

    /**
     * The first broken link of a ledger chain, or null.
     */
    public function verifyLedger(string $ownerRef, string $scopeKey): ?string
    {
        $byPrev = [];
        foreach ($this->ledger->findByOwner($ownerRef, $scopeKey) as $line) {
            if ($line->computeHash() !== $line->getHash()) {
                return sprintf('ledger %s / %s: line %s is broken', $ownerRef, $scopeKey, $line->getId());
            }
            $byPrev[$line->getPrevHash() ?? ''][] = $line;
        }
        $total = 0;
        foreach ($byPrev as $lines) {
            $total += count($lines);
        }
        $visited = 0;
        $next    = '';
        while (isset($byPrev[$next])) {
            if (count($byPrev[$next]) !== 1) {
                return sprintf('ledger %s / %s: the chain forks after %s', $ownerRef, $scopeKey, $next === '' ? 'its start' : $next);
            }
            $line = $byPrev[$next][0];
            ++$visited;
            $next = $line->getHash();
        }

        return $visited === $total ? null : sprintf('ledger %s / %s: %d line(s) are not linked to the chain', $ownerRef, $scopeKey, $total - $visited);
    }
}
