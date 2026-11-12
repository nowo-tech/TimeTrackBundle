<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Tests\Functional;

use DateTimeImmutable;
use DateTimeZone;
use Nowo\TimeTrackBundle\Enum\ClientScope;
use Nowo\TimeTrackBundle\Enum\ClientType;
use Nowo\TimeTrackBundle\Service\ClientAuthService;
use Nowo\TimeTrackBundle\Tests\Stub\LeaveDaysOffProfileProvider;
use Nowo\TimeTrackBundle\Tests\Stub\StubLeaveProvider;
use Nowo\TimeTrackBundle\Tests\Stub\StubWorkdayProvider;
use Nowo\TimeTrackBundle\Tests\Support\RecordingLogger;

use function array_key_exists;
use function array_keys;
use function array_values;
use function date_default_timezone_get;
use function implode;
use function json_encode;

use const JSON_THROW_ON_ERROR;
use const JSON_UNESCAPED_UNICODE;

/**
 * Since 1.22 (Beacon spec 204 FR-029): with a `client_api.leave_provider`, `GET workday` carries `leave: {type, until}`
 * or null (medical leave as *Absent*, never its type, comment or attachment), no reminder on a leave day, and `GET me`
 * gains `features.leave` and `links.leave`. The workday actions keep their 1.21 body.
 */
final class LeaveClientApiTest extends FunctionalTestCase
{
    private const MEDICAL_SECRETS = ['medical', 'médica', 'Gripe', 'fiebre', 'sick-note', '.pdf', 'Absence'];

    protected static function timeTrackConfig(): array
    {
        return ['client_api' => [
            'workday_provider'         => StubWorkdayProvider::class,
            'leave_provider'           => StubLeaveProvider::class,
            'links_working_time_route' => 'nowo_time_track_index',
            'links_leave_route'        => 'nowo_time_track_index',
            'profile_provider'         => LeaveDaysOffProfileProvider::class,
        ]];
    }

    public function testATypedLeaveIsSentWithItsPublicTypeAndSilencesTheReminder(): void
    {
        $register           = $this->register();
        $register->reminder = 'clock_in';
        $leaves             = $this->leaves();
        $id                 = (string) $this->user->getId();
        $leaves->records    = [$id => ['type' => 'Vacaciones', 'medical' => false, 'comment' => 'Viaje a Lisboa', 'attachment' => null, 'until' => '2026-11-14']];

        $body = $this->api('GET', '/api/time-track/workday', null, $this->token());
        self::assertResponseIsSuccessful();
        self::assertSame(['status', 'date', 'timezone', 'segments', 'workedSeconds', 'breakSeconds', 'flags', 'reminder', 'allowedActions', 'leave'], array_keys($body ?? []));
        self::assertSame(['type' => 'Vacaciones', 'until' => '2026-11-14'], $body['leave'] ?? null);
        // Audit NG-3: personal data, never in a shared cache.
        $headers = $this->client->getResponse()->headers;
        self::assertSame('no-store, private', $headers->get('Cache-Control'));
        self::assertStringContainsString('Authorization', (string) $headers->get('Vary'));
        self::assertNull(array_key_exists('reminder', $body ?? []) ? $body['reminder'] : 'missing', 'FR-029: no clock reminder on a leave day');
        self::assertStringNotContainsString('Lisboa', json_encode($body, JSON_THROW_ON_ERROR), 'the host comment never reaches the client');
        self::assertSame([[$id, '2026-11-05T00:00:00+01:00']], $leaves->calls, 'asked once, by id, for the workday date in its time zone');
    }

