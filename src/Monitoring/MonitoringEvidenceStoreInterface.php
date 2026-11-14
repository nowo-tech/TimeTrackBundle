<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Monitoring;

use DateTimeImmutable;
use Nowo\TimeTrackBundle\ValueObject\TimeTrackUserRef;

/**
 * Since 1.26.0 (contract §24.3 / §24.6; host, `client_api.monitoring.evidence_store`, required when monitoring is on):
 * where screenshots, discard markers and activity aggregates go. The bundle never writes files: it checks every
 * request (policy, acknowledgement, running timer, schedule, age, slot, caps, MIME, dimensions, hash) and hands the
 * result here. Framework-free (REQ-ARCH-001 rule 3).
 *
 * Every method is called for the authenticated worker only and must only read or change that worker's data. A store
 * method that refuses the evidence for good throws {@see MonitoringEvidenceRejectedException} (`422
 * evidence_rejected`, never retried); any other exception is a temporary failure (`503`, retried).
 */
interface MonitoringEvidenceStoreInterface
{
    /**
     * Stores a checked screenshot (encrypt it, re-encode it, strip metadata as your policy says) and returns its id:
     * 1-64 characters of `[A-Za-z0-9_-]` (it is used in `DELETE monitoring/screenshots/{id}`). Read the stream inside
     * the call; it is closed and deleted right after.
     */
    public function storeScreenshot(ScreenshotUpload $upload): string;

    /**
     * The worker discarded the slot's screenshot before upload, in the local review window: mark the slot (nothing
     * was uploaded).
     */
    public function markDiscarded(TimeTrackUserRef $user, string $entryId, DateTimeImmutable $slot): void;

    /**
     * The worker discards an uploaded screenshot of their own: soft delete it (purge within 24 h). False when the id is
     * unknown or not the worker's (the client gets a 404).
     */
    public function discard(TimeTrackUserRef $user, string $screenshotId): bool;

    /**
     * Stores checked activity aggregates of the worker.
     *
     * @param list<ActivityItem> $items
     */
    public function storeActivity(TimeTrackUserRef $user, array $items): void;

    /**
     * Whether the worker already has a screenshot (or a discard marker) for this slot, **whatever the entry**: the key
     * is (user, slot) — the same worked minute has a timer id and later an entry id (audit PM-M2). Since then the bundle
     * always passes `''` as `$entryId` (kept for BC only): ignore it. Keep a unique key on (user, slot) in your store.
     */
    public function hasScreenshot(TimeTrackUserRef $user, string $entryId, DateTimeImmutable $slot): bool;
}
