<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Tests\Unit\Doctrine;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;
use Nowo\TimeTrackBundle\Doctrine\OwnerIdColumn;
use Nowo\TimeTrackBundle\Entity\TimeEntry;
use Nowo\TimeTrackBundle\Tests\Fixtures\FunctionalEntity\AppUser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * 1.35.0: an id the owner column cannot hold never reaches the SQL (PostgreSQL rejects `'nobody'` for an integer).
 */
final class OwnerIdColumnTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string, bool}>
     */
    public static function ids(): iterable
    {
        yield 'string column takes anything' => [Types::STRING, 'nobody', true];
        yield 'guid column' => [Types::GUID, 'ABC', true];
        yield 'integer' => [Types::INTEGER, '42', true];
        yield 'zero' => [Types::INTEGER, '0', true];
        yield 'negative' => [Types::INTEGER, '-7', true];
        yield 'not a number' => [Types::INTEGER, 'nobody', false];
        yield 'leading zero' => [Types::INTEGER, '007', false];
        yield 'minus zero' => [Types::INTEGER, '-0', false];
        yield 'blank around' => [Types::INTEGER, ' 42', false];
        yield 'integer max' => [Types::INTEGER, '2147483647', true];
        yield 'integer overflow' => [Types::INTEGER, '2147483648', false];
        yield 'integer min' => [Types::INTEGER, '-2147483648', true];
        yield 'integer underflow' => [Types::INTEGER, '-2147483649', false];
        yield 'smallint overflow' => [Types::SMALLINT, '32768', false];
        yield 'bigint max' => [Types::BIGINT, '9223372036854775807', true];
        yield 'bigint saturates' => [Types::BIGINT, '9223372036854775808', false];
        yield 'too long' => [Types::BIGINT, '12345678901234567890', false];
    }

    #[DataProvider('ids')]
    public function testTheOwnerColumnTypeDecides(string $type, string $id, bool $accepted): void
    {
        $entry = $this->createMock(ClassMetadata::class);
        $entry->method('getAssociationTargetClass')->with('user')->willReturn(AppUser::class);
        $user = $this->createMock(ClassMetadata::class);
        $user->method('getSingleIdentifierFieldName')->willReturn('id');
        $user->method('getTypeOfField')->with('id')->willReturn($type);
        $manager = $this->createMock(EntityManagerInterface::class);
        $manager->method('getClassMetadata')->willReturnCallback(static fn (string $class): ClassMetadata => $class === TimeEntry::class ? $entry : $user);

        self::assertSame($accepted, OwnerIdColumn::accepts($manager, TimeEntry::class, $id));
    }
}
