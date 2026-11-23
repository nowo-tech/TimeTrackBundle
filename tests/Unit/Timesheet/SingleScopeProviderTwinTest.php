<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Tests\Unit\Timesheet;

use DateTimeImmutable;
use Nowo\TimeTrackBundle\Dto\TimeEntryView;
use Nowo\TimeTrackBundle\Enum\TimeEntrySource;
use Nowo\TimeTrackBundle\Timesheet\SingleScopeProvider;
use Nowo\TimeTrackBundle\Timesheet\TimesheetScope;
use Nowo\TimeTrackBundle\ValueObject\TimeTrackUserRef;
use PHPUnit\Framework\TestCase;

/**
 * 2.0: the default single scope answers the framework-free port (`TimesheetScopePolicyInterface` service id) as it
 * answers the bundle's internal shape.
 */
final class SingleScopeProviderTwinTest extends TestCase
{
    public function testTheTwinAnswersTheSingleScope(): void
    {
        $provider = new SingleScopeProvider('Company', 'Europe/Madrid');
        $user     = TimeTrackUserRef::ofId('App\User', '7');
        $view     = new TimeEntryView('e-1', '7', 'task-1', 'T', null, null, new DateTimeImmutable('2026-10-05 09:00:00'), new DateTimeImmutable('2026-10-05 10:00:00'), 3600, TimeEntrySource::Web, null, null);

        self::assertEquals([new TimesheetScope(SingleScopeProvider::KEY, 'Company', 'Europe/Madrid')], $provider->scopesOf($user));
        self::assertSame(SingleScopeProvider::KEY, $provider->scopeKeyForUserTask($user, 'task-1', 'board-1'));
        self::assertSame(['e-1' => SingleScopeProvider::KEY], $provider->scopeKeysForEntryViews([$view]));
    }
}
