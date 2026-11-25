<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Tests\Unit\Exception;

use DateTimeImmutable;
use Nowo\TimeTrackBundle\Enum\ClientType;
use Nowo\TimeTrackBundle\Exception\TimeAllocationException;
use Nowo\TimeTrackBundle\Exception\TimeAllocationRequiredException;
use Nowo\TimeTrackBundle\Timer\TimerSnapshot;
use PHPUnit\Framework\TestCase;

/**
 * Since 2.1.0 (Beacon spec 256): allocation error codes map to HTTP statuses.
 */
final class TimeAllocationExceptionTest extends TestCase
{
    public function testStatusMapping(): void
    {
        $expected = [
            TimeAllocationException::SUBJECT_FORBIDDEN      => 403,
            TimeAllocationException::ALLOCATION_REQUIRED    => 409,
            TimeAllocationException::ALLOCATION_CONFLICT    => 422,
            TimeAllocationException::ACTIVITY_TYPE_UNKNOWN  => 422,
            TimeAllocationException::ACTIVITY_TYPE_INACTIVE => 422,
            TimeAllocationException::ACTIVITY_TYPE_REQUIRED => 422,
        ];
        foreach ($expected as $code => $status) {
            $exception = new TimeAllocationException($code);
            self::assertSame($code, $exception->errorCode);
            self::assertSame($code, $exception->getMessage());
            self::assertSame($status, $exception->httpStatus());
        }
    }

    public function testRequiredExceptionCarriesTheTimerAndIsAlways409(): void
    {
        $timer     = new TimerSnapshot('tm-1', '', '', null, new DateTimeImmutable('2026-11-02 09:00:00'), ClientType::Web, null, null, true);
        $exception = new TimeAllocationRequiredException($timer);

        self::assertSame($timer, $exception->timer);
        self::assertSame(TimeAllocationException::ALLOCATION_REQUIRED, $exception->errorCode);
        self::assertSame(409, $exception->httpStatus());

        $activity = new TimeAllocationRequiredException($timer, TimeAllocationException::ACTIVITY_TYPE_REQUIRED);
        self::assertSame(TimeAllocationException::ACTIVITY_TYPE_REQUIRED, $activity->errorCode);
        self::assertSame(409, $activity->httpStatus());
    }
}
