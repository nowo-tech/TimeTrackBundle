<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Import;

use LogicException;
use Nowo\TimeTrackBundle\Port\TimeTrackUserRefs;
use Nowo\TimeTrackBundle\Retention\FormerPersonTimeEntries;
use Nowo\TimeTrackBundle\ValueObject\TimeTrackUserRef;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Since 1.15: an upsert row saying "this user is now the person of that reference". A plain user row never takes an
 * ownerless entry: the claim has to name the reference the entry holds, and the scope has to match, so one tenant cannot
 * take another's former person through a crafted external id. On an entry the user already owns, or a new one, it acts
 * as a plain user row, so a sink can always send it for a mapped person.
 */
final readonly class ImportedTimeEntryClaim
{
    /**
     * @param UserInterface $user the new owner
     * @param string $personRef the reference the entry must hold ({@see ImportedTimeEntryPerson::$ref}, or {@see FormerPersonTimeEntries::personRefOf()})
     * @param string|null $scopeKey the claimer's tenant scope, used when `timesheets.scope_provider` gives none. It must equal the entry's: a claim with a scope is refused on an entry with none
     */
    public function __construct(
        public UserInterface $user,
        public string $personRef,
        public ?string $scopeKey = null,
    ) {
    }

    /**
     * Since 1.23.0: the claim of a user reference (REQ-ARCH-001 rule 3). The reference must carry the security user
     * ({@see TimeTrackUserRef::of()}): the entry stores it.
     *
     * @throws LogicException When the reference carries no security user object
     */
    public static function ofRef(TimeTrackUserRef $user, string $personRef, ?string $scopeKey = null): self
    {
        return new self(TimeTrackUserRefs::securityUser($user, self::class . '::ofRef()'), $personRef, $scopeKey);
    }

    /**
     * Since 1.23.0: framework-free reference of {@see self::$user}. Built per call.
     */
    public function userRef(): TimeTrackUserRef
    {
        return TimeTrackUserRefs::fromUser($this->user);
    }
}
