<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Entry;

use DateTimeImmutable;

/**
 * Since 1.40.0: a manual entry {@see TimeEntryOperationsInterface::createManual()} creates. The rules are those of
 * `POST entries` (`ManualEntryService::create()`). Immutable; built per call.
 */
final readonly class ManualTimeEntryDraft
{
    /**
     * @param string|null $taskId Task of the entry (null = an entry without a task, with `entries.allow_without_task`)
     * @param DateTimeImmutable $startedAt Start (past)
     * @param DateTimeImmutable $endedAt End (after the start, at most 24 h later, not in the future)
     * @param string|null $note The person's note (cleaned and cut like every note)
     * @param array<string, scalar> $metadata Extra metadata (`closed_by` is reserved and dropped)
     * @param bool|null $billable The billable flag (null = the host default)
     * @param string|null $channel Channel label stored on the entry (`api`, `mcp`, …)
     * @param string|null $description Free text of an entry without a task
     * @param string|null $scopeKey Tenant scope of an entry without a task (one of the owner's; null = the current one)
     * @param int|null $maxAgeDays Oldest allowed start in days (null = `manual_entries.max_age_days`), e.g. for seeded demo data
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
    ) {
    }
}
