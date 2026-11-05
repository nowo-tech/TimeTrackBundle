<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Timesheet;

use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Since 1.13: the scope provider of `timesheets.scope_mode: single`: one scope `''` for every user, task and entry.
 */
final readonly class SingleScopeProvider implements TimesheetScopeProviderInterface
{
    /** The key of the single scope. */
    public const KEY = '';

    /**
     * @param string $label Label of the single scope
     * @param string|null $timezone Zone the periods are cut in (null = the user's working hours, then the default)
     */
    public function __construct(
        private string $label = '',
        private ?string $timezone = null,
    ) {
    }

    /**
     * The single scope.
     */
    public function scopesFor(UserInterface $user): array
    {
        return [new TimesheetScope(self::KEY, $this->label, $this->timezone)];
    }

    /**
     * Always the single scope.
     */
    public function scopeKeyForTask(UserInterface $user, string $taskId, ?string $boardId): string
    {
        return self::KEY;
    }

    /**
     * Every entry is in the single scope.
     */
    public function scopeKeysForEntries(array $entries): array
    {
        $keys = [];
        foreach ($entries as $entry) {
            $keys[$entry->getId()] = self::KEY;
        }

        return $keys;
    }
}
