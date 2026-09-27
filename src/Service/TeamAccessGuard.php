<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Service;

use Doctrine\Persistence\ManagerRegistry;
use Nowo\TimeTrackBundle\Doctrine\ManagedEntityRefresher;
use Nowo\TimeTrackBundle\Entity\TimeEntry;
use Nowo\TimeTrackBundle\Event\TimeEntryAccessCheckEvent;
use Nowo\TimeTrackBundle\Event\TimeTrackEvents;
use Nowo\TimeTrackBundle\Integration\TeamContextProviderInterface;
use Nowo\TimeTrackBundle\Support\UserIdResolver;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

use function in_array;

final readonly class TeamAccessGuard
{
    /**
     * @param list<string> $adminRoles
     */
    public function __construct(
        private TeamContextProviderInterface $teamContext,
        private EventDispatcherInterface $eventDispatcher,
        private array $adminRoles,
        private bool $managerCanViewEntries,
        private bool $managerCanEditEntries,
        private ?ManagerRegistry $managerRegistry = null,
    ) {
    }

    public function canViewUserEntries(UserInterface $viewer, string $targetUserId): bool
    {
        if (UserIdResolver::getId($viewer) === $targetUserId) {
            return true;
        }

        if ($this->hasAdminRole($viewer)) {
            return true;
        }

        if (!$this->managerCanViewEntries) {
            return false;
        }

        return in_array($targetUserId, $this->teamContext->getManagedUserIds($viewer), true);
    }

    public function canEditEntry(UserInterface $editor, TimeEntry $entry): bool
    {
        $entryUser = $entry->getUser();
        if (!$entryUser instanceof UserInterface) {
            return false;
        }

        $granted = UserIdResolver::getId($editor) === UserIdResolver::getId($entryUser);
        if (!$granted && $this->managerCanEditEntries) {
            $granted = $this->teamContext->isManagerOf($editor, $entryUser);
        }
        if (!$granted) {
            $granted = $this->hasAdminRole($editor);
        }

        $event = new TimeEntryAccessCheckEvent($editor, $entry, 'edit', $granted);
        $this->eventDispatcher->dispatch($event, TimeTrackEvents::TIME_ENTRY_ACCESS_CHECK);

        return $event->isGranted();
    }

    private function hasAdminRole(UserInterface $user): bool
    {
        if ($this->adminRoles === []) {
            return false;
        }

        ManagedEntityRefresher::refresh($this->managerRegistry, $user);

        return (bool) array_intersect($this->adminRoles, $user->getRoles());
    }
}
