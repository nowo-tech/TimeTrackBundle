<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Query;

use Nowo\TimeTrackBundle\Subject\TimeSubjectRef;

use function json_encode;

use const JSON_THROW_ON_ERROR;

/**
 * Since 2.1.0 (Beacon spec 256): the key of one {@see TimeEntryTotal}: only the parts of the grouping are set (ids and
 * keys, never labels: label subject groups with `TimeSubjectLabelResolver`). Framework-free, immutable, built per call.
 */
final readonly class TimeEntryGroupKey
{
    /**
     * @param TimeSubjectRef|null $subject Subject (groupings Subject, SubjectAndActivityType)
     * @param string|null $subjectType Subject type (grouping SubjectType)
     * @param string|null $activityTypeKey Activity type (groupings ActivityType, SubjectAndActivityType)
     * @param string|null $ownerId Owner id (grouping Owner)
     * @param string|null $taskId Task id (grouping Task)
     * @param AllocationFilter|null $allocation Allocation (grouping Allocation)
     */
    public function __construct(
        public ?TimeSubjectRef $subject = null,
        public ?string $subjectType = null,
        public ?string $activityTypeKey = null,
        public ?string $ownerId = null,
        public ?string $taskId = null,
        public ?AllocationFilter $allocation = null,
    ) {
    }

    /**
     * A key for maps inside one call (exact, never stored).
     */
    public function key(): string
    {
        return json_encode([$this->subject?->type, $this->subject?->id, $this->subjectType, $this->activityTypeKey, $this->ownerId, $this->taskId, $this->allocation?->value], JSON_THROW_ON_ERROR);
    }
}
