<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Access;

use Nowo\TimeTrackBundle\Doctrine\DoctrineUserReferenceResolver;
use Nowo\TimeTrackBundle\Dto\TimeEntryView;
use Nowo\TimeTrackBundle\Entity\TimeEntry;
use Nowo\TimeTrackBundle\Repository\TimeEntryRepositoryInterface;
use Nowo\TimeTrackBundle\Service\TeamAccessGuard;
use Nowo\TimeTrackBundle\ValueObject\TimeTrackUserRef;
use Symfony\Component\Security\Core\User\UserInterface;

use function is_int;

/**
 * Since 1.37.0 (DDD-host checklist B1/B8): {@see TimeEntryAccessInterface} on the bundle's entry store (the private
 * entity manager with `database.private_entity_manager: true`) and {@see TeamAccessGuard}. The entry is read by its
 * exact id ({@see ExactTimeEntryLookup}) at every call; nothing is kept (FrankenPHP worker mode, kernel reset false).
 *
 * @internal Hosts depend on the interface
 */
final readonly class TimeEntryAccess implements TimeEntryAccessInterface
{
    /**
     * @param string $userClass the configured `user_class`, the class of a reference built from a bare id
     */
    public function __construct(
        private TimeEntryRepositoryInterface $entries,
        private TeamAccessGuard $guard,
        private string $userClass,
    ) {
    }

    public function canViewEntry(TimeTrackUserRef|string|int $user, string $entryId): bool
    {
        return $this->viewable($user, $entryId) instanceof TimeEntry;
    }

    public function canEditEntry(TimeTrackUserRef|string|int $user, string $entryId): bool
    {
        $actor = $this->actor($user);
        $entry = $actor instanceof UserInterface ? ExactTimeEntryLookup::find($this->entries, $entryId) : null;

        return $actor instanceof UserInterface && $entry instanceof TimeEntry && $this->guard->canEditEntry($actor, $entry);
    }

    public function viewableEntry(TimeTrackUserRef|string|int $user, string $entryId): ?TimeEntryView
    {
        $entry = $this->viewable($user, $entryId);

        return $entry instanceof TimeEntry ? TimeEntryView::fromEntry($entry) : null;
    }

    /**
     * The entry when the user may see it, else null.
     */
    private function viewable(TimeTrackUserRef|string|int $user, string $entryId): ?TimeEntry
    {
        $actor = $this->actor($user);
        $entry = $actor instanceof UserInterface ? ExactTimeEntryLookup::find($this->entries, $entryId) : null;

        return $actor instanceof UserInterface && $entry instanceof TimeEntry && $this->guard->canViewEntry($actor, $entry) ? $entry : null;
    }

    /**
     * The user the rules check: the security user a reference carries, else an {@see IdActor} of its id (null for an
     * unusable id: empty, padded, too long, with control or invisible characters; such an id owns nothing).
     */
    private function actor(TimeTrackUserRef|string|int $user): ?UserInterface
    {
        if ($user instanceof TimeTrackUserRef) {
            $object = $user->user();
            if ($object instanceof UserInterface) {
                return $object;
            }
            $ref = $user;
        } else {
            $id = is_int($user) ? (string) $user : $user;
            if ($id === '' || !DoctrineUserReferenceResolver::isExactId($id)) {
                return null;
            }
            $ref = TimeTrackUserRef::ofId($this->userClass, $id);
        }

        return $ref->id !== null && DoctrineUserReferenceResolver::isExactId($ref->id) ? new IdActor($ref) : null;
    }
}
