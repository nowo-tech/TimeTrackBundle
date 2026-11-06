<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Repository;

use Nowo\TimeTrackBundle\Entity\CalendarRule;

/**
 * Since 1.14: store of the user calendar rules (contract §3.7).
 */
interface CalendarRuleRepositoryInterface
{
    /**
     * Persists and flushes a rule.
     */
    public function saveRule(CalendarRule $rule): void;

    /**
     * Removes a rule.
     */
    public function removeRule(CalendarRule $rule): void;

    /**
     * The rule with this id.
     */
    public function findRule(string $id): ?CalendarRule;

    /**
     * Rules of a user in a scope, oldest first.
     *
     * @return list<CalendarRule>
     */
    public function findRulesByUser(string $userId, string $scopeKey): array;

    /**
     * Rules of a user in every scope, oldest first (personal-data export).
     *
     * @return list<CalendarRule>
     */
    public function findRulesByOwner(string $userId): array;
}
