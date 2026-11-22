<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Repository;

use DateTimeImmutable;
use Nowo\TimeTrackBundle\Timesheet\ScopeKeys;

/**
 * Since 1.36.0 (Beacon spec 250): one listing of {@see TasklessScopedTimeEntryRepositoryInterface}: the entries of the
 * users (and, when asked, the ownerless entries of the scopes) started within the period, where an entry without a task
 * that is not the viewer's own is kept only in one of the viewer's tenant scopes. Framework-free; built per call.
 */
final readonly class TimeEntryListing
{
    /**
     * @param list<string> $userIds Owners whose entries are listed
     * @param DateTimeImmutable $from Period start (inclusive)
     * @param DateTimeImmutable $to Period end (inclusive)
     * @param bool $includeImported Whether imported entries are listed
     * @param bool $includeOwnerless Whether ownerless entries are listed
     * @param list<string>|null $ownerlessScopeKeys Scopes of the ownerless entries (null = any)
     * @param string $viewerId The viewer, whose own entries without a task are always kept
     * @param list<string> $tasklessScopeKeys The viewer's scopes: another owner's entry without a task is kept only in one of them
     */
    public function __construct(
        public array $userIds,
        public DateTimeImmutable $from,
        public DateTimeImmutable $to,
        public bool $includeImported,
        public bool $includeOwnerless,
        public ?array $ownerlessScopeKeys,
        public string $viewerId,
        public array $tasklessScopeKeys,
    ) {
    }

    /**
     * Whether an entry without a task, owned by `$ownerId` (null = ownerless) in `$scopeKey`, may be listed: the
     * viewer's own (`===`), or in one of the viewer's scopes byte for byte.
     */
    public function keepsWithoutTask(?string $ownerId, ?string $scopeKey): bool
    {
        return $ownerId === $this->viewerId || ScopeKeys::isOneOf($scopeKey, $this->tasklessScopeKeys);
    }
}
