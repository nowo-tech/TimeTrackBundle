<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Tests\Unit\ValueObject;

use InvalidArgumentException;
use Nowo\TimeTrackBundle\ValueObject\Uuid;
use PHPUnit\Framework\TestCase;

final class UuidTest extends TestCase
{
    public function testFromStringNormalizesCase(): void
    {
        $uuid = Uuid::fromString('550E8400-E29B-41D4-A716-446655440000');

        self::assertSame('550e8400-e29b-41d4-a716-446655440000', $uuid->toString());
        self::assertSame('550e8400-e29b-41d4-a716-446655440000', (string) $uuid);
    }

    public function testGenerateProducesValidUuid(): void
    {
        $uuid = Uuid::generate();

        self::assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/',
            $uuid->toString(),
        );
    }

    public function testInvalidUuidThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Uuid::fromString('not-a-uuid');
    }
}
