<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Tests\Unit\Support;

use DateTimeImmutable;
use DateTimeZone;
use Nowo\TimeTrackBundle\Support\CanonicalJson;
use Nowo\TimeTrackBundle\Support\StorageTime;
use PHPUnit\Framework\TestCase;

use function date_default_timezone_get;
use function hash;

final class CanonicalJsonStorageTimeTest extends TestCase
{
    public function testKeysAreSortedRecursivelyAndListsKeepTheirOrder(): void
    {
        $a = ['b' => 1, 'a' => ['y' => true, 'x' => null], 'list' => [3, 1, ['d' => 'é/', 'c' => 2]]];
        $b = ['list' => [3, 1, ['c' => 2, 'd' => 'é/']], 'a' => ['x' => null, 'y' => true], 'b' => 1];

        self::assertSame('{"a":{"x":null,"y":true},"b":1,"list":[3,1,{"c":2,"d":"é/"}]}', CanonicalJson::encode($a));
        self::assertSame(CanonicalJson::encode($a), CanonicalJson::encode($b));
        self::assertSame(hash('sha256', 'prev' . CanonicalJson::encode($a)), CanonicalJson::hash($b, 'prev'));
        self::assertSame('"x"', CanonicalJson::encode('x'));
    }

    public function testStorageTimeNormalisesToTheDefaultZoneAndTheSecond(): void
    {
        $time = new DateTimeImmutable('2026-03-29 01:30:00.750', new DateTimeZone('Europe/Madrid'));

        $stored = StorageTime::normalize($time);
        self::assertSame(date_default_timezone_get(), $stored->getTimezone()->getName());
        self::assertSame($time->getTimestamp(), $stored->getTimestamp());
        self::assertSame('000000', $stored->format('u'));
        self::assertSame('2026-03-29T00:30:00Z', StorageTime::utc($time));
        self::assertSame('2026-03-28', StorageTime::localDate(new DateTimeImmutable('2026-03-29 03:00:00Z'), 'America/New_York'));
    }
}
