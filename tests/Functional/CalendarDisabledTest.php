<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Tests\Functional;

use DateTimeImmutable;
use Nowo\TimeTrackBundle\Calendar\CalendarConnectionService;
use Nowo\TimeTrackBundle\Calendar\CalendarException;
use Nowo\TimeTrackBundle\Calendar\CalendarRuleService;
use Nowo\TimeTrackBundle\Calendar\CalendarSyncService;
use Nowo\TimeTrackBundle\Command\CalendarSyncCommand;
use Nowo\TimeTrackBundle\Entity\CalendarConnection;
use Nowo\TimeTrackBundle\Entity\CalendarRule;
use Nowo\TimeTrackBundle\Enum\CalendarConnectionMode;
use Nowo\TimeTrackBundle\Enum\CalendarProvider;
use Symfony\Component\Console\Tester\CommandTester;

use function preg_replace;

/**
 * Since 1.14 (AC-01): with `calendar` off (the default) the services refuse or return nothing, the tables stay empty
 * and the sync command only purges.
 */
final class CalendarDisabledTest extends FunctionalTestCase
{
    public function testEverythingIsOff(): void
    {
        $connections = self::getContainer()->get(CalendarConnectionService::class);
        $rules       = self::getContainer()->get(CalendarRuleService::class);
        $sync        = self::getContainer()->get(CalendarSyncService::class);
        self::assertInstanceOf(CalendarConnectionService::class, $connections);
        self::assertInstanceOf(CalendarRuleService::class, $rules);
        self::assertInstanceOf(CalendarSyncService::class, $sync);

        self::assertFalse($connections->isEnabled());
        self::assertFalse($sync->isEnabled());
        self::assertNull($connections->scopeOf($this->user, null));
        self::assertSame([], $connections->connectionsOf($this->user));
        self::assertNull($connections->connectionOf($this->user, 'x'));
        self::assertSame([], $rules->rulesOf($this->user, ''));
        self::assertFalse($rules->delete($this->user, 'x'));
        foreach ([
            fn (): CalendarConnection => $connections->connect($this->user, '', CalendarProvider::Google, CalendarConnectionMode::Local, null, 'v1'),
            fn (): CalendarRule => $rules->create($this->user, '', 'daily', 'task-1'),
        ] as $operation) {
            try {
                $operation();
                self::fail('expected calendar_disabled');
            } catch (CalendarException $exception) {
                self::assertSame('calendar_disabled', $exception->errorCode);
            }
        }
        $connection = new CalendarConnection($this->user, '', CalendarProvider::Google, CalendarConnectionMode::Server, 'c', 'v1', new DateTimeImmutable());
        self::assertSame('not_syncable', $sync->sync($connection)->errorCode);
        self::assertSame([], $sync->syncUser($this->user, null));

        $command = self::getContainer()->get(CalendarSyncCommand::class);
        self::assertInstanceOf(CalendarSyncCommand::class, $command);
        $tester = new CommandTester($command);
        self::assertSame(0, $tester->execute([]));
        self::assertStringContainsString('Calendar suggestions are disabled', (string) preg_replace('/\s+/', ' ', $tester->getDisplay()));
        self::assertStringContainsString('Synced 0 connection(s), 0 failed, 0 outside working hours; purged 0 expired suggestion(s).', (string) preg_replace('/\s+/', ' ', $tester->getDisplay()));
    }
}
