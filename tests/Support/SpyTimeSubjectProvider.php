<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Tests\Support;

use Nowo\TimeTrackBundle\Subject\TimeSubjectContext;
use Nowo\TimeTrackBundle\Subject\TimeSubjectLabel;
use Nowo\TimeTrackBundle\Subject\TimeSubjectLabels;
use Nowo\TimeTrackBundle\Subject\TimeSubjectProviderInterface;
use Nowo\TimeTrackBundle\Subject\TimeSubjectRef;
use Nowo\TimeTrackBundle\Subject\TimeSubjectSearch;
use RuntimeException;

use function count;
use function in_array;

/**
 * Since 2.1.0: test subject provider (Beacon spec 256) that records every call, labels the subjects whose id is in
 * `$readable` as "Label <id>", answers canLog from `$loggable` and may throw from every method.
 */
final class SpyTimeSubjectProvider implements TimeSubjectProviderInterface
{
    /** @var list<list<TimeSubjectRef>> one list per describe() call */
    public array $describeCalls = [];

    /** @var list<TimeSubjectContext> the context of every describe() call */
    public array $describeContexts = [];

    /** @var int number of canLog() calls */
    public int $canLogCalls = 0;

    /** @var list<TimeSubjectLabel> extra labels describe() adds (subjects that were not asked) */
    public array $extraLabels = [];

    /** @var list<mixed> what search() answers */
    public array $searchResults = [];

    /** @var bool whether every method throws */
    public bool $fail = false;

    /** @var int|null the describe() call (1-based) that throws */
    public ?int $failOnDescribeCall = null;

    /**
     * @param list<string> $readable Ids describe() labels
     * @param list<string> $loggable Ids canLog() accepts
     */
    public function __construct(
        private readonly string $type = 'project',
        public array $readable = [],
        public array $loggable = [],
    ) {
    }

    /**
     * The subject type.
     */
    public static function subjectType(): string
    {
        return 'project';
    }

    /**
     * Whether the subject may sit next to a task.
     */
    public static function allowsTask(): bool
    {
        return true;
    }

    /**
     * Whether the id is in `$loggable`.
     */
    public function canLog(TimeSubjectContext $context, TimeSubjectRef $subject): bool
    {
        ++$this->canLogCalls;
        $this->throwWhenFailing();

        return $subject->type === $this->type && in_array($subject->id, $this->loggable, true);
    }

    /**
     * Labels the readable subjects plus `$extraLabels`.
     */
    public function describe(TimeSubjectContext $context, array $subjects): TimeSubjectLabels
    {
        $this->describeCalls[]    = $subjects;
        $this->describeContexts[] = $context;
        if ($this->failOnDescribeCall === count($this->describeCalls)) {
            throw new RuntimeException('describe batch failed');
        }
        $this->throwWhenFailing();
        $labels = $this->extraLabels;
        foreach ($subjects as $subject) {
            if (in_array($subject->id, $this->readable, true)) {
                $labels[] = new TimeSubjectLabel($subject, 'Label ' . $subject->id, 'https://example.test/' . $subject->id);
            }
        }

        return TimeSubjectLabels::of($labels);
    }

    /**
     * Answers `$searchResults` as they are (the registry filters them).
     */
    public function search(TimeSubjectContext $context, TimeSubjectSearch $search): array
    {
        $this->throwWhenFailing();

        /* @phpstan-ignore return.type (a misbehaving provider is the point of the double) */
        return $this->searchResults;
    }

    /**
     * Number of describe() calls.
     */
    public function describeCount(): int
    {
        return count($this->describeCalls);
    }

    /**
     * Throws when `$fail` is set.
     */
    private function throwWhenFailing(): void
    {
        if ($this->fail) {
            throw new RuntimeException('provider down for user 7 on project secret');
        }
    }
}
