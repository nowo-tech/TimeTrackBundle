<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Subject;

use Nowo\TimeTrackBundle\Dto\TimeEntryView;
use Nowo\TimeTrackBundle\Query\TimeEntryQueryInterface;
use Nowo\TimeTrackBundle\ValueObject\TimeTrackUserRef;

use function array_values;

/**
 * Since 2.1.0 (Beacon spec 256): labels a page of entries for a viewer, applying `canRead` through the batched
 * {@see TimeSubjectProviderInterface::describe()}: each provider is asked **once per type per page** (in batches of
 * 1000), never once per row. The query ports apply no access rule (by contract since 1.35): a host that shows subjects
 * from {@see TimeEntryQueryInterface} labels them here, and shows a subject this resolver
 * does not label as hidden (`{type, hidden: true}`, without its id; Q-TS-6).
 *
 * Public service (id = class). Stateless: the answer is built per call (FrankenPHP worker mode, kernel reset false).
 */
final readonly class TimeSubjectLabelResolver
{
    /**
     * Receives the subject providers.
     */
    public function __construct(private TimeSubjectRegistry $registry)
    {
    }

    /**
     * Whether any subject provider is registered.
     */
    public function isEnabled(): bool
    {
        return $this->registry->isEnabled();
    }

    /**
     * The labels of the readable subjects of a page of one tenant: the entries whose scope is the context's (an entry
     * without a scope counts as `''`) are described with the context; the others stay unlabelled (hidden). Label a page
     * of several tenants with one call per tenant.
     *
     * @param list<TimeEntryView> $entries
     */
    public function forPage(TimeSubjectContext $context, array $entries): TimeSubjectLabels
    {
        $subjects = [];
        foreach ($entries as $entry) {
            if ($entry->subject instanceof TimeSubjectRef && ($entry->scopeKey ?? '') === $context->scopeKey) {
                $subjects[] = $entry->subject;
            }
        }

        return $this->forSubjects($context, $subjects);
    }

    /**
     * The labels of the readable subjects among these, in the context's tenant (one call per type).
     *
     * @param list<TimeSubjectRef> $subjects
     */
    public function forSubjects(TimeSubjectContext $context, array $subjects): TimeSubjectLabels
    {
        $byType = [];
        foreach ($subjects as $subject) {
            $byType[$subject->type][$subject->key()] = $subject;
        }

        $labels = TimeSubjectLabels::none();
        foreach ($byType as $type => $refs) {
            $labels = $labels->with($this->registry->describe($context, (string) $type, array_values($refs)));
        }

        return $labels;
    }

    /**
     * Whether the viewer may read one subject (a single-entry path; pages use {@see self::forPage()}).
     */
    public function canRead(TimeSubjectContext $context, TimeSubjectRef $subject): bool
    {
        return $this->forSubjects($context, [$subject])->readable($subject);
    }

    /**
     * The labels of subjects of several tenants, for one viewer: one call per (tenant, type) present.
     *
     * @param iterable<array{0: string|null, 1: TimeSubjectRef|null}> $subjects Pairs of (entry scope, subject)
     *
     * @internal used by the bundle's own read surfaces
     */
    public function forViewer(TimeTrackUserRef $viewer, iterable $subjects): ScopedSubjectLabels
    {
        $byScope = [];
        foreach ($subjects as [$scopeKey, $subject]) {
            if ($subject instanceof TimeSubjectRef) {
                $byScope[$scopeKey ?? ''][] = $subject;
            }
        }

        $labels = [];
        foreach ($byScope as $scopeKey => $refs) {
            $labels[(string) $scopeKey] = $this->forSubjects(new TimeSubjectContext($viewer, (string) $scopeKey), $refs);
        }

        return new ScopedSubjectLabels($labels);
    }
}
