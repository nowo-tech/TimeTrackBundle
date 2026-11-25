<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Entry;

use DateTimeImmutable;
use InvalidArgumentException;
use Nowo\TimeTrackBundle\Subject\TimeAllocation;

/**
 * Since 1.40.0: a manual entry {@see TimeEntryOperationsInterface::createManual()} creates. The rules are those of
 * `POST entries` (`ManualEntryService::create()`). Since 1.40.1 (audit ZAN-3) the allowed age and the metadata are
 * checked when the draft is built, with the rules of {@see EntryMetadataRules}.
 *
 * Since 2.1.0 (Beacon spec 256) `$allocation` names what the entry is logged on (task, task and subject, subject alone or
 * nothing) and its activity type; `$taskId` is kept for BC and must then be null or the allocation's task.
 * {@see self::allocation()} is the effective one. Immutable; built per call.
 */
final readonly class ManualTimeEntryDraft
{
    /**
     * @param string|null $taskId Task of the entry (null = an entry without a task, with `entries.allow_without_task`)
     * @param DateTimeImmutable $startedAt Start (past)
     * @param DateTimeImmutable $endedAt End (after the start, at most 24 h later, not in the future)
     * @param string|null $note The person's note (cleaned and cut like every note)
     * @param array<string, scalar> $metadata Extra metadata ({@see EntryMetadataRules}: key pattern, finite scalars, strings
     *                                        up to 2 000 bytes, at most 32 keys and 16 KB; `closed_by` is refused)
     * @param bool|null $billable The billable flag (null = the host default)
     * @param string|null $channel Channel label stored on the entry (`api`, `mcp`, …)
     * @param string|null $description Free text of an entry without a task
     * @param string|null $scopeKey Tenant scope of an entry without a task (one of the owner's; null = the current one)
     * @param int|null $maxAgeDays Oldest allowed start in days, 0 to {@see EntryMetadataRules::MAX_AGE_DAYS_CAP} (null =
     *                             `manual_entries.max_age_days`), e.g. for seeded demo data
     * @param TimeAllocation|null $allocation Since 2.1.0: task, subject and activity type (null = `$taskId` alone)
     *
     * @throws InvalidArgumentException When the allowed age is out of range, the metadata is not accepted, or `$taskId`
     *                                  is not the allocation's task
     */
    public function __construct(
        public ?string $taskId,
        public DateTimeImmutable $startedAt,
        public DateTimeImmutable $endedAt,
        public ?string $note = null,
        public array $metadata = [],
        public ?bool $billable = null,
        public ?string $channel = null,
        public ?string $description = null,
        public ?string $scopeKey = null,
        public ?int $maxAgeDays = null,
        public ?TimeAllocation $allocation = null,
    ) {
        if ($allocation instanceof TimeAllocation && $taskId !== null && $taskId !== $allocation->taskId) {
            throw new InvalidArgumentException('The task of a manual entry draft must be the task of its allocation (or null).');
        }
        EntryMetadataRules::assertMaxAgeDays($maxAgeDays, 'a manual entry');
        EntryMetadataRules::assertValid($metadata, false, 'a manual entry');
        if (!EntryMetadataRules::fits($metadata)) {
            throw new InvalidArgumentException('The metadata of a manual entry may hold at most ' . EntryMetadataRules::MAX_ENCODED_BYTES . ' bytes.');
        }
    }

    /**
     * Since 2.1.0: the effective allocation (the given one, else `$taskId` alone).
     */
    public function allocation(): TimeAllocation
    {
        return $this->allocation ?? TimeAllocation::ofTask($this->taskId);
    }
}
