<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Tests\Support;

use Doctrine\DBAL\Driver\Exception as DriverException;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Nowo\TimeTrackBundle\Entity\BalanceLedgerLine;
use Nowo\TimeTrackBundle\Repository\BalanceLedgerRepositoryInterface;

use function array_filter;
use function array_values;
use function end;

/**
 * In-memory balance ledger of the unit tests (chain order = append order).
 */
final class InMemoryBalanceLedgerRepository implements BalanceLedgerRepositoryInterface
{
    /** Since the GB fixes: the next flush fails like a concurrent append to the same chain. */
    public ?DriverException $failNextFlush = null;

    /** @var list<BalanceLedgerLine> */
    public array $lines = [];

    public int $flushes = 0;

    public function append(BalanceLedgerLine $line): void
    {
        $this->lines[] = $line;
    }

    public function flush(): void
    {
        if ($this->failNextFlush instanceof DriverException) {
            $driverException     = $this->failNextFlush;
            $this->failNextFlush = null;

            throw new UniqueConstraintViolationException($driverException, null);
        }
        ++$this->flushes;
    }

    public function find(string $id): ?BalanceLedgerLine
    {
        foreach ($this->lines as $line) {
            if ($line->getId() === $id) {
                return $line;
            }
        }

        return null;
    }

    public function findLast(string $ownerRef, string $scopeKey): ?BalanceLedgerLine
    {
        $lines = $this->findByOwner($ownerRef, $scopeKey);

        return $lines === [] ? null : end($lines);
    }

    public function findByOwner(string $ownerRef, string $scopeKey, ?string $fromDate = null, ?string $toDate = null): array
    {
        return array_values(array_filter(
            $this->lines,
            static fn (BalanceLedgerLine $l): bool => $l->getOwnerRef() === $ownerRef && $l->getScopeKey() === $scopeKey
                && ($fromDate === null || $l->getEffectiveDate() >= $fromDate) && ($toDate === null || $l->getEffectiveDate() <= $toDate),
        ));
    }

    public function findReversalOf(string $lineId): ?BalanceLedgerLine
    {
        foreach ($this->lines as $line) {
            if ($line->getReversesId() === $lineId) {
                return $line;
            }
        }

        return null;
    }

    public function findByTimesheet(string $timesheetId): array
    {
        return array_values(array_filter($this->lines, static fn (BalanceLedgerLine $l): bool => $l->getTimesheet()?->getId() === $timesheetId));
    }
}
