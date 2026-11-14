<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Repository;

use DateTimeImmutable;
use Nowo\TimeTrackBundle\Entity\MonitoringUploadIntent;

/**
 * Since 1.26.0 (contract §24.3): store of the screenshot upload intents. Every state change is one conditional
 * statement, so two requests never both consume a signed URL. The rows it returns are fresh reads, not kept managed.
 *
 * @internal
 */
interface MonitoringUploadIntentRepositoryInterface
{
    /**
     * Persists and flushes a new intent.
     */
    public function save(MonitoringUploadIntent $intent): void;

    /**
     * The intent with this id of this user (fresh read), or null.
     */
    public function findForUser(string $id, string $userId): ?MonitoringUploadIntent;

    /**
     * The user's intent with this hashed `Idempotency-Key` (fresh read), or null.
     */
    public function findByKey(string $userId, string $idempotencyHash): ?MonitoringUploadIntent;

    /**
     * Whether another intent of the user holds the slot (whatever its entry id, audit PM-M2): stored, uploading, or
     * pending with a URL still valid at `$now`.
     */
    public function slotTaken(string $userId, DateTimeImmutable $slot, string $exceptId, DateTimeImmutable $now): bool;

    /**
     * Records the expiry of a new URL of a pending or spent intent (or of an upload stuck since before `$staleBefore`),
     * which becomes pending; false when the intent is stored or uploading.
     */
    public function sign(string $id, DateTimeImmutable $until, DateTimeImmutable $staleBefore): bool;

    /**
     * Consumes the URL that expires at `$signedUntil` (pending → uploading) while it is still valid at `$now`; true only
     * for the one call that won.
     */
    public function consume(string $id, DateTimeImmutable $signedUntil, DateTimeImmutable $now): bool;

    /**
     * A failed upload: uploading → spent (its URL is dead; a replay of the intent signs one that expires later).
     */
    public function release(string $id): void;

    /**
     * A stored upload: uploading → stored, with the host's screenshot id.
     */
    public function markStored(string $id, string $screenshotId): void;

    /**
     * The host refused the evidence for good: uploading → rejected.
     */
    public function markRejected(string $id): void;

    /**
     * Deletes the intents created before `$before` (of one user, or of everyone); returns how many.
     */
    public function purgeCreatedBefore(DateTimeImmutable $before, ?string $userId = null): int;
}
