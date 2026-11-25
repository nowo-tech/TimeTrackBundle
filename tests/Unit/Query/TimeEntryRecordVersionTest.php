<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Tests\Unit\Query;

use DateTimeImmutable;
use Nowo\TimeTrackBundle\Dto\TimeEntryView;
use Nowo\TimeTrackBundle\Enum\TimeEntrySource;
use Nowo\TimeTrackBundle\Query\TimeEntryRecord;
use Nowo\TimeTrackBundle\Subject\TimeSubjectRef;
use PHPUnit\Framework\TestCase;

use function array_unique;
use function hash;
use function json_encode;

use const JSON_THROW_ON_ERROR;
use const JSON_UNESCAPED_SLASHES;
use const JSON_UNESCAPED_UNICODE;

/**
 * Since 2.1.0 (Beacon spec 256): the version token is byte-identical to 2.0 without subject or activity type and
 * changes when either is set.
 */
final class TimeEntryRecordVersionTest extends TestCase
{
    public function testTheTokenOfAnUnallocatedEntryIsThe20Token(): void
    {
        self::assertSame($this->version20(false), $this->record(null, null)->version());
        self::assertSame($this->version20(true), $this->record(null, null, true)->version());
    }

    public function testSubjectAndActivityChangeTheToken(): void
    {
        $plain    = $this->record(null, null)->version();
        $subject  = $this->record(new TimeSubjectRef('client', 'c-1'), null)->version();
        $activity = $this->record(null, 'dev')->version();
        $both     = $this->record(new TimeSubjectRef('client', 'c-1'), 'dev')->version();

        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $both);
        self::assertCount(4, array_unique([$plain, $subject, $activity, $both]));
        self::assertNotSame($subject, $this->record(new TimeSubjectRef('client', 'c-2'), null)->version());
        self::assertNotSame($subject, $this->record(new TimeSubjectRef('project', 'c-1'), null)->version());
        self::assertNotSame($activity, $this->record(null, 'qa')->version());
        self::assertSame($both, $this->record(new TimeSubjectRef('client', 'c-1'), 'dev')->version());
    }

    /**
     * A record with fixed values.
     */
    private function record(?TimeSubjectRef $subject, ?string $activity, bool $withoutTask = false): TimeEntryRecord
    {
        return new TimeEntryRecord(
            new TimeEntryView('e-1', '7', $withoutTask ? '' : 't-1', 'Task', 'b-1', 'org-a', new DateTimeImmutable('2026-11-02 09:00:00 UTC'), new DateTimeImmutable('2026-11-02 10:00:00 UTC'), 3600, TimeEntrySource::Web, null, null, withoutTask: $withoutTask, subject: $subject, activityTypeKey: $activity),
            'note',
            'Désc/1',
            true,
            ['a' => 1, 'b' => ['x' => 2]],
            new DateTimeImmutable('2026-11-02 10:00:00 UTC'),
        );
    }

    /**
     * The 2.0.0 formula, copied as it was.
     */
    private function version20(bool $withoutTask): string
    {
        return hash('sha256', json_encode([
            'v1',
            'e-1',
            '7',
            $withoutTask ? null : 't-1',
            'b-1',
            'org-a',
            (new DateTimeImmutable('2026-11-02 09:00:00 UTC'))->getTimestamp(),
            (new DateTimeImmutable('2026-11-02 10:00:00 UTC'))->getTimestamp(),
            3600,
            TimeEntrySource::Web->value,
            true,
            'Désc/1',
            ['a' => 1, 'b' => ['x' => 2]],
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }
}
