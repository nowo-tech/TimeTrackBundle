<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Tests\Unit\Subject;

use Nowo\TimeTrackBundle\Subject\ScopedSubjectLabels;
use Nowo\TimeTrackBundle\Subject\TimeSubjectLabel;
use Nowo\TimeTrackBundle\Subject\TimeSubjectLabels;
use Nowo\TimeTrackBundle\Subject\TimeSubjectRef;
use PHPUnit\Framework\TestCase;

/**
 * Since 2.1.0 (Beacon spec 256): label sets are keyed by the exact (type, id) pair.
 */
final class TimeSubjectLabelsTest extends TestCase
{
    public function testLabelsAreFoundByExactPair(): void
    {
        $p1     = new TimeSubjectRef('project', 'p-1');
        $labels = TimeSubjectLabels::of([new TimeSubjectLabel($p1, 'One')]);

        self::assertSame('One', $labels->get(new TimeSubjectRef('project', 'p-1'))?->label);
        self::assertTrue($labels->readable($p1));
        self::assertNull($labels->get(new TimeSubjectRef('client', 'p-1')));
        self::assertFalse($labels->readable(new TimeSubjectRef('project', 'P-1')));
        self::assertCount(1, $labels->all());
    }

    public function testNoneIsEmpty(): void
    {
        self::assertSame([], TimeSubjectLabels::none()->all());
        self::assertFalse(TimeSubjectLabels::none()->readable(new TimeSubjectRef('project', 'p-1')));
    }

    public function testWithPrefersTheOtherSet(): void
    {
        $p1    = new TimeSubjectRef('project', 'p-1');
        $p2    = new TimeSubjectRef('project', 'p-2');
        $first = TimeSubjectLabels::of([new TimeSubjectLabel($p1, 'Old'), new TimeSubjectLabel($p2, 'Two')]);
        $other = TimeSubjectLabels::of([new TimeSubjectLabel($p1, 'New')]);

        $merged = $first->with($other);

        self::assertSame('New', $merged->get($p1)?->label);
        self::assertSame('Two', $merged->get($p2)?->label);
    }

    public function testOnlyForKeepsTheAskedKeys(): void
    {
        $p1     = new TimeSubjectRef('project', 'p-1');
        $p2     = new TimeSubjectRef('project', 'p-2');
        $labels = TimeSubjectLabels::of([new TimeSubjectLabel($p1, 'One'), new TimeSubjectLabel($p2, 'Two')])->onlyFor([$p1->key() => $p1]);

        self::assertTrue($labels->readable($p1));
        self::assertFalse($labels->readable($p2));
    }

    public function testScopedLabelsAreLookedUpPerScope(): void
    {
        $p1     = new TimeSubjectRef('project', 'p-1');
        $scoped = new ScopedSubjectLabels(['org-a' => TimeSubjectLabels::of([new TimeSubjectLabel($p1, 'One')]), '' => TimeSubjectLabels::of([new TimeSubjectLabel($p1, 'NoScope')])]);

        self::assertSame('One', $scoped->get('org-a', $p1)?->label);
        self::assertSame('NoScope', $scoped->get(null, $p1)?->label);
        self::assertNull($scoped->get('org-b', $p1));
        self::assertNull((new ScopedSubjectLabels())->get('org-a', $p1));
    }
}
