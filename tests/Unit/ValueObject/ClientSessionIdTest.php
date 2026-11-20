<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Tests\Unit\ValueObject;

use InvalidArgumentException;
use Nowo\TimeTrackBundle\Enum\ClientSessionRevocation;
use Nowo\TimeTrackBundle\ValueObject\ClientSessionId;
use PHPUnit\Framework\TestCase;

/**
 * 1.34.0: the id of one client session.
 */
final class ClientSessionIdTest extends TestCase
{
    public function testItIsALowerCaseUuid(): void
    {
        $id = ClientSessionId::fromString('6F1C4C62-1E86-4A5E-9A51-0D0E0B6A6F00');

        self::assertSame('6f1c4c62-1e86-4a5e-9a51-0d0e0b6a6f00', $id->toString());
        self::assertSame('6f1c4c62-1e86-4a5e-9a51-0d0e0b6a6f00', (string) $id);
        self::assertNull(ClientSessionId::tryFromString('nope'));
        self::assertNotNull(ClientSessionId::tryFromString('6f1c4c62-1e86-4a5e-9a51-0d0e0b6a6f00'));
    }

    public function testAnythingElseIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        ClientSessionId::fromString('6f1c4c62');
    }

    public function testTheRevocationResult(): void
    {
        self::assertTrue(ClientSessionRevocation::Revoked->isRevoked());
        self::assertFalse(ClientSessionRevocation::NotFound->isRevoked());
        self::assertSame('not_found', ClientSessionRevocation::NotFound->value);
    }
}
