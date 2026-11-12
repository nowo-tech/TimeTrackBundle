<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Event;

use DateTimeImmutable;
use Nowo\TimeTrackBundle\Enum\ClientType;
use Nowo\TimeTrackBundle\Enum\IdleFollowUp;
use Nowo\TimeTrackBundle\Enum\IdleResolution;
use Nowo\TimeTrackBundle\Port\TimeTrackUserRefs;
use Nowo\TimeTrackBundle\Support\NoteSanitizer;
use Nowo\TimeTrackBundle\Support\UserIdResolver;
use Nowo\TimeTrackBundle\ValueObject\TimeTrackUserRef;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Contracts\EventDispatcher\Event;

/**
 * Dispatched after the user's decision about an idle span was applied (`POST timer/discard-idle`, since 1.10). Hosts
 * may write it to their own audit log: it changes working-time records. The server never receives an activity signal,
 * only this decision.
 */
final class IdleResolvedEvent extends Event
{
    /**
     * @param string|null $targetTaskId Task the span was given to (`reassign` only)
     * @param IdleFollowUp|null $followUp What runs after the span (`reassign` only)
     * @param string|null $note Note sent with the decision (`keep`: added to the running timer, 1.10.1; `reassign`: stored on the reassigned entry)
     */
    public function __construct(
        private readonly UserInterface $user,
        private readonly IdleResolution $resolution,
        private readonly DateTimeImmutable $from,
        private readonly DateTimeImmutable $to,
        private readonly ClientType $clientType,
        private readonly ?string $targetTaskId = null,
        private readonly ?IdleFollowUp $followUp = null,
        private readonly ?string $note = null,
    ) {
    }

    /**
     * Timer owner.
     *
     * @deprecated since 1.23.0, use {@see self::userRef()} (framework-free, REQ-ARCH-001 rule 3); removed in 2.0.
     */
    public function getUser(): UserInterface
    {
        return $this->user;
    }

    /**
     * Since 1.23.0: framework-free reference of {@see self::getUser()} (REQ-ARCH-001 rule 3): class, id, identifier and
     * roles; the object itself through `userRef()->user()`. Built per call: compare with `isSameUserAs()`.
     */
    public function userRef(): TimeTrackUserRef
    {
        return TimeTrackUserRefs::fromUser($this->user);
    }

    /**
     * The user's decision.
     */
    public function getResolution(): IdleResolution
    {
        return $this->resolution;
    }

    /**
     * Idle span start.
     */
    public function getFrom(): DateTimeImmutable
    {
        return $this->from;
    }

    /**
     * Idle span end.
     */
    public function getTo(): DateTimeImmutable
    {
        return $this->to;
    }

    /**
     * Client that sent the decision.
     */
    public function getClientType(): ClientType
    {
        return $this->clientType;
    }

    /**
     * Task the span was reassigned to (null unless `reassign`).
     */
    public function getTargetTaskId(): ?string
    {
        return $this->targetTaskId;
    }

    /**
     * What runs after the span (null unless `reassign`).
     */
    public function getFollowUp(): ?IdleFollowUp
    {
        return $this->followUp;
    }

    /**
     * Note sent with the decision (null when none).
     *
     * **Raw user text** (EF2-I5): cleaned by {@see NoteSanitizer} (no control or bidi
     * characters, at most 500 code points) but not escaped. A listener that renders or stores it (an audit page, HTML,
     * CSV, a log) must escape it for that context, and should not write it to logs at all.
     */
    public function getNote(): ?string
    {
        return $this->note;
    }

    /** Since 1.18: id of {@see self::getUser()}, the acting user, as a string (`getId()`). */
    public function getUserId(): string
    {
        return UserIdResolver::getId($this->user);
    }
}