    public function testMedicalLeaveIsAbsentWithoutTypeCommentOrAttachment(): void
    {
        $leaves          = $this->leaves();
        $leaves->records = [(string) $this->user->getId() => [
            'type'       => 'Absence – medical (justified)',
            'medical'    => true,
            'comment'    => 'Gripe con fiebre alta',
            'attachment' => 'sick-note-2026-11-05.pdf',
            'until'      => '2026-11-07',
        ]];
        $token = $this->token();

        $workday = $this->api('GET', '/api/time-track/workday', null, $token);
        self::assertResponseIsSuccessful();
        self::assertSame(['type' => 'Absent', 'until' => '2026-11-07'], $workday['leave'] ?? null);
        $me = $this->api('GET', '/api/time-track/me', null, $token);
        self::assertResponseIsSuccessful();

        foreach ([$workday, $me] as $body) {
            $json = json_encode($body, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
            foreach (self::MEDICAL_SECRETS as $secret) {
                self::assertStringNotContainsStringIgnoringCase($secret, $json, 'no medical type, comment or attachment in the client API');
            }
        }
        self::assertSame([], $this->leaveWarnings(), 'nothing is logged for a valid leave');
    }

    public function testNoLeaveIsNullAndTheRegisterReminderStays(): void
    {
        $this->register()->reminder = 'clock_in';

        $body = $this->api('GET', '/api/time-track/workday', null, $this->token());
        self::assertResponseIsSuccessful();
        self::assertArrayHasKey('leave', $body ?? []);
        self::assertNull($body['leave']);
        self::assertSame('clock_in', $body['reminder'] ?? null);
    }

    public function testAFailingProviderOrAnEndedLeaveGivesNullAndALogWithoutPersonalData(): void
    {
        $leaves          = $this->leaves();
        $id              = (string) $this->user->getId();
        $leaves->failing = [$id];
        $token           = $this->token();

        $body = $this->api('GET', '/api/time-track/workday', null, $token);
        self::assertResponseIsSuccessful('the workday API keeps working');
        self::assertNull(array_key_exists('leave', $body ?? []) ? $body['leave'] : 'missing');

        $leaves->failing = [];
        $leaves->records = [$id => ['type' => 'Vacaciones', 'medical' => false, 'comment' => '', 'attachment' => null, 'until' => '2026-11-04']];
        $body            = $this->api('GET', '/api/time-track/workday', null, $token);
        self::assertNull(array_key_exists('leave', $body ?? []) ? $body['leave'] : 'missing', 'a leave that ended the day before is no leave');

        $warnings = $this->leaveWarnings();
        self::assertSame([
            ['bundle' => 'time_track', 'action' => 'client_workday', 'reason' => 'provider failed', 'exception' => 'RuntimeException'],
            ['bundle' => 'time_track', 'action' => 'client_workday', 'reason' => 'leave ended before the workday date'],
        ], $warnings, 'reasons only: no user id, no date, no label');
        $logged = implode("\n", [...$this->logger()->messages(), ...$this->logger()->contextValues()]);
        self::assertStringNotContainsString('sick note', $logged, 'the exception message is never logged');
        self::assertStringNotContainsString('Vacaciones', $logged);
    }

    public function testMeGainsFeaturesLeaveAndTheLeaveLink(): void
    {
        $token = $this->token();
        $me    = $this->api('GET', '/api/time-track/me', null, $token);
        self::assertTrue($me['features']['leave'] ?? null);
        self::assertSame(['calendars' => null, 'workingTime' => null, 'leave' => null], $me['links'] ?? null, 'the test host is http: no link');

        $secure = $this->api('GET', '/api/time-track/me', null, $token, ['HTTPS' => 'on']);
        self::assertStringStartsWith('https://localhost/', (string) ($secure['links']['leave'] ?? ''));

        // With the provider a `leave` day is kept, always without its label (audit NG-1).
        $today = new DateTimeImmutable('today', new DateTimeZone(date_default_timezone_get()));
        self::assertSame([
            ['date' => $today->format('Y-m-d'), 'kind' => 'holiday', 'label' => 'Inmaculada Concepción'],
            ['date' => $today->modify('+1 day')->format('Y-m-d'), 'kind' => 'leave', 'label' => null],
            ['date' => $today->modify('+2 days')->format('Y-m-d'), 'kind' => 'vacation', 'label' => null],
        ], $me['daysOff'] ?? null);
        self::assertStringNotContainsString('gripe', json_encode($me, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));

        // With the register off for the user, GET workday answers 404: features.leave and links.leave follow
        // features.workday (audit NG-4), the keys stay.
        $this->register()->off = ['person@example.com'];
        $off                   = $this->api('GET', '/api/time-track/me', null, $token, ['HTTPS' => 'on']);
        self::assertFalse($off['features']['leave'] ?? null);
        self::assertFalse($off['features']['workday'] ?? null);
        self::assertArrayHasKey('leave', $off['links'] ?? []);
        self::assertNull($off['links']['leave']);
    }

    public function testWorkdayActionsKeepTheir121Body(): void
    {
        $leaves          = $this->leaves();
        $leaves->records = [(string) $this->user->getId() => ['type' => 'Vacaciones', 'medical' => false, 'comment' => '', 'attachment' => null, 'until' => '2026-11-14']];
        $token           = $this->token(ClientScope::WorkdayRead, ClientScope::WorkdayWrite);

        $state = $this->api('POST', '/api/time-track/workday/clock-in', [], $token, ['HTTP_IDEMPOTENCY_KEY' => 'leave-1']);
        self::assertResponseIsSuccessful();
        self::assertArrayNotHasKey('leave', $state ?? [], 'no leave in action answers (nor in the idempotency store)');
        self::assertSame([], $leaves->calls);
    }

    private function token(ClientScope ...$scopes): string
    {
        $auth = self::getContainer()->get(ClientAuthService::class);
        self::assertInstanceOf(ClientAuthService::class, $auth);

        return (string) $auth->issueToken($this->user, ClientType::Desktop, null, $scopes === [] ? [ClientScope::WorkdayRead] : array_values($scopes))['token'];
    }

    private function register(): StubWorkdayProvider
    {
        $register = self::getContainer()->get(StubWorkdayProvider::class);
        self::assertInstanceOf(StubWorkdayProvider::class, $register);

        return $register;
    }

    private function leaves(): StubLeaveProvider
    {
        $leaves = self::getContainer()->get(StubLeaveProvider::class);
        self::assertInstanceOf(StubLeaveProvider::class, $leaves);

        return $leaves;
    }

    private function logger(): RecordingLogger
    {
        $logger = self::getContainer()->get('logger');
        self::assertInstanceOf(RecordingLogger::class, $logger);

        return $logger;
    }

    /**
     * Contexts of the bundle's leave warnings.
     *
     * @return list<array<mixed>>
     */
    private function leaveWarnings(): array
    {
        $warnings = [];
        foreach ($this->logger()->records as $record) {
            if (($record['context']['action'] ?? null) === 'client_workday') {
                $warnings[] = $record['context'];
            }
        }

        return $warnings;
    }
}
