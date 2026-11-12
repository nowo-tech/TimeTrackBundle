<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Tests\Functional;

use DateTimeImmutable;
use DateTimeZone;
use Nowo\TimeTrackBundle\Tests\Stub\LeaveDaysOffProfileProvider;

use function date_default_timezone_get;

/**
 * Since 1.22 (audit NG-1): without `client_api.leave_provider` the client API is the 1.21 one, byte for byte — even when
 * the host's days-off provider returns a `leave` entry: it is dropped as an unknown kind, as in 1.21.
 */
final class LeaveDisabledClientApiTest extends FunctionalTestCase
{
    protected static function timeTrackConfig(): array
    {
        return ['client_api' => ['profile_provider' => LeaveDaysOffProfileProvider::class]];
    }

    public function testGetMeIsThe121BodyAndALeaveDayIsDropped(): void
    {
        $today = new DateTimeImmutable('today', new DateTimeZone(date_default_timezone_get()));

        $me = $this->api('GET', '/api/time-track/me', null, $this->login());
        self::assertResponseIsSuccessful();
        self::assertSame([
            'userId'       => (string) $this->user->getId(),
            'identifier'   => 'person@example.com',
            'displayName'  => null,
            'scopes'       => ['timer:read', 'timer:write', 'tasks:read', 'entries:read'],
            'workingHours' => null,
            'locale'       => null,
            'daysOff'      => [
                ['date' => $today->format('Y-m-d'), 'kind' => 'holiday', 'label' => 'Inmaculada Concepción'],
                ['date' => $today->modify('+2 days')->format('Y-m-d'), 'kind' => 'vacation', 'label' => null],
            ],
            'features'  => ['task_details' => true, 'start_backdate' => true, 'stop_backdate' => true, 'idle_reassign' => true, 'idle_keep_note' => true, 'idle_check' => true, 'workday' => false, 'timesheets' => false, 'balance' => false, 'calendar_suggestions' => false, 'manual_entries' => false, 'task_effort' => true, 'meetings' => false, 'monitoring' => false],
            'limits'    => ['backdate' => ['max_seconds' => 86400, 'clock_skew_seconds' => 120], 'idle_check' => ['threshold_minutes' => 10, 'answer_timeout_minutes' => 5, 'user_can_disable' => false]],
            'timesheet' => null,
            'meetings'  => null,
            'links'     => ['calendars' => null, 'workingTime' => null],
        ], $me);
    }
}
