<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Calendar;

use Nowo\TimeTrackBundle\Dto\TaskReference;
use Nowo\TimeTrackBundle\ValueObject\TimeTrackUserRef;

/**
 * Since 1.23.0: framework-free twin of {@see TaskKeyResolverInterface} (REQ-ARCH-001 rule 3), same
 * `calendar.task_key_resolver` option. Same rules for titles (in memory only, linear-time matching).
 */
interface TaskKeyLookupInterface
{
    /**
     * The task keys in an event title, in title order: at most 5, each at most 32 characters (the bundle drops the rest).
     *
     * @return list<string>
     */
    public function extractKeys(string $title): array;

    /**
     * The tasks the keys mean in the user's tenant scope (key → task). Unknown keys and tasks of other scopes are left
     * out; the bundle checks separately that the user may track each task.
     *
     * @param list<string> $keys
     *
     * @return array<string, TaskReference>
     */
    public function resolveFor(TimeTrackUserRef $user, string $scopeKey, array $keys): array;
}
