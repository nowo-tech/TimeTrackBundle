<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Tests\Unit\ClientApi;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use Nowo\TimeTrackBundle\Client\ClientDaysOffNormalizer;
use Nowo\TimeTrackBundle\Client\ClientUserLeave;
use Nowo\TimeTrackBundle\Client\ClientUserLeaveProviderInterface;
use Nowo\TimeTrackBundle\Client\ClientUserLocaleProviderInterface;
use Nowo\TimeTrackBundle\Client\ClientUserProfileProviderInterface;
use Nowo\TimeTrackBundle\Client\ClientWorkingTimeContext;
use Nowo\TimeTrackBundle\Tests\Stub\StubTimesheetApproverProvider;
use Nowo\TimeTrackBundle\Tests\Stub\TestUser;
use Nowo\TimeTrackBundle\Tests\Support\FixedScopeProvider;
use Nowo\TimeTrackBundle\Tests\Support\FixedWorkingCalendar;
use Nowo\TimeTrackBundle\Tests\Support\InMemoryActiveTimerRepository;
use Nowo\TimeTrackBundle\Tests\Support\InMemoryBalanceLedgerRepository;
use Nowo\TimeTrackBundle\Tests\Support\InMemoryTimeEntryRepository;
use Nowo\TimeTrackBundle\Tests\Support\InMemoryTimesheetEventRepository;
use Nowo\TimeTrackBundle\Tests\Support\InMemoryTimesheetRepository;
use Nowo\TimeTrackBundle\Tests\Support\RecordingLogger;
use Nowo\TimeTrackBundle\Timesheet\PeriodLockGuard;
use Nowo\TimeTrackBundle\Timesheet\TimesheetAccessGuard;
use Nowo\TimeTrackBundle\Timesheet\TimesheetAuditTrail;
use Nowo\TimeTrackBundle\Timesheet\TimesheetService;
use Nowo\TimeTrackBundle\Timesheet\TimesheetSnapshotBuilder;
use Nowo\TimeTrackBundle\Timesheet\TimesheetTimezoneResolver;
use Nowo\TimeTrackBundle\Workday\WorkdayState;
use Nowo\TimeTrackBundle\WorkingTime\ConfigOvertimePolicy;
use Nowo\TimeTrackBundle\WorkingTime\TaskTimeWorkedTimeSource;
use Nowo\TimeTrackBundle\WorkingTime\WorkingTimeBalanceService;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionProperty;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

use function array_map;
use function array_shift;
use function date_default_timezone_get;
use function end;
use function file_get_contents;
use function implode;
use function preg_match;
use function str_repeat;
use function strlen;

use const DATE_ATOM;

/**
 * Since 1.22 (Beacon spec 204 FR-029): the leave value object (nothing private fits in it), the `leave` kind of
 * `daysOff` (never a label) and `GET workday.leave` built by {@see ClientWorkingTimeContext::leave()}.
 */
final class ClientApiV122LeaveTest extends TestCase
{
    public function testTheValueObjectHoldsOnlyAPublicTypeAndTheEndDate(): void
    {
        $typed = ClientUserLeave::typed('Vacation', '2026-08-14');
        self::assertSame(['Vacation', '2026-08-14', false], [$typed->type, $typed->until, $typed->isAbsent()]);
        $absent = ClientUserLeave::absent('2026-02-28');
        self::assertSame([null, '2026-02-28', true], [$absent->type, $absent->until, $absent->isAbsent()]);

        $class = new ReflectionClass(ClientUserLeave::class);
        self::assertSame(['type', 'until'], array_map(static fn (ReflectionProperty $p): string => $p->getName(), $class->getProperties()), 'no room for a reason, comment or attachment');
        self::assertFalse($class->getConstructor()?->isPublic(), 'only through typed() / absent()');
        self::assertTrue($class->isFinal());
        self::assertTrue($class->isReadOnly());

        foreach (['2026-02-30', '2026-8-14', '14/08/2026', '2026-08-14T00:00:00', ''] as $bad) {
            try {
                ClientUserLeave::absent($bad);
                self::fail('accepted ' . $bad);
            } catch (InvalidArgumentException $e) {
                self::assertStringNotContainsString($bad === '' ? 'x' : $bad, $e->getMessage(), 'the value is not echoed');
            }
        }
    }

    public function testThePortIsFrameworkFree(): void
    {
        foreach ([ClientUserLeaveProviderInterface::class, ClientUserLeave::class] as $class) {
            $source = (string) file_get_contents((string) (new ReflectionClass($class))->getFileName());
            self::assertDoesNotMatchRegularExpression('/^use (Symfony|Doctrine)\\\\/m', $source, $class . ' (REQ-ARCH-001 rule 3)');
        }
    }

