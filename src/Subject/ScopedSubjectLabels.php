<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Subject;

/**
 * Since 2.1.0: subject labels of several tenants for one viewer ({@see TimeSubjectLabelResolver::forViewer()}): a subject
 * is readable only in the tenant it was described in. Built per call.
 *
 * @internal
 */
final readonly class ScopedSubjectLabels
{
    /**
     * @param array<string, TimeSubjectLabels> $labels Tenant scope ('' = none) => labels
     */
    public function __construct(private array $labels = [])
    {
    }

    /**
     * The label of a subject of an entry in this scope, or null (not readable, unknown or not asked).
     */
    public function get(?string $scopeKey, TimeSubjectRef $subject): ?TimeSubjectLabel
    {
        return ($this->labels[$scopeKey ?? ''] ?? null)?->get($subject);
    }
}
