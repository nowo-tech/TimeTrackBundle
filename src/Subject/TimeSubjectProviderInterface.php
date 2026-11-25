<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Subject;

/**
 * Since 2.1.0 (Beacon spec 256): answers for one subject type (customers, the organisation, …): who may log time on a
 * subject, how subjects are shown to whom, and a bounded search for pickers. Implement it once per type; the bundle tags
 * every implementation for autoconfiguration (`nowo_time_track.time_subject_provider`), on standard Symfony and DDD hosts
 * alike: the signatures take bundle value objects only. Two providers for one type fail the build. With no provider,
 * every subject is refused (writes) or hidden (reads).
 *
 * Deny by default: an unknown id and an id the actor may not use get the same answer (no enumeration oracle). An
 * exception from a provider is logged by the bundle (class only) and answered as denied. Keep nothing between calls
 * (FrankenPHP worker mode, kernel reset false): cache in your own tag-aware pool if you need to.
 */
interface TimeSubjectProviderInterface
{
    /**
     * The subject type this provider answers for ({@see TimeSubjectRef::TYPE_PATTERN}). Constant per class (read when
     * the container is compiled).
     */
    public static function subjectType(): string;

    /**
     * Whether a subject of this type may sit on a task entry next to the task (Q-TS-1): true for customer-like types,
     * false for internal categories. Constant per class (read when the container is compiled).
     */
    public static function allowsTask(): bool;

    /**
     * Whether the actor may log time on the subject in the context's tenant. Deny by default.
     */
    public function canLog(TimeSubjectContext $context, TimeSubjectRef $subject): bool;

    /**
     * Labels (and links) of the subjects the actor may READ in the tenant; a subject left out is not readable (or does not
     * exist: one answer). At most 1000 references per call, all of this provider's type, distinct. Labels of subjects
     * that were not asked about are ignored.
     *
     * @param list<TimeSubjectRef> $subjects
     */
    public function describe(TimeSubjectContext $context, array $subjects): TimeSubjectLabels;

    /**
     * Subjects the actor may log on in the tenant, matching the query; at most `$search->limit` (the bundle cuts a longer
     * answer).
     *
     * @return list<TimeSubjectLabel>
     */
    public function search(TimeSubjectContext $context, TimeSubjectSearch $search): array;
}