    public function testLeaveDaysOffNeverCarryALabelAndNeedTheLeaveProvider(): void
    {
        $days = [
            ['date' => '2026-12-08', 'kind' => 'holiday', 'label' => 'Inmaculada Concepción'],
            ['date' => '2026-12-09', 'kind' => 'leave', 'label' => 'Baja médica: gripe'],
            ['date' => '2026-12-10', 'kind' => 'Leave'],
            ['date' => '2026-12-11', 'kind' => 'vacation', 'label' => null],
            ['date' => '2026-12-12', 'kind' => 'sick', 'label' => 'Gripe'],
        ];

        $logger = new RecordingLogger();
        self::assertSame([
            ['date' => '2026-12-08', 'kind' => 'holiday', 'label' => 'Inmaculada Concepción'],
            ['date' => '2026-12-09', 'kind' => 'leave', 'label' => null],
            ['date' => '2026-12-10', 'kind' => 'leave', 'label' => null],
            ['date' => '2026-12-11', 'kind' => 'vacation', 'label' => null],
        ], ClientDaysOffNormalizer::normalize($days, '2026-12-01', '2027-01-30', $logger, true), 'an unknown kind is still dropped alone');
        self::assertCount(1, $logger->records);
        self::assertSame(['leave label dropped', 'invalid kind'], $logger->records[0]['context']['reasons'] ?? null);
        self::assertStringNotContainsString('Gripe', implode("\n", $logger->contextValues()));

        // Audit NG-1: without the leave provider, a leave day is an unknown kind, as in 1.21.
        $without = ClientDaysOffNormalizer::normalize($days, '2026-12-01', '2027-01-30');
        self::assertSame([
            ['date' => '2026-12-08', 'kind' => 'holiday', 'label' => 'Inmaculada Concepción'],
            ['date' => '2026-12-11', 'kind' => 'vacation', 'label' => null],
        ], $without);
    }

    public function testLabelsLoseEveryInvisibleCharacterAndAreCappedInBytes(): void
    {
        // Audit NG-2: format characters (except ZWJ), tags, separators and invisible fillers.
        self::assertSame('Día festivo', ClientDaysOffNormalizer::label("D\u{034F}ía\u{2028}fes\u{E0041}tivo\u{2029}\u{3164}\u{115F}\u{FFF9}\u{FFFB}\u{2061}"));
        self::assertSame("A\u{200D}B", ClientDaysOffNormalizer::label("\u{00AD}A\u{200D}B\u{200B}"), 'ZWJ is kept');
        // Audit NG-5: nothing left is null, never ''.
        foreach (["\u{3164}", "\u{2028}\u{115F}", "\u{E0001}\u{E0020}", "\u{034F} \u{FFFA}", '', null] as $empty) {
            self::assertNull(ClientDaysOffNormalizer::label($empty));
        }
        // 80 grapheme clusters of a letter with 20 combining marks are ~3.3 KB: cut at a cluster boundary.
        $cluster = 'a' . str_repeat("\u{0301}", 20);
        $label   = ClientDaysOffNormalizer::label(str_repeat($cluster, 80));
        self::assertIsString($label);
        self::assertLessThanOrEqual(ClientDaysOffNormalizer::LABEL_MAX_BYTES, strlen($label));
        self::assertSame(0, strlen($label) % strlen($cluster), 'whole clusters only');
        self::assertSame(1, preg_match('//u', $label), 'valid UTF-8');
        self::assertSame(str_repeat('é', 80), ClientDaysOffNormalizer::label(str_repeat('é', 90)), 'short labels: only the grapheme cut');
    }

