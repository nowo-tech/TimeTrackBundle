<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Tests\Support;

use Closure;
use Nowo\TimeTrackBundle\Subject\TimeSubjectContext;
use Nowo\TimeTrackBundle\Subject\TimeSubjectLabel;
use Nowo\TimeTrackBundle\Subject\TimeSubjectLabels;
use Nowo\TimeTrackBundle\Subject\TimeSubjectProviderInterface;
use Nowo\TimeTrackBundle\Subject\TimeSubjectRef;
use Nowo\TimeTrackBundle\Subject\TimeSubjectSearch;
use RuntimeException;

use function in_array;

/**
 * Since 2.1.0: base of the write-path test subject providers (Beacon spec 256). `canLog` accepts the ids in `$loggable`
 * (any actor) or `$loggableBy[actor id]`, in any scope unless `$scopes` names the allowed ones; `describe()` labels the
 * ids in `$readable` (any actor) or `$readableBy[actor id]`; every call is recorded and every method may throw.
 * Mutable on purpose: functional tests set it per test through the test container.
 */
abstract class AllocationTestSubjectProvider implements TimeSubjectProviderInterface
{
    /** @var list<string> ids any actor may log time on */
    public array $loggable = [];

    /** @var array<int|string, list<string>> actor id => ids that actor may log time on */
    public array $loggableBy = [];

    /** @var list<string>|null scope keys canLog accepts (null = any) */
    public ?array $scopes = null;

    /** @var list<string> ids any actor may read */
    public array $readable = [];

    /** @var array<int|string, list<string>> actor id => ids that actor may read */
    public array $readableBy = [];

    /** @var list<array{actor: string|null, scope: string, id: string}> every canLog() call */
    public array $canLogCalls = [];

    /** @var list<array{actor: string|null, scope: string, ids: list<string>}> every describe() call */
    public array $describeCalls = [];

    /** @var list<TimeSubjectLabel> what search() answers (before the registry filters it) */
    public array $searchResults = [];

    /** @var list<array{actor: string|null, scope: string, query: string, limit: int}> every search() call */
    public array $searchCalls = [];

    /** @var bool whether every method throws */
    public bool $fail = false;

    /** @var (Closure(): mixed)|null since audit ZBQ-L5: runs on every describe() call (e.g. to see whether a lock is held) */
    public ?Closure $onDescribe = null;

    /**
     * Resets the provider to "nothing allowed, nothing recorded".
     */
    public function reset(): void
    {
        $this->loggable      = [];
        $this->loggableBy    = [];
        $this->scopes        = null;
        $this->readable      = [];
        $this->readableBy    = [];
        $this->canLogCalls   = [];
        $this->describeCalls = [];
        $this->searchResults = [];
        $this->searchCalls   = [];
        $this->fail          = false;
        $this->onDescribe    = null;
    }

    /**
     * Whether the actor may log on the subject (see the class doc).
     */
    public function canLog(TimeSubjectContext $context, TimeSubjectRef $subject): bool
    {
        $this->canLogCalls[] = ['actor' => $context->actor->id, 'scope' => $context->scopeKey, 'id' => $subject->id];
        $this->throwWhenFailing();
        if ($this->scopes !== null && !in_array($context->scopeKey, $this->scopes, true)) {
            return false;
        }

        return in_array($subject->id, $this->loggable, true) || in_array($subject->id, $this->loggableBy[(string) $context->actor->id] ?? [], true);
    }

    /**
     * Labels the subjects the actor may read as "<Type> <id>", with an https URL.
     */
    public function describe(TimeSubjectContext $context, array $subjects): TimeSubjectLabels
    {
        $ids = [];
        foreach ($subjects as $subject) {
            $ids[] = $subject->id;
        }
        $this->describeCalls[] = ['actor' => $context->actor->id, 'scope' => $context->scopeKey, 'ids' => $ids];
        if ($this->onDescribe instanceof Closure) {
            ($this->onDescribe)();
        }
        $this->throwWhenFailing();
        $labels = [];
        foreach ($subjects as $subject) {
            if (in_array($subject->id, $this->readable, true) || in_array($subject->id, $this->readableBy[(string) $context->actor->id] ?? [], true)) {
                $labels[] = new TimeSubjectLabel($subject, static::subjectType() . ' ' . $subject->id, 'https://crm.example.test/' . static::subjectType() . '/' . $subject->id);
            }
        }

        return TimeSubjectLabels::of($labels);
    }

    /**
     * Answers `$searchResults` as they are.
     */
    public function search(TimeSubjectContext $context, TimeSubjectSearch $search): array
    {
        $this->searchCalls[] = ['actor' => $context->actor->id, 'scope' => $context->scopeKey, 'query' => $search->query, 'limit' => $search->limit];
        $this->throwWhenFailing();

        return $this->searchResults;
    }

    /**
     * Throws when `$fail` is set (with a message a log must never carry).
     */
    private function throwWhenFailing(): void
    {
        if ($this->fail) {
            throw new RuntimeException('crm down for secret customer');
        }
    }
}
