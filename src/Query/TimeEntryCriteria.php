<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Query;

use DateTimeImmutable;
use InvalidArgumentException;
use Nowo\TimeTrackBundle\Doctrine\DoctrineUserReferenceResolver;
use Nowo\TimeTrackBundle\Enum\TimeEntrySource;
use Nowo\TimeTrackBundle\Retention\FormerPersonTimeEntries;
use Nowo\TimeTrackBundle\Timesheet\ScopeKeys;

use function array_values;
use function count;
use function in_array;
use function is_string;
use function strlen;

/**
 * Since 1.35.0 (DDD-host checklist B7): the filters of a {@see TimeEntryQueryInterface} listing, count or total. A
 * listing is always keyed: at least one of the owner ids, scope keys (tenants), task ids or board ids is given, so a host
 * never reads the whole table by accident. Every list compares byte for byte; an empty list matches nothing.
 *
 * Only entries that still have an owner are listed (an anonymised entry, a former person's entry or the entry of a
 * deleted user has none): those are read through {@see FormerPersonTimeEntries}.
 *
 * Immutable, framework-free and built per call (FrankenPHP worker mode, kernel reset false).
 */
final readonly class TimeEntryCriteria
{
    /** The most values one list filter takes (below every driver's parameter limit). */
    public const MAX_VALUES = 1000;

    /** The longest task or board id the bundle stores. */
    private const MAX_TASK_ID_LENGTH = 36;

    /** @var list<string>|null */
    public ?array $ownerIds;

    /** @var list<string>|null */
    public ?array $scopeKeys;

    /** @var list<string>|null */
    public ?array $taskIds;

    /** @var list<string>|null */
    public ?array $boardIds;

    /** @var list<TimeEntrySource>|null */
    public ?array $sources;

    /** @var list<string> */
    public array $excludeEntryIds;

    /**
     * @param list<string>|null $ownerIds Owner user ids (the `user_id` column, as `TimeTrackUserRef::$id`); null = any owner
     * @param list<string>|null $scopeKeys Tenant scope keys; null = any scope (an entry with no scope matches only null)
     * @param list<string>|null $taskIds Task ids; null = any task
     * @param list<string>|null $boardIds Board ids (the board snapshot of the entry); null = any board
     * @param DateTimeImmutable|null $startedFrom Entries started at or after this time
     * @param DateTimeImmutable|null $startedTo Entries started at or before this time
     * @param DateTimeImmutable|null $overlapFrom With `$overlapTo`: entries that overlap the range (they end after this time…)
     * @param DateTimeImmutable|null $overlapTo …and start before this time; both or neither
     * @param list<TimeEntrySource>|null $sources Sources; null = every source, imported entries included
     * @param bool|null $serviceAccount true = only service accounts' (agents') entries, false = only people's, null = both
     * @param list<string> $excludeEntryIds Entry ids left out (e.g. the entry being corrected in an overlap check)
     *
     * @throws InvalidArgumentException When no key is given, a list is too long, a value is not usable, or only one end
     *                                  of the overlap range is given
     */
    public function __construct(
        ?array $ownerIds = null,
        ?array $scopeKeys = null,
        ?array $taskIds = null,
        ?array $boardIds = null,
        public ?DateTimeImmutable $startedFrom = null,
        public ?DateTimeImmutable $startedTo = null,
        public ?DateTimeImmutable $overlapFrom = null,
        public ?DateTimeImmutable $overlapTo = null,
        ?array $sources = null,
        public ?bool $serviceAccount = null,
        array $excludeEntryIds = [],
    ) {
        if ($ownerIds === null && $scopeKeys === null && $taskIds === null && $boardIds === null) {
            throw new InvalidArgumentException('A TimeTrack entry query needs a key: owner ids, scope keys, task ids or board ids.');
        }
        if ((!$overlapFrom instanceof DateTimeImmutable) !== (!$overlapTo instanceof DateTimeImmutable)) {
            throw new InvalidArgumentException('A TimeTrack entry overlap filter needs both ends of the range.');
        }
        $this->ownerIds        = $this->strings($ownerIds, 'owner id', static fn (string $id): bool => DoctrineUserReferenceResolver::isExactId($id));
        $this->scopeKeys       = $this->strings($scopeKeys, 'scope key', static fn (string $key): bool => $key !== '' && ScopeKeys::isUsable($key));
        $this->taskIds         = $this->strings($taskIds, 'task id', $this->isId(...));
        $this->boardIds        = $this->strings($boardIds, 'board id', $this->isId(...));
        $this->excludeEntryIds = $this->strings($excludeEntryIds, 'entry id', $this->isId(...)) ?? [];
        if ($sources !== null) {
            $this->assertCount($sources, 'source');
            foreach ($sources as $source) {
                if (!$source instanceof TimeEntrySource) {
                    throw new InvalidArgumentException('A TimeTrack entry query source must be a TimeEntrySource case.');
                }
            }
            $sources = array_values($sources);
        }
        $this->sources = $sources;
    }

    /**
     * The entries of one owner.
     */
    public static function ofOwner(string $ownerId): self
    {
        return new self(ownerIds: [$ownerId]);
    }

    /**
     * Whether an empty list makes the query match nothing (no database round trip).
     */
    public function matchesNothing(): bool
    {
        return $this->ownerIds === [] || $this->scopeKeys === [] || $this->taskIds === [] || $this->boardIds === [] || $this->sources === [];
    }

    /**
     * Whether a row the database returned matches every string filter byte for byte (a MySQL collation may fold case).
     */
    public function matchesExactly(string $entryId, ?string $ownerId, ?string $scopeKey, string $taskId, ?string $boardId): bool
    {
        return $ownerId !== null
            && $this->isIn($ownerId, $this->ownerIds)
            && ($this->scopeKeys === null || ScopeKeys::isOneOf($scopeKey, $this->scopeKeys))
            && $this->isIn($taskId, $this->taskIds)
            && ($this->boardIds === null || ($boardId !== null && $this->isIn($boardId, $this->boardIds)))
            && !$this->isIn($entryId, $this->excludeEntryIds, false);
    }

    /**
     * @param list<string>|null $values
     */
    private function isIn(string $value, ?array $values, bool $whenNull = true): bool
    {
        if ($values === null) {
            return $whenNull;
        }

        return in_array($value, $values, true);
    }

    private function isId(string $id): bool
    {
        return $id !== '' && strlen($id) <= self::MAX_TASK_ID_LENGTH && DoctrineUserReferenceResolver::isExactId($id);
    }

    /**
     * @param array<mixed>|null $values
     * @param callable(string): bool $usable
     *
     * @return list<string>|null
     */
    private function strings(?array $values, string $label, callable $usable): ?array
    {
        if ($values === null) {
            return null;
        }
        $this->assertCount($values, $label);
        $kept = [];
        foreach ($values as $value) {
            if (!is_string($value) || $value === '' || !$usable($value)) {
                throw new InvalidArgumentException('A TimeTrack entry query ' . $label . ' must be a non-empty string with no leading or trailing blank and no invisible character, within the stored length.');
            }
            $kept[] = $value;
        }

        return $kept;
    }

    /**
     * @param array<mixed> $values
     */
    private function assertCount(array $values, string $label): void
    {
        if (count($values) > self::MAX_VALUES) {
            throw new InvalidArgumentException('A TimeTrack entry query takes at most ' . self::MAX_VALUES . ' values per filter (' . $label . ').');
        }
    }
}
