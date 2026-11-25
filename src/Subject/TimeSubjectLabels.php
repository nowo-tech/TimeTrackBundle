<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Subject;

use function array_values;

/**
 * Since 2.1.0 (Beacon spec 256): the answer of a batched read ({@see TimeSubjectProviderInterface::describe()},
 * {@see TimeSubjectLabelResolver::forPage()}): the labels of the subjects the actor may read. A subject left out is not
 * readable or does not exist (one answer: the caller cannot tell which). Built per call and discarded with the response.
 */
final readonly class TimeSubjectLabels
{
    /**
     * @param array<string, TimeSubjectLabel> $labels Keyed by {@see TimeSubjectRef::key()}
     */
    private function __construct(private array $labels)
    {
    }

    /**
     * The labels of a list (a later label of the same subject replaces an earlier one).
     *
     * @param iterable<TimeSubjectLabel> $labels
     */
    public static function of(iterable $labels): self
    {
        $keyed = [];
        foreach ($labels as $label) {
            $keyed[$label->subject->key()] = $label;
        }

        return new self($keyed);
    }

    /**
     * No readable subject.
     */
    public static function none(): self
    {
        return new self([]);
    }

    /**
     * The label of a readable subject; null when the subject is not readable or unknown.
     */
    public function get(TimeSubjectRef $subject): ?TimeSubjectLabel
    {
        $label = $this->labels[$subject->key()] ?? null;

        // A provider may only describe the subject it was asked about: the key is type and id, compared exactly.
        return $label !== null && $label->subject->equals($subject) ? $label : null;
    }

    /**
     * Whether the actor may read the subject.
     */
    public function readable(TimeSubjectRef $subject): bool
    {
        return $this->get($subject) instanceof TimeSubjectLabel;
    }

    /**
     * These labels and another set's (the other's win on the same subject).
     */
    public function with(self $other): self
    {
        return new self($other->labels + $this->labels);
    }

    /**
     * Only the labels of the given subjects (what a provider may answer for).
     *
     * @param array<string, TimeSubjectRef> $asked Keyed by {@see TimeSubjectRef::key()}
     */
    public function onlyFor(array $asked): self
    {
        $kept = [];
        foreach ($this->labels as $key => $label) {
            if (isset($asked[$key])) {
                $kept[$key] = $label;
            }
        }

        return new self($kept);
    }

    /**
     * Every label.
     *
     * @return list<TimeSubjectLabel>
     */
    public function all(): array
    {
        return array_values($this->labels);
    }
}
