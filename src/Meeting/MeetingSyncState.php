<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Meeting;

use DateTimeImmutable;

/**
 * Since 1.14 (contract §22.6): the host's meeting sync state for one user.
 */
final readonly class MeetingSyncState
{
    /**
     * @param int|null $refreshAfterSeconds Host hint for the clients' poll gap (bounded to 60-3600 by the bundle; null = `meetings.refresh_after_seconds`)
     */
    public function __construct(
        public MeetingSyncStatus $status,
        public ?DateTimeImmutable $lastSyncAt = null,
        public ?int $refreshAfterSeconds = null,
    ) {
    }
}
