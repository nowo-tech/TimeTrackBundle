<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Tests\Unit\Query;

use Nowo\TimeTrackBundle\Query\AllocationFilter;
use Nowo\TimeTrackBundle\Query\TimeEntryGrouping;
use Nowo\TimeTrackBundle\Query\TimeEntryGroupKey;
use Nowo\TimeTrackBundle\Query\TimeEntryTotal;
use Nowo\TimeTrackBundle\Query\TimeEntryTotals;
use Nowo\TimeTrackBundle\Subject\TimeSubjectRef;
use PHPUnit\Framework\TestCase;

/**
 * Since 2.1.0 (Beacon spec 256): group keys and totals of the read port.
 */
final class TimeEntryTotalsTest extends TestCase
{
    public function testGroupKeysAreStableAndDistinct(): void
    {
        $empty = new TimeEntryGroupKey();
        self::assertSame('[null,null,null,null,null,null,null]', $empty->key());

        $full = new TimeEntryGroupKey(new TimeSubjectRef('client', 'c-1'), 'client', 'dev', '7', 't-1', AllocationFilter::TaskAndSubject);
        self::assertSame('["client","c-1","client","dev","7","t-1","task_subject"]', $full->key());
        self::assertSame($full->key(), (new TimeEntryGroupKey(new TimeSubjectRef('client', 'c-1'), 'client', 'dev', '7', 't-1', AllocationFilter::TaskAndSubject))->key());
        self::assertNotSame((new TimeEntryGroupKey(ownerId: 'c-1'))->key(), (new TimeEntryGroupKey(taskId: 'c-1'))->key());
    }

    public function testTotalsKeepTheirValues(): void
    {
        $key    = new TimeEntryGroupKey(activityTypeKey: 'dev');
        $total  = new TimeEntryTotal($key, 3600, 2);
        $totals = new TimeEntryTotals([$total], true);

        self::assertSame($key, $total->key);
        self::assertSame(3600, $total->seconds);
        self::assertSame(2, $total->entries);
        self::assertSame([$total], $totals->totals);
        self::assertTrue($totals->truncated);
        self::assertFalse((new TimeEntryTotals([]))->truncated);
        self::assertCount(7, TimeEntryGrouping::cases());
    }
}
