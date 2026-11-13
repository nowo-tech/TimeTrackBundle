<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Entity;

use App\Entity\User;
use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;
use Nowo\TimeTrackBundle\Support\StorageTime;
use Nowo\TimeTrackBundle\Timesheet\ScopeKeys;
use Nowo\TimeTrackBundle\ValueObject\Uuid;

use const DATE_ATOM;

/**
 * Since 1.14: a user-declared rule for calendar events without a task key (contract §3.7, Q-O6): a case-insensitive
 * **literal** substring of the title (no regex: no ReDoS, no over-matching) and the task it means. Opt-in, owner only.
 */
#[ORM\Entity]
#[ORM\Table(name: 'time_track_calendar_rules')]
#[ORM\Index(name: 'idx_time_track_calendar_rules_user', columns: ['user_id', 'scope_key'])]
class CalendarRule
{
    /** Longest pattern, in code points. */
    public const PATTERN_MAX_LENGTH = 120;

    #[ORM\Id]
    #[ORM\Column(type: 'string', length: 36)]
    private string $id;

    #[ORM\Column(name: 'created_at', type: 'datetime_immutable')]
    private DateTimeImmutable $createdAt;

    /**
     * Creates the rule (the rule service validates the pattern and the task).
     */
    public function __construct(
        #[ORM\ManyToOne(targetEntity: User::class)]
        #[ORM\JoinColumn(name: 'user_id', nullable: false, onDelete: 'CASCADE')]
        private object $user,
        #[ORM\Column(name: 'scope_key', type: 'string', length: 64)]
        private string $scopeKey,
        #[ORM\Column(type: 'string', length: 120)]
        private string $pattern,
        #[ORM\Column(name: 'task_id', type: 'string', length: 36)]
        private string $taskId,
        #[ORM\Column(name: 'group_matches', type: 'boolean', options: ['default' => true])]
        private bool $groupMatches,
        DateTimeImmutable $now,
    ) {
        // Since 1.24.1 (REQ-SEC-006): a scope key a look-alike could stand for is refused, never trimmed.
        ScopeKeys::assertUsable($this->scopeKey);
        $this->id        = Uuid::generateV7()->toString();
        $this->createdAt = StorageTime::normalize($now);
    }

    /** Row id. */
    public function getId(): string
    {
        return $this->id;
    }

    /** The owner. */
    public function getUser(): object
    {
        return $this->user;
    }

    /** Tenant scope. */
    public function getScopeKey(): string
    {
        return $this->scopeKey;
    }

    /** Literal pattern. */
    public function getPattern(): string
    {
        return $this->pattern;
    }

    /** Task the matching events mean. */
    public function getTaskId(): string
    {
        return $this->taskId;
    }

    /** Whether matches are grouped per ISO week. */
    public function groupsMatches(): bool
    {
        return $this->groupMatches;
    }

    /** Creation time. */
    public function getCreatedAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }

    /**
     * Owner-facing shape.
     *
     * @return array{id: string, scopeKey: string, pattern: string, taskId: string, groupMatches: bool, createdAt: string}
     */
    public function toArray(): array
    {
        return [
            'id'           => $this->id,
            'scopeKey'     => $this->scopeKey,
            'pattern'      => $this->pattern,
            'taskId'       => $this->taskId,
            'groupMatches' => $this->groupMatches,
            'createdAt'    => $this->createdAt->format(DATE_ATOM),
        ];
    }
}
