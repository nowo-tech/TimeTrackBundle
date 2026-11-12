<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Port;

use DateTimeImmutable;
use LogicException;
use Nowo\TimeTrackBundle\Repository\EntryOwnerRefsRepositoryInterface;
use Nowo\TimeTrackBundle\Repository\EntryOwnersRepositoryInterface;

/**
 * Asks a store implementing only {@see EntryOwnerRefsRepositoryInterface} (1.23.0) where the auto-lock asks the
 * deprecated {@see EntryOwnersRepositoryInterface}: each reference must carry the security user (`LogicException`
 * otherwise, before anything is locked). Built by {@see Ports}; stateless.
 *
 * @internal
 */
final readonly class EntryOwnerRefsGate implements EntryOwnersRepositoryInterface
{
    /**
     * Wraps the host store.
     */
    public function __construct(private EntryOwnerRefsRepositoryInterface $store)
    {
    }

    /**
     * The store's owners, as the security users their references carry.
     *
     * @throws LogicException when a reference carries no security user
     */
    public function ownersWithEntries(DateTimeImmutable $from, DateTimeImmutable $to): array
    {
        $owners = [];
        foreach ($this->store->ownerRefsWithEntries($from, $to) as ['user' => $ref, 'scopeKey' => $scopeKey]) {
            $owners[] = ['user' => TimeTrackUserRefs::securityUser($ref, EntryOwnerRefsRepositoryInterface::class . '::ownerRefsWithEntries()'), 'scopeKey' => $scopeKey];
        }

        return $owners;
    }
}
