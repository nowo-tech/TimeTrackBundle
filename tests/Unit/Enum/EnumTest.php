<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Tests\Unit\Enum;

use Nowo\TimeTrackBundle\Enum\ClientType;
use Nowo\TimeTrackBundle\Enum\TimeEntrySource;
use PHPUnit\Framework\TestCase;

final class EnumTest extends TestCase
{
    public function testClientTypeValues(): void
    {
        self::assertSame('extension', ClientType::Extension->value);
        self::assertSame('desktop', ClientType::Desktop->value);
    }

    public function testTimeEntrySourceValues(): void
    {
        self::assertSame('web', TimeEntrySource::Web->value);
    }
}