    public function testTheContextBuildsTheWorkdayLeave(): void
    {
        $leaves = new class implements ClientUserLeaveProviderInterface {
            public ?ClientUserLeave $answer = null;

            /** @var list<array{string, string}> */
            public array $calls = [];

            public function getCurrentLeave(string $userId, DateTimeImmutable $day): ?ClientUserLeave
            {
                $this->calls[] = [$userId, $day->format(DATE_ATOM)];

                return $this->answer;
            }

            public function lastDay(): ?string
            {
                $last = end($this->calls);

                return $last === false ? null : $last[1];
            }
        };
        $logger  = new RecordingLogger();
        $user    = new TestUser('7', 'ana@example.com');
        $state   = new WorkdayState('idle', '2026-11-05', 'Europe/Madrid');
        $context = $this->context($leaves, $logger);

        self::assertTrue($context->reportsLeave());
        self::assertNull($context->leave($user, $state), 'not on leave');
        self::assertSame([['7', '2026-11-05T00:00:00+01:00']], $leaves->calls);

        $leaves->answer = ClientUserLeave::absent('2026-11-05');
        self::assertSame(['type' => 'Ausente', 'until' => '2026-11-05'], $context->leave($user, $state), '*Absent* in the user\'s language, last day included');
        self::assertSame(['type' => ClientWorkingTimeContext::ABSENT, 'until' => '2026-11-05'], $this->context($leaves, null, false)->leave($user, $state), 'English without a translator');

        $leaves->answer = ClientUserLeave::typed("  Vacaciones\u{202E}\n de verano\u{00A0}", '2026-11-20');
        self::assertSame(['type' => 'Vacaciones  de verano', 'until' => '2026-11-20'], $context->leave($user, $state), 'plain text, bidi controls removed');
        $leaves->answer = ClientUserLeave::typed(str_repeat('é', 100), '2026-11-20');
        self::assertSame(str_repeat('é', 80), $this->typeOf($context, $user, $state));
        foreach (["\u{200B} ", "\xC3\x28"] as $unusable) {
            $leaves->answer = ClientUserLeave::typed($unusable, '2026-11-20');
            self::assertSame('Ausente', $this->typeOf($context, $user, $state), 'an empty or broken label fails closed to *Absent*');
        }
        self::assertSame([], $logger->records);

        $leaves->answer = ClientUserLeave::typed('Vacaciones', '2026-11-04');
        self::assertNull($context->leave($user, $state));
        self::assertNull($context->leave($user, new WorkdayState('idle', '2026-02-30', 'Europe/Madrid')));
        self::assertSame(['leave ended before the workday date', 'invalid workday date'], array_map(static fn (array $r): mixed => $r['context']['reason'] ?? null, $logger->records));

        $context->leave($user, new WorkdayState('idle', '2026-11-05', 'Not/AZone'));
        self::assertSame((new DateTimeImmutable('2026-11-05', new DateTimeZone(date_default_timezone_get())))->format(DATE_ATOM), $leaves->lastDay(), 'an invalid zone falls back to the PHP one');
    }

    public function testWithoutAProviderNothingIsReported(): void
    {
        $context = $this->context(null, null);
        self::assertFalse($context->reportsLeave());
        self::assertNull($context->leave(new TestUser('7', 'ana@example.com'), new WorkdayState('idle', '2026-11-05', 'UTC')));
        self::assertNull($context->leaveLink());
    }

    public function testTheLeaveLinkIsHttpsOnly(): void
    {
        $answers = ['https://host.example/leave', 'http://host.example/leave'];
        $router  = $this->createStub(UrlGeneratorInterface::class);
        $router->method('generate')->willReturnCallback(static function () use (&$answers): string {
            return (string) array_shift($answers);
        });
        $context = $this->context(null, null, true, $router, 'app_leave');
        self::assertSame('https://host.example/leave', $context->leaveLink());
        self::assertNull($context->leaveLink(), 'http is never sent');
        self::assertNull($context->workingTimeLink(), 'a separate route');
    }

    /**
     * The `type` of `GET workday.leave` (a helper, so static analysis does not reuse an earlier answer).
     */
    private function typeOf(ClientWorkingTimeContext $context, UserInterface $user, WorkdayState $state): ?string
    {
        return $context->leave($user, $state)['type'] ?? null;
    }

    private function context(?ClientUserLeaveProviderInterface $leaves, ?RecordingLogger $logger, bool $translated = true, ?UrlGeneratorInterface $router = null, ?string $leaveRoute = null): ClientWorkingTimeContext
    {
        $timesheets = new InMemoryTimesheetRepository();
        $events     = new InMemoryTimesheetEventRepository();
        $scopes     = new FixedScopeProvider(['7' => ['org-a']]);
        $access     = new TimesheetAccessGuard($scopes, new StubTimesheetApproverProvider());
        $resolver   = new TimesheetTimezoneResolver($scopes);
        $entries    = new InMemoryTimeEntryRepository();
        $service    = new TimesheetService($timesheets, $events, new TimesheetAuditTrail($events, $timesheets), $access, new TimesheetSnapshotBuilder($entries), $resolver, new InMemoryActiveTimerRepository());
        $balance    = new WorkingTimeBalanceService(new FixedWorkingCalendar(), new TaskTimeWorkedTimeSource($entries, $resolver), new ConfigOvertimePolicy(), new InMemoryBalanceLedgerRepository(), $timesheets, $resolver, new MockClock());
        $translator = new class implements TranslatorInterface {
            /**
             * @param array<string, mixed> $parameters
             */
            public function trans(string $id, array $parameters = [], ?string $domain = null, ?string $locale = null): string
            {
                return $id === 'client_api.leave_absent' && $locale === 'es' ? 'Ausente' : $id;
            }

            public function getLocale(): string
            {
                return 'en';
            }
        };
        $profiles = new class implements ClientUserProfileProviderInterface, ClientUserLocaleProviderInterface {
            public function getDisplayName(UserInterface $user): ?string
            {
                return null;
            }

            public function getWorkingHours(UserInterface $user): ?array
            {
                return null;
            }

            public function getLocale(UserInterface $user): string
            {
                return 'es_ES';
            }
        };

        return new ClientWorkingTimeContext($service, $balance, $access, new PeriodLockGuard($timesheets), null, null, $translated ? $translator : null, $profiles, $router, null, $leaves, $leaveRoute, $logger);
    }
}
