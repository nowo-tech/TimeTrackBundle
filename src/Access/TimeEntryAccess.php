<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Access;

use Nowo\TimeTrackBundle\Doctrine\DoctrineUserReferenceResolver;
use Nowo\TimeTrackBundle\Dto\TimeEntryView;
use Nowo\TimeTrackBundle\Entity\TimeEntry;
use Nowo\TimeTrackBundle\Repository\TimeEntryRepositoryInterface;
use Nowo\TimeTrackBundle\Service\TeamAccessGuard;
use Nowo\TimeTrackBundle\Support\UserIdResolver;
use Nowo\TimeTrackBundle\ValueObject\TimeTrackUserRef;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\Security\Core\User\UserInterface;
use Throwable;

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
    private const VIEW = 'view';

    private const EDIT = 'edit';

    /**
     * @param string $userClass the configured `user_class`, the class of a reference built from a bare id
     * @param LoggerInterface $logger Since 1.37.0 (audit YO-L2): a failing guard, port or listener is logged (class only)
     *                                and answered as a refusal
     */
    public function __construct(
        private TimeEntryRepositoryInterface $entries,
        private TeamAccessGuard $guard,
        private string $userClass,
        private LoggerInterface $logger = new NullLogger(),
    ) {
    }

    public function canViewEntry(TimeTrackUserRef|string|int $user, string $entryId): bool
    {
        return $this->reachable($user, $entryId, self::VIEW) instanceof TimeEntry;
    }

    public function canEditEntry(TimeTrackUserRef|string|int $user, string $entryId): bool
    {
        return $this->reachable($user, $entryId, self::EDIT) instanceof TimeEntry;
    }

    public function viewableEntry(TimeTrackUserRef|string|int $user, string $entryId): ?TimeEntryView
    {
        $entry = $this->reachable($user, $entryId, self::VIEW);

        return $entry instanceof TimeEntry ? TimeEntryView::fromEntry($entry) : null;
    }

    /**
     * The entry when the user may see it (`view`) or change it (`edit`), else null. Audit YO-L2: an exception of the
     * store, a host port or a listener is logged (its class only) and answered as the same refusal, so a failure never
     * tells an existing entry from an unknown one.
     */
    private function reachable(TimeTrackUserRef|string|int $user, string $entryId, string $action): ?TimeEntry
    {
        try {
            $actor = $this->actor($user);
            $entry = $actor instanceof UserInterface ? ExactTimeEntryLookup::find($this->entries, $entryId) : null;
            if (!$actor instanceof UserInterface || !$entry instanceof TimeEntry) {
                return null;
            }

            return ($action === self::EDIT ? $this->guard->canEditEntry($actor, $entry) : $this->guard->canViewEntry($actor, $entry)) ? $entry : null;
        } catch (Throwable $exception) {
            $this->logger->warning('TimeTrack entry access check failed; answered as a refusal.', ['bundle' => 'time_track', 'action' => 'entry_access_' . $action, 'exception' => $exception::class]);

            return null;
        }
    }

    /**
     * The user the rules check: the security user a reference carries, else an {@see IdActor} of its id (null for an
     * unusable id: empty, padded, too long, with control or invisible characters; such an id owns nothing). Audit
     * YO-L1: a reference whose object has another id than the reference's (`UserIdResolver::idOf()`, compared with
     * `===`) is refused, whatever the object's kind.
     */
    private function actor(TimeTrackUserRef|string|int $user): ?UserInterface
    {
        if ($user instanceof TimeTrackUserRef) {
            $object = $user->user();
            if ($object !== null && UserIdResolver::idOf($object) !== $user->id) {
                return null;
            }
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
