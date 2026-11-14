<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Tests\Stub;

use DateTimeImmutable;
use Nowo\TimeTrackBundle\Monitoring\ActivityItem;
use Nowo\TimeTrackBundle\Monitoring\MonitoringEvidenceRejectedException;
use Nowo\TimeTrackBundle\Monitoring\MonitoringEvidenceStoreInterface;
use Nowo\TimeTrackBundle\Monitoring\ScreenshotUpload;
use Nowo\TimeTrackBundle\ValueObject\TimeTrackUserRef;
use RuntimeException;

use function count;
use function stream_get_contents;

use const DATE_ATOM;

/**
 * In-memory host evidence store of the monitoring tests: what the bundle handed over, per user id.
 */
final class StubMonitoringEvidenceStore implements MonitoringEvidenceStoreInterface
{
    /** @var array<string, array{user: string|null, entryId: string, slot: string, takenAt: string, policyVersion: int, blurred: bool, mime: string, bytes: int, width: int, height: int, sha256: string, content: string}> */
    public array $screenshots = [];

    /** @var list<array{user: string|null, entryId: string, slot: string}> */
    public array $discarded = [];

    /** @var list<array{user: string|null, items: list<ActivityItem>}> */
    public array $activity = [];

    /** @var list<array{user: string|null, id: string}> */
    public array $deleted = [];

    public ?string $failWith = null;

    /** Thrown by the store methods (not by the reads): the host's typed rejection. */
    public ?MonitoringEvidenceRejectedException $rejectWith = null;

    /** Id returned by the next storeScreenshot() (null = shot-N). */
    public ?string $nextId = null;

    /** Answer of hasScreenshot() for any slot (null = from the stored screenshots). */
    public ?bool $taken = null;

    public function storeScreenshot(ScreenshotUpload $upload): string
    {
        $this->fail();
        $this->reject();
        $id                     = $this->nextId ?? 'shot-' . (count($this->screenshots) + 1);
        $this->screenshots[$id] = [
            'user'          => $upload->user->id,
            'entryId'       => $upload->entryId,
            'slot'          => $upload->slot->format(DATE_ATOM),
            'takenAt'       => $upload->takenAt->format(DATE_ATOM),
            'policyVersion' => $upload->policyVersion,
            'blurred'       => $upload->blurred,
            'mime'          => $upload->mime,
            'bytes'         => $upload->bytes,
            'width'         => $upload->width,
            'height'        => $upload->height,
            'sha256'        => $upload->sha256,
            'content'       => (string) stream_get_contents($upload->stream()),
        ];

        return $id;
    }

    public function markDiscarded(TimeTrackUserRef $user, string $entryId, DateTimeImmutable $slot): void
    {
        $this->fail();
        $this->reject();
        $this->discarded[] = ['user' => $user->id, 'entryId' => $entryId, 'slot' => $slot->format(DATE_ATOM)];
    }

    public function discard(TimeTrackUserRef $user, string $screenshotId): bool
    {
        $this->fail();
        if (($this->screenshots[$screenshotId]['user'] ?? null) !== $user->id) {
            return false;
        }
        unset($this->screenshots[$screenshotId]);
        $this->deleted[] = ['user' => $user->id, 'id' => $screenshotId];

        return true;
    }

    public function storeActivity(TimeTrackUserRef $user, array $items): void
    {
        $this->fail();
        $this->reject();
        $this->activity[] = ['user' => $user->id, 'items' => $items];
    }

    public function hasScreenshot(TimeTrackUserRef $user, string $entryId, DateTimeImmutable $slot): bool
    {
        $this->fail();
        if ($this->taken !== null) {
            return $this->taken;
        }
        foreach ($this->screenshots as $shot) {
            // The key is (user, slot), whatever the entry (audit PM-M2).
            if ($shot['user'] === $user->id && $shot['slot'] === $slot->format(DATE_ATOM)) {
                return true;
            }
        }

        return false;
    }

    private function reject(): void
    {
        if ($this->rejectWith instanceof MonitoringEvidenceRejectedException) {
            throw $this->rejectWith;
        }
    }

    private function fail(): void
    {
        if ($this->failWith !== null) {
            throw new RuntimeException($this->failWith);
        }
    }
}
