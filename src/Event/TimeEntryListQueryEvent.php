<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Event;

use DateTimeImmutable;
use Nowo\TimeTrackBundle\Port\TimeTrackUserRefs;
use Nowo\TimeTrackBundle\Support\UserIdResolver;
use Nowo\TimeTrackBundle\ValueObject\TimeTrackUserRef;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Contracts\EventDispatcher\Event;

/**
 * Lets listeners set the user ids whose entries a viewer sees in a period (team reports).
 */
final class TimeEntryListQueryEvent extends Event
{
    /** @var list<string> */
    private array $userIds = [];

    /** Since 1.11: whether imported entries are listed (true by default; false for live-time views). */
    private bool $includeImported = true;

    /** Since 1.15: whether ownerless entries are listed (false by default). */
    private bool $includeOwnerless = false;

    /** @var list<string>|null Since 1.15: scopes of the ownerless entries listed (null = every one) */
    private ?array $ownerlessScopeKeys = null;

    /**
     * Creates the query for a viewer and a period.
     */
    public function __construct(
        private readonly UserInterface $viewer,
        private readonly DateTimeImmutable $from,
        private readonly DateTimeImmutable $to,
    ) {
    }

    /**
     * User asking for the entries.
     *
     * @deprecated since 1.23.0, use {@see self::viewerRef()} (framework-free, REQ-ARCH-001 rule 3); removed in 2.0.
     */
    public function getViewer(): UserInterface
    {
        return $this->viewer;
    }

    /**
     * Since 1.23.0: framework-free reference of {@see self::getViewer()} (REQ-ARCH-001 rule 3): class, id, identifier and
     * roles; the object itself through `viewerRef()->user()`. Built per call: compare with `isSameUserAs()`.
     */
    public function viewerRef(): TimeTrackUserRef
    {
        return TimeTrackUserRefs::fromUser($this->viewer);
    }

    /**
     * Period start.
     */
    public function getFrom(): DateTimeImmutable
    {
        return $this->from;
    }

    /**
     * Period end.
     */
    public function getTo(): DateTimeImmutable
    {
        return $this->to;
    }

    /**
     * @return list<string>
     */
    public function getUserIds(): array
    {
        return $this->userIds;
    }

    /**
     * @param list<string> $userIds
     */
    public function setUserIds(array $userIds): void
    {
        // @igor-ignore - Request-scoped Event DTO; not a shared worker service.
        $this->userIds = $userIds;
    }

    /**
     * Since 1.11: whether the list includes imported entries.
     */
    public function includesImported(): bool
    {
        return $this->includeImported;
    }

    /**
     * Since 1.11: false keeps imported entries out of the list (live-time views).
     */
    public function setIncludeImported(bool $includeImported): void
    {
        // @igor-ignore - Request-scoped Event DTO; not a shared worker service.
        $this->includeImported = $includeImported;
    }

    /**
     * Since 1.15: lists the entries with no user too (a former person's, a deleted user's), for a viewer the host has
     * allowed: a listener is the only way in, so nobody sees them by default. Pass the viewer's tenant scopes: only
     * entries with one of them are listed; null lists every one, for single-tenant hosts or instance admins. An entry
     * with no scope is listed only with null.
     *
     * @param list<string>|null $scopeKeys
     */
    public function includeOwnerless(?array $scopeKeys): void
    {
        // @igor-ignore - Request-scoped Event DTO; not a shared worker service.
        $this->includeOwnerless = true;
        // @igor-ignore - Request-scoped Event DTO; not a shared worker service.
        $this->ownerlessScopeKeys = $scopeKeys;
    }

    /**
     * Since 1.15: whether the list includes ownerless entries.
     */
    public function includesOwnerless(): bool
    {
        return $this->includeOwnerless;
    }

    /**
     * Since 1.15: scopes of the ownerless entries listed (null = every one).
     *
     * @return list<string>|null
     */
    public function getOwnerlessScopeKeys(): ?array
    {
        return $this->ownerlessScopeKeys;
    }

    /** Since 1.18: id of {@see self::getViewer()} as a string (`getId()`), for listeners that keep only ids. */
    public function getViewerId(): string
    {
        return UserIdResolver::getId($this->viewer);
    }
}
