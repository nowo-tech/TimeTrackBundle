<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Tests\Unit\Integration;

use DateTimeImmutable;
use Nowo\TimeTrackBundle\Entity\TimeEntry;
use Nowo\TimeTrackBundle\Enum\TimeEntrySource;
use Nowo\TimeTrackBundle\Integration\NullTimeEntryBillableDefaultProvider;
use Nowo\TimeTrackBundle\Tests\Stub\TestUser;
use PHPUnit\Framework\TestCase;

/**
 * Since 1.21: the default provider answers null (the flag stays unset), and the entity setter reports a change.
 */
final class NullTimeEntryBillableDefaultProviderTest extends TestCase
{
    public function testNoDefault(): void
    {
        self::assertNull((new NullTimeEntryBillableDefaultProvider())->defaultFor('org-a', 'task-1'));
        self::assertNull((new NullTimeEntryBillableDefaultProvider())->defaultFor('', null));
    }

    public function testMarkBillableReportsAChange(): void
    {
        $entry = new TimeEntry(new TestUser('1', 'u@example.com'), 'task-1', 'T', null, new DateTimeImmutable('-2 hours'), new DateTimeImmutable('-1 hour'), 3600, TimeEntrySource::Web);

        self::assertFalse($entry->markBillable(null));
        self::assertTrue($entry->markBillable(true));
        self::assertFalse($entry->markBillable(true));
        self::assertTrue($entry->markBillable(false));
        self::assertFalse($entry->toArray()['billable']);
    }
}
