<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Import;

/**
 * Since 1.11: which imported entries of one external source a removal targets (exactly one scope; the source always
 * matches exactly, never by prefix).
 */
final readonly class ImportedTimeEntryScope
{
    public const ENTRY  = 'entry';
    public const USER   = 'user';
    public const TASKS  = 'tasks';
    public const BOARD  = 'board';
    public const SOURCE = 'source';

    /**
     * @param list<string> $values the external id, the user id, the task ids or the board id ([] for SOURCE)
     */
    private function __construct(
        public string $externalSource,
        public string $kind,
        public array $values,
    ) {
    }

    /**
     * One imported entry by its external id.
     */
    public static function entry(string $externalSource, string $externalId): self
    {
        return new self($externalSource, self::ENTRY, [$externalId]);
    }

    /**
     * Every imported entry of the source owned by a user.
     */
    public static function user(string $externalSource, string $userId): self
    {
        return new self($externalSource, self::USER, [$userId]);
    }

    /**
     * @param list<string> $taskIds
     */
    public static function tasks(string $externalSource, array $taskIds): self
    {
        return new self($externalSource, self::TASKS, $taskIds);
    }

    /**
     * Every imported entry of the source whose board snapshot is this board.
     */
    public static function board(string $externalSource, string $boardId): self
    {
        return new self($externalSource, self::BOARD, [$boardId]);
    }

    /**
     * Every imported entry of exactly this source.
     */
    public static function source(string $externalSource): self
    {
        return new self($externalSource, self::SOURCE, []);
    }
}
