<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Tests\Unit\Exception;

use Nowo\TimeTrackBundle\Exception\ActiveTimerConflictException;
use PHPUnit\Framework\TestCase;

final class ActiveTimerConflictExceptionTest extends TestCase
{
    public function testAlreadyRunningMessage(): void
    {
        $exception = ActiveTimerConflictException::alreadyRunning('task-99');

        self::assertStringContainsString('task-99', $exception->getMessage());
    }
}
