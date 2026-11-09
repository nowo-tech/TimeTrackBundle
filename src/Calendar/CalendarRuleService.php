<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Calendar;

use Nowo\TimeTrackBundle\Entity\CalendarRule;
use Nowo\TimeTrackBundle\Integration\PassThroughUserReferenceResolver;
use Nowo\TimeTrackBundle\Integration\UserReferenceResolverInterface;
use Nowo\TimeTrackBundle\Repository\CalendarRuleRepositoryInterface;
use Nowo\TimeTrackBundle\Security\TaskAccessCheckerInterface;
use Nowo\TimeTrackBundle\Support\UserIdResolver;
use Nowo\TimeTrackBundle\Timesheet\TimesheetScope;
use Psr\Clock\ClockInterface;
use Symfony\Component\Clock\NativeClock;
use Symfony\Component\Security\Core\User\UserInterface;

use function count;
use function mb_strlen;
use function preg_match;
use function preg_replace;
use function trim;

/**
 * Since 1.14 (contract §3.7, Q-O6): the user's rules for events without a task key — a literal, case-insensitive
 * pattern (no regex) and a task the user can track (checked at save and again at match time), at most
 * `calendar.max_rules_per_user` per scope. Owner only.
 */
final readonly class CalendarRuleService
{
    private UserReferenceResolverInterface $userReferences;

    private ClockInterface $clock;

    /**
     * Receives the store, the connection service (scopes), the track check and the settings.
     */
    public function __construct(
        private CalendarRuleRepositoryInterface $rules,
        private CalendarConnectionService $connections,
        private TaskAccessCheckerInterface $taskAccess,
        private CalendarSettings $settings,
        ?ClockInterface $clock = null,
        ?UserReferenceResolverInterface $userReferences = null,
    ) {
        $this->clock          = $clock ?? new NativeClock();
        $this->userReferences = $userReferences ?? new PassThroughUserReferenceResolver();
    }

    /**
     * Adds a rule.
     *
     * @throws CalendarException `calendar_disabled`, `invalid_scope`, `invalid_pattern`, `not_trackable`, `too_many_rules`
     */
    public function create(UserInterface $user, string $scopeKey, string $pattern, string $taskId, bool $groupMatches = true): CalendarRule
    {
        if (!$this->connections->isEnabled()) {
            throw new CalendarException('calendar_disabled');
        }
        if (!$this->connections->scopeOf($user, $scopeKey) instanceof TimesheetScope) {
            throw new CalendarException('invalid_scope');
        }
        // Whitespace runs are folded; control characters are refused (they never appear in a title the user typed).
        $pattern = trim((string) preg_replace('/\s+/u', ' ', $pattern));
        if ($pattern === '' || mb_strlen($pattern) > CalendarRule::PATTERN_MAX_LENGTH || preg_match('/[\p{Cc}\p{Cf}]/u', $pattern) !== 0) {
            throw new CalendarException('invalid_pattern');
        }
        if ($taskId === '' || mb_strlen($taskId) > 36 || !$this->taskAccess->canTrack($user, $taskId)) {
            throw new CalendarException('not_trackable');
        }
        if (count($this->rules->findRulesByUser(UserIdResolver::getId($user), $scopeKey)) >= $this->settings->maxRulesPerUser) {
            throw new CalendarException('too_many_rules');
        }
        $rule = new CalendarRule($this->userReferences->reference($user), $scopeKey, $pattern, $taskId, $groupMatches, $this->clock->now());
        $this->rules->saveRule($rule);

        return $rule;
    }

    /**
     * The user's rules in a scope, oldest first.
     *
     * @return list<CalendarRule>
     */
    public function rulesOf(UserInterface $user, string $scopeKey): array
    {
        return $this->connections->scopeOf($user, $scopeKey) instanceof TimesheetScope ? $this->rules->findRulesByUser(UserIdResolver::getId($user), $scopeKey) : [];
    }

    /**
     * Deletes one of the user's rules; false when it is not the user's.
     */
    public function delete(UserInterface $user, string $ruleId): bool
    {
        $rule  = $this->connections->isEnabled() && mb_strlen($ruleId) <= 36 ? $this->rules->findRule($ruleId) : null;
        $owner = $rule?->getUser();
        if (!$rule instanceof CalendarRule || !$owner instanceof UserInterface || UserIdResolver::getId($owner) !== UserIdResolver::getId($user)) {
            return false;
        }
        // @igor-ignore - Service coordinates I/O or request-scoped work; not unsafe worker singleton state.
        $this->rules->removeRule($rule);

        return true;
    }
}
