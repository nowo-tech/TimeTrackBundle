<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Repository;

use LogicException;

use function sprintf;

/**
 * Since 1.12: the service behind {@see ImportedTimeEntryRepositoryInterface} is whatever
 * {@see TimeEntryRepositoryInterface} resolves to (the Doctrine store, a host replacement or a host decorator), so
 * imported-entry reads never bypass a replaced or decorated store. It fails closed when that store does not hold
 * imported entries.
 */
final class ImportedTimeEntryRepositoryResolver
{
    /**
     * The configured time entry store, when it implements {@see ImportedTimeEntryRepositoryInterface}.
     *
     * @throws LogicException when the configured store does not implement the interface
     */
    public static function resolve(TimeEntryRepositoryInterface $store): ImportedTimeEntryRepositoryInterface
    {
        if (!$store instanceof ImportedTimeEntryRepositoryInterface) {
            throw new LogicException(sprintf('The configured TimeEntryRepositoryInterface (%s) does not implement ImportedTimeEntryRepositoryInterface: imported time entries are not available. Implement it on your store or decorator, or alias ImportedTimeEntryRepositoryInterface yourself.', $store::class));
        }

        return $store;
    }

    /**
     * Since 1.23.0: the configured time entry store, when it implements the framework-free
     * {@see ImportedTimeEntryStoreInterface} (the deprecated {@see ImportedTimeEntryRepositoryInterface} extends it).
     *
     * @throws LogicException when the configured store does not implement the interface
     */
    public static function resolveStore(TimeEntryRepositoryInterface $store): ImportedTimeEntryStoreInterface
    {
        if (!$store instanceof ImportedTimeEntryStoreInterface) {
            throw new LogicException(sprintf('The configured TimeEntryRepositoryInterface (%s) does not implement ImportedTimeEntryStoreInterface: imported time entries are not available. Implement it on your store or decorator, or alias ImportedTimeEntryStoreInterface yourself.', $store::class));
        }

        return $store;
    }
}
