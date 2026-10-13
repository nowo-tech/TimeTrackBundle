<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Tests\Unit\Exception;

use Nowo\TimeTrackBundle\Exception\ActiveTimerConflictException;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class ActiveTimerConflictExceptionTest extends TestCase
{
    public function testAlreadyRunningMessage(): void
    {
        $exception = ActiveTimerConflictException::alreadyRunning('task-99');

        self::assertStringContainsString('task-99', $exception->getMessage());
    }

    public function testConcurrentStartKeepsPrevious(): void
    {
        $previous  = new RuntimeException('duplicate key');
        $exception = ActiveTimerConflictException::concurrentStart($previous);

        self::assertSame('An active timer is already running.', $exception->getMessage());
        self::assertSame($previous, $exception->getPrevious());
    }
}
