<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Repository;

use Nowo\TimeTrackBundle\Enum\TimeEntrySource;
use Nowo\TimeTrackBundle\Import\ImportedTimeEntryWriter;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Since 1.11: a time entry store that also holds imported entries ({@see TimeEntrySource::Import}).
 * Required by {@see ImportedTimeEntryWriter}; the Doctrine store implements it. Since 1.23.0 its methods other than
 * {@see self::attachUser()} live in {@see ImportedTimeEntryStoreInterface}.
 *
 * @deprecated since 1.23.0, implement {@see ImportedTimeEntryStoreInterface} instead (framework-free, REQ-ARCH-001 rule 3); removed in 2.0.
 */
interface ImportedTimeEntryRepositoryInterface extends ImportedTimeEntryStoreInterface
{
    /**
     * The user as an object new entries can reference after the store was reset (the Doctrine store: the managed
     * instance, or a reference by identifier, since a reset detaches the objects the caller passed). Returns the user
     * unchanged when it is already managed or is not an entity of this store.
     */
    public function attachUser(UserInterface $user): UserInterface;
}
