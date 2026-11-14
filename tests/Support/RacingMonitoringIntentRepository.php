<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Tests\Support;

use DateTimeImmutable;
use Doctrine\DBAL\Driver\AbstractException;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Nowo\TimeTrackBundle\Entity\MonitoringUploadIntent;
use Nowo\TimeTrackBundle\Repository\MonitoringUploadIntentRepositoryInterface;
use RuntimeException;

/**
 * Decorates the intent store of the functional tests (pass-through by default): `raceNextSave` makes the next save
 * lose a race to a concurrent request with the same key (the same body, or `$raceBody` when set); `failRelease`
 * makes release() throw.
 */
final class RacingMonitoringIntentRepository implements MonitoringUploadIntentRepositoryInterface
{
    public bool $raceNextSave = false;

    public ?string $raceBody = null;

    public bool $failRelease = false;

    public bool $failFind = false;

    public bool $failMarkStored = false;

    public function __construct(private readonly MonitoringUploadIntentRepositoryInterface $inner)
    {
    }

    public function save(MonitoringUploadIntent $intent): void
    {
        if (!$this->raceNextSave) {
            $this->inner->save($intent);

            return;
        }
        $this->raceNextSave = false;
        $winner             = new MonitoringUploadIntent($intent->getUser(), $intent->getIdempotencyHash(), $this->raceBody ?? $intent->getBodyHash(), $intent->getEntryId(), $intent->getSlot(), $intent->getTakenAt(), $intent->getPolicyVersion(), $intent->isBlurred(), $intent->getMime(), $intent->getBytes(), $intent->getWidth(), $intent->getHeight(), $intent->getSha256(), $intent->getCreatedAt());
        $until              = $intent->getSignedUntil();
        $this->inner->save($until instanceof DateTimeImmutable ? $winner->signUntil($until) : $winner);

        throw new UniqueConstraintViolationException(new class('duplicate key') extends AbstractException {}, null);
    }

    public function findForUser(string $id, string $userId): ?MonitoringUploadIntent
    {
        if ($this->failFind) {
            throw new RuntimeException('intent store down');
        }

        return $this->inner->findForUser($id, $userId);
    }

    public function findByKey(string $userId, string $idempotencyHash): ?MonitoringUploadIntent
    {
        return $this->inner->findByKey($userId, $idempotencyHash);
    }

    public function slotTaken(string $userId, DateTimeImmutable $slot, string $exceptId, DateTimeImmutable $now): bool
    {
        return $this->inner->slotTaken($userId, $slot, $exceptId, $now);
    }

    public function markRejected(string $id): void
    {
        $this->inner->markRejected($id);
    }

    public function sign(string $id, DateTimeImmutable $until, DateTimeImmutable $staleBefore): bool
    {
        return $this->inner->sign($id, $until, $staleBefore);
    }

    public function consume(string $id, DateTimeImmutable $signedUntil, DateTimeImmutable $now): bool
    {
        return $this->inner->consume($id, $signedUntil, $now);
    }

    public function release(string $id): void
    {
        if ($this->failRelease) {
            throw new RuntimeException('intent store down');
        }
        $this->inner->release($id);
    }

    public function markStored(string $id, string $screenshotId): void
    {
        if ($this->failMarkStored) {
            throw new RuntimeException('intent store down');
        }
        $this->inner->markStored($id, $screenshotId);
    }

    public function purgeCreatedBefore(DateTimeImmutable $before, ?string $userId = null): int
    {
        return $this->inner->purgeCreatedBefore($before, $userId);
    }
}
