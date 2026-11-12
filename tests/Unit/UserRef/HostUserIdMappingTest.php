<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Tests\Unit\UserRef;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\DBAL\Platforms\MySQL80Platform;
use Doctrine\DBAL\Platforms\SQLServerPlatform;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Event\LoadClassMetadataEventArgs;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\Mapping\Driver\AttributeDriver;
use Doctrine\Persistence\Mapping\RuntimeReflectionService;
use LogicException;
use Nowo\TimeTrackBundle\Doctrine\DoctrineUserReferenceResolver;
use Nowo\TimeTrackBundle\Doctrine\TimeTrackMetadataListener;
use Nowo\TimeTrackBundle\Tests\Fixtures\DddHost\SecurityUser;
use Nowo\TimeTrackBundle\Tests\Stub\TestUser;
use Nowo\TimeTrackBundle\UserRef\Entity\HostUser;
use Nowo\TimeTrackBundle\UserRef\UserMapping;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

use function dirname;
use function str_repeat;

/**
 * 1.23.0 (`user_mapping: id`, REQ-ARCH-001 rule 2): the bundle-owned host user row, its resolver path and its mapping.
 */
final class HostUserIdMappingTest extends TestCase
{
    public function testTheRowStandsForAUserWithoutRoles(): void
    {
        $user = new HostUser('ext-7', SecurityUser::class);

        self::assertSame(['ext-7', SecurityUser::class, 'ext-7', []], [$user->getId(), $user->getUserClass(), $user->getUserIdentifier(), $user->getRoles()]);
        self::assertSame('?', (new HostUser('', SecurityUser::class))->getUserIdentifier());
        $user->eraseCredentials();
        self::assertSame('ext-7', $user->getId());
    }

    public function testTheResolverReturnsTheExistingRow(): void
    {
        $row = new HostUser('ext-7', SecurityUser::class);
        $em  = $this->createMock(EntityManagerInterface::class);
        $em->method('find')->with(HostUser::class, 'ext-7')->willReturn($row);
        $em->expects(self::never())->method('getConnection');

        self::assertSame($row, $this->resolver($em)->reference(new SecurityUser('ext-7')));
    }

    public function testTheResolverToleratesARowCreatedMeanwhile(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('isTransactionActive')->willReturn(false);
        $connection->expects(self::once())->method('insert')->with('time_track_host_users', ['id' => 'ext-7', 'user_class' => SecurityUser::class])->willThrowException($this->createStub(UniqueConstraintViolationException::class));
        $metadata = new ClassMetadata(HostUser::class);
        $metadata->setPrimaryTable(['name' => 'time_track_host_users']);
        $reference = new HostUser('ext-7', SecurityUser::class);
        $em        = $this->createMock(EntityManagerInterface::class);
        $em->method('find')->willReturn(null);
        $em->method('getConnection')->willReturn($connection);
        $em->method('getClassMetadata')->willReturn($metadata);
        $em->method('getReference')->with(HostUser::class, 'ext-7')->willReturn($reference);

        self::assertSame($reference, $this->resolver($em)->reference(new SecurityUser('ext-7')));
    }

    public function testTheResolverRefusesAnotherClassAndAnUnusableId(): void
    {
        $em = $this->createStub(EntityManagerInterface::class);
        try {
            $this->resolver($em)->reference(new TestUser('1', 'other'));
            self::fail('a class that is neither user_class nor accepted is refused');
        } catch (AccessDeniedException) {
        }

        foreach ([new SecurityUser(null), new SecurityUser('0'), new SecurityUser(str_repeat('a', HostUser::ID_LENGTH + 1))] as $user) {
            try {
                $this->resolver($em)->reference($user);
                self::fail('an unusable id is refused');
            } catch (LogicException $exception) {
                self::assertStringContainsString('no usable getId()', $exception->getMessage());
            }
        }
    }

    public function testTheIdColumnIsBinaryOnMySqlAndSqlServerAndAnUnknownPlatformFails(): void
    {
        self::assertSame(['charset' => 'utf8mb4', 'collation' => 'utf8mb4_bin'], $this->idOptions(new MySQL80Platform()));
        self::assertSame(['collation' => 'Latin1_General_100_BIN2'], $this->idOptions(new SQLServerPlatform()));

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('needs the database platform');
        $this->idOptions(null);
    }

    private function resolver(EntityManagerInterface $em): DoctrineUserReferenceResolver
    {
        return new DoctrineUserReferenceResolver($em, SecurityUser::class, [], UserMapping::ID);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function idOptions(?object $platform): ?array
    {
        $metadata = new ClassMetadata(HostUser::class);
        $metadata->initializeReflection(new RuntimeReflectionService());
        (new AttributeDriver([dirname(__DIR__, 3) . '/src/UserRef/Entity']))->loadMetadataForClass(HostUser::class, $metadata);
        $connection = $this->createStub(Connection::class);
        if ($platform === null) {
            $connection->method('getDatabasePlatform')->willThrowException(new RuntimeException('database down'));
        } else {
            $connection->method('getDatabasePlatform')->willReturn($platform);
        }
        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('getConnection')->willReturn($connection);

        (new TimeTrackMetadataListener('e', 't', 'c', SecurityUser::class, userMapping: UserMapping::ID))->loadClassMetadata(new LoadClassMetadataEventArgs($metadata, $em));
        self::assertSame('time_track_host_users', $metadata->getTableName());

        return $metadata->fieldMappings['id']->options;
    }
}
