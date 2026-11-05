<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Import;

use Nowo\TimeTrackBundle\Entity\TimeEntry;
use Nowo\TimeTrackBundle\Service\TeamAccessGuard;
use Nowo\TimeTrackBundle\Support\UserIdResolver;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Since 1.11: deletes one imported entry on behalf of a user, after {@see TeamAccessGuard::canDeleteImportedEntry()}
 * (denied unless a `delete_imported` listener grants it). Bulk deletions are host calls to the writer's `remove*()`
 * after the host's own check.
 */
final readonly class ImportedTimeEntryDeleter
{
    /**
     * @param TeamAccessGuard $accessGuard decides `delete_imported`
     * @param ImportedTimeEntryWriter $writer removes the entry and fires the event
     */
    public function __construct(
        private TeamAccessGuard $accessGuard,
        private ImportedTimeEntryWriter $writer,
    ) {
    }

    /**
     * @throws AccessDeniedException when the actor may not delete it, or it is not an imported entry
     */
    public function delete(UserInterface $actor, TimeEntry $entry): void
    {
        $source = $entry->getExternalSource();
        $id     = $entry->getExternalId();
        if (!$entry->isReadOnly() || $source === null || $id === null || !$this->accessGuard->canDeleteImportedEntry($actor, $entry)) {
            throw new AccessDeniedException('This time entry cannot be deleted.');
        }

        // @igor-ignore - I/O delegate (repository / writer call); no worker state.
        $this->writer->remove($source, $id, UserIdResolver::getId($actor));
    }
}
