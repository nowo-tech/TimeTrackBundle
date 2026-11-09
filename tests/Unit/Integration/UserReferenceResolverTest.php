<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Tests\Unit\Integration;

use Doctrine\ORM\EntityManagerInterface;
use LogicException;
use Nowo\TimeTrackBundle\Doctrine\DoctrineUserReferenceResolver;
use Nowo\TimeTrackBundle\Integration\PassThroughUserReferenceResolver;
use Nowo\TimeTrackBundle\Tests\Fixtures\DddHost\SecurityUser;
use Nowo\TimeTrackBundle\Tests\Fixtures\FunctionalEntity\AppUser;
use Nowo\TimeTrackBundle\Tests\Stub\TestUser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Stringable;
use Symfony\Component\Security\Core\User\UserInterface;

final class UserReferenceResolverTest extends TestCase
{
    public function testPassThroughKeepsTheSecurityUser(): void
    {
        $user = new TestUser('1', 'u@example.com');

        self::assertSame($user, (new PassThroughUserReferenceResolver())->reference($user));
    }

    public function testTheUserClassEntityIsStoredAsItIs(): void
    {
        $user = new AppUser('person@example.com', 'secret');
        $em   = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::never())->method('getReference');

        self::assertSame($user, (new DoctrineUserReferenceResolver($em, AppUser::class))->reference($user));
    }

    /**
     * @return iterable<string, array{int|object|string}>
     */
    public static function ids(): iterable
    {
        yield 'int' => [42];
        yield 'string' => ['0190a1b2-0000-7000-8000-000000000001'];
        yield 'id object' => [new class implements Stringable {
            public function __toString(): string
            {
                return '0190a1b2-0000-7000-8000-000000000001';
            }
        }];
    }

    #[DataProvider('ids')]
    public function testAnotherSecurityUserBecomesAReferenceFromItsId(int|string|object $id): void
    {
        $reference = new AppUser('person@example.com', 'secret');
        $em        = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::once())->method('getReference')->with(AppUser::class, $id)->willReturn($reference);

        self::assertSame($reference, (new DoctrineUserReferenceResolver($em, AppUser::class))->reference(new SecurityUser($id)));
    }

    /**
     * @return iterable<string, array{int|string|null}>
     */
    public static function unusableIds(): iterable
    {
        yield 'null' => [null];
        yield 'empty string' => [''];
        yield 'zero' => [0];
        yield 'zero string' => ['0'];
        yield 'negative' => [-3];
    }

    #[DataProvider('unusableIds')]
    public function testASecurityUserWithoutAUsableIdIsRefused(int|string|null $id): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::never())->method('getReference');

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('has no usable getId()');

        (new DoctrineUserReferenceResolver($em, AppUser::class))->reference(new SecurityUser($id));
    }

    public function testASecurityUserWithoutGetIdIsRefused(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);

        $this->expectException(LogicException::class);

        (new DoctrineUserReferenceResolver($em, AppUser::class))->reference(new class implements UserInterface {
            public function getRoles(): array
            {
                return [];
            }

            public function eraseCredentials(): void
            {
            }

            public function getUserIdentifier(): string
            {
                return 'no-id';
            }
        });
    }

    public function testAMissingReferenceIsRefused(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getReference')->willReturn(null);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('No Doctrine reference could be built');

        (new DoctrineUserReferenceResolver($em, AppUser::class))->reference(new SecurityUser(7));
    }
}
