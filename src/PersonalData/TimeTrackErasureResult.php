<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\PersonalData;

/**
 * Since 1.35.0: what {@see TimeTrackPersonalDataInterface::erase()} changed, as counts (the bundle logs counts only).
 */
final readonly class TimeTrackErasureResult
{
    /**
     * @param int $clientSessions Client sessions deleted (the apps are signed out)
     * @param int $deviceSignIns Device sign-in requests deleted
     * @param int $monitoringUploads Monitoring upload intents deleted (the evidence files live in the host's store)
     * @param int $monitoringAcknowledgements Monitoring notice acknowledgements deleted
     * @param int $entriesAnonymised Entries anonymised (owned ones with {@see TimeEntryErasure::Anonymise}, plus those under the former reference)
     * @param int $entriesCleared Entries whose description or note was cleared ({@see TimeEntryErasure::FreeText})
     * @param int $calendarConnectionsKept Calendar connections left for `CalendarConnectionService::revokeAllFor()` (it revokes the host grant first)
     */
    public function __construct(
        public int $clientSessions,
        public int $deviceSignIns,
        public int $monitoringUploads,
        public int $monitoringAcknowledgements,
        public int $entriesAnonymised,
        public int $entriesCleared,
        public int $calendarConnectionsKept,
    ) {
    }
}
