<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Tests\Support;

use Nowo\TimeTrackBundle\Subject\ActivityType;
use Nowo\TimeTrackBundle\Subject\ActivityTypeList;
use Nowo\TimeTrackBundle\Subject\ActivityTypeProviderInterface;
use RuntimeException;

/**
 * Since 2.1.0: test activity type provider. Every scope gets `dev` (billable default true), `support` (billable default
 * false), `meeting` (no default) and the retired `legacy`; `$perScope` overrides a scope's list. Records the scopes asked.
 */
final class FixedActivityTypeProvider implements ActivityTypeProviderInterface
{
    /** @var array<string, list<ActivityType>> scope key => types (overrides the defaults) */
    public array $perScope = [];

    /** @var list<string> scopes asked, in order */
    public array $asked = [];

    /** @var bool whether activityTypesFor() throws */
    public bool $fail = false;

    /**
     * The activity types of the scope.
     */
    public function activityTypesFor(string $scopeKey): ActivityTypeList
    {
        $this->asked[] = $scopeKey;
        if ($this->fail) {
            throw new RuntimeException('activity store down');
        }

        return ActivityTypeList::of($this->perScope[$scopeKey] ?? self::defaults());
    }

    /**
     * Resets the overrides and the record.
     */
    public function reset(): void
    {
        $this->perScope = [];
        $this->asked    = [];
        $this->fail     = false;
    }

    /**
     * The default list.
     *
     * @return list<ActivityType>
     */
    public static function defaults(): array
    {
        return [
            new ActivityType('dev', 'Development', true, true, 'Build'),
            new ActivityType('support', 'Support', false),
            new ActivityType('meeting', 'Meeting'),
            new ActivityType('legacy', 'Legacy work', true, false),
        ];
    }
}
