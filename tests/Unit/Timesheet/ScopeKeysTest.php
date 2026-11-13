<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Tests\Unit\Timesheet;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\MariaDBPlatform;
use Doctrine\DBAL\Platforms\MySQL80Platform;
use Doctrine\DBAL\Platforms\MySQLPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Event\LoadClassMetadataEventArgs;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\Mapping\Driver\AttributeDriver;
use Doctrine\Persistence\Mapping\RuntimeReflectionService;
use InvalidArgumentException;
use LogicException;
use Nowo\TimeTrackBundle\Doctrine\TimeTrackMetadataListener;
use Nowo\TimeTrackBundle\Entity\BalanceLedgerLine;
use Nowo\TimeTrackBundle\Entity\CalendarRule;
use Nowo\TimeTrackBundle\Entity\TimeEntry;
use Nowo\TimeTrackBundle\Entity\Timesheet;
use Nowo\TimeTrackBundle\Enum\LedgerKind;
use Nowo\TimeTrackBundle\Enum\TimeEntrySource;
use Nowo\TimeTrackBundle\Enum\TimesheetPeriodKind;
use Nowo\TimeTrackBundle\Tests\Fixtures\DddHost\SecurityUser;
use Nowo\TimeTrackBundle\Tests\Stub\TestUser;
use Nowo\TimeTrackBundle\Timesheet\Period;
use Nowo\TimeTrackBundle\Timesheet\ScopeKeys;
use Nowo\TimeTrackBundle\Timesheet\TimesheetScope;
use Nowo\TimeTrackBundle\UserRef\UserMapping;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use RuntimeException;
use Stringable;

use function dirname;
use function str_repeat;

/**
 * 1.24.1 (REQ-SEC-006, audit OA-R2 / F4): scope keys are refused when a folding or PAD SPACE collation could confuse them
 * with another key, and the `scope_key` columns compare exactly on MySQL / MariaDB with every user mapping.
 */
final class ScopeKeysTest extends TestCase
{
    /**
     * @return iterable<string, array{string}>
     */
    public static function unusableKeys(): iterable
    {
        yield 'trailing blank' => ['bob '];
        yield 'leading blank' => [' bob'];
        yield 'trailing newline' => ["bob\n"];
        yield 'NBSP' => ["bo\u{00A0}b"];
        yield 'ZWSP' => ["bob\u{200B}"];
        yield 'BOM' => ["\u{FEFF}bob"];
        yield 'bidi mark' => ["bob\u{200E}"];
        yield 'tab' => ["bo\tb"];
        yield 'invalid UTF-8' => ["bo\xC3b"];
        yield 'too long' => [str_repeat('b', 65)];
        yield 'only blanks' => ['   '];
        yield 'inner NBSP-like ideographic space' => ["b\u{3000}b"];
    }

    #[DataProvider('unusableKeys')]
    public function testUnusableKeysAreRefusedEverywhereTheyEnter(string $key): void
    {
        self::assertFalse(ScopeKeys::isUsable($key));
        foreach ([
            'scope'     => static fn (): object => new TimesheetScope($key, 'Label'),
            'timesheet' => static fn (): object => new Timesheet(null, 'u-1', $key, Period::fromKey(TimesheetPeriodKind::Week, '2026-W45'), 'UTC', new DateTimeImmutable()),
            'ledger'    => static fn (): object => new BalanceLedgerLine(null, 'u-1', $key, '2026-11-02', LedgerKind::Opening, 60, new DateTimeImmutable(), null, 'x'),
            'rule'      => static fn (): object => new CalendarRule(new TestUser('u-1', 'u-1'), $key, 'standup', 'task-1', true, new DateTimeImmutable()),
            'entry'     => static fn (): object => (new TimeEntry(null, 'task-1', 'Task', null, new DateTimeImmutable('-1 hour'), new DateTimeImmutable(), 3600, TimeEntrySource::Web))->assignScopeKey($key),
        ] as $where => $build) {
            try {
                $build();
                self::fail($where . ' refuses the key');
            } catch (InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }
    }

    public function testLookAlikeKeysAreDistinctUsableKeys(): void
    {
        foreach (['', 'bob', 'Bob', 'bób', 'org-1f0e', str_repeat('b', 64), 'Team A'] as $key) {
            self::assertTrue(ScopeKeys::isUsable($key), $key);
            self::assertSame($key, (new TimesheetScope($key, 'Label'))->key);
        }
        $rows = [['k' => 'bob'], ['k' => 'Bob'], ['k' => 'bób'], ['k' => "bo\u{0301}b"]];
        self::assertSame([['k' => 'bob']], ScopeKeys::keep($rows, 'bob', static fn (array $row): string => $row['k']));
        self::assertTrue(ScopeKeys::isOneOf('bob', ['Bob', 'bob']));
        self::assertFalse(ScopeKeys::isOneOf('bob', ['Bob', 'bób', 'bob ']));
        self::assertFalse(ScopeKeys::isOneOf(null, ['']));
    }

    public function testTheScopeKeyColumnsCompareExactlyWithEveryUserMapping(): void
    {
        foreach ([UserMapping::RELATION, UserMapping::ID] as $mapping) {
            foreach ([[new MySQL80Platform(), 'utf8mb4_0900_bin'], [new MariaDBPlatform(), 'utf8mb4_nopad_bin']] as [$platform, $collation]) {
                foreach (TimeTrackMetadataListener::SCOPE_FIELDS as $class => $field) {
                    $options = $this->metadata($class, $mapping, $platform)->fieldMappings[$field]->options ?? [];
                    self::assertSame(['utf8mb4', $collation], [$options['charset'] ?? null, $options['collation'] ?? null], $class . ' ' . $mapping);
                }
            }
            self::assertArrayNotHasKey('collation', $this->metadata(Timesheet::class, $mapping, new PostgreSQLPlatform())->fieldMappings['scopeKey']->options ?? []);
        }
    }

    public function testThePadFallbackAndAnUnknownPlatformAreLogged(): void
    {
        // REQ-SEC-006 item 8: a server_version without the patch number ("8.0") selects the MySQL 5.7 platform.
        $logger  = $this->logger();
        $options = $this->metadata(Timesheet::class, UserMapping::RELATION, new MySQLPlatform(), $logger)->fieldMappings['scopeKey']->options ?? [];
        self::assertSame('utf8mb4_bin', $options['collation'] ?? null);
        self::assertStringContainsString('server_version has no patch number', $logger->messages[0] ?? '');

        // Relation mapping without a reachable platform: the default collation, a warning, and the re-checks still apply.
        $logger  = $this->logger();
        $options = $this->metadata(Timesheet::class, UserMapping::RELATION, null, $logger)->fieldMappings['scopeKey']->options ?? [];
        self::assertArrayNotHasKey('collation', $options);
        self::assertStringContainsString('could not read the database platform', $logger->messages[0] ?? '');

        // The id mapping keeps failing loudly.
        $this->expectException(LogicException::class);
        $this->metadata(Timesheet::class, UserMapping::ID, null);
    }

    /**
     * @return AbstractLogger&object{messages: list<string>}
     */
    private function logger(): AbstractLogger
    {
        return new class extends AbstractLogger {
            /** @var list<string> */
            public array $messages = [];

            public function log($level, string|Stringable $message, array $context = []): void
            {
                $this->messages[] = (string) $message;
            }
        };
    }

    /**
     * @param class-string $class
     *
     * @return ClassMetadata<object>
     */
    private function metadata(string $class, string $mapping, ?object $platform, ?AbstractLogger $logger = null): ClassMetadata
    {
        $metadata = new ClassMetadata($class);
        $metadata->initializeReflection(new RuntimeReflectionService());
        (new AttributeDriver([dirname(__DIR__, 3) . '/src/Entity']))->loadMetadataForClass($class, $metadata);
        $connection = $this->createStub(Connection::class);
        if ($platform === null) {
            $connection->method('getDatabasePlatform')->willThrowException(new RuntimeException('database down'));
        } else {
            $connection->method('getDatabasePlatform')->willReturn($platform);
        }
        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('getConnection')->willReturn($connection);
        $listener = $logger === null
            ? new TimeTrackMetadataListener('e', 't', 'c', SecurityUser::class, userMapping: $mapping)
            : new TimeTrackMetadataListener('e', 't', 'c', SecurityUser::class, userMapping: $mapping, logger: $logger);
        $listener->loadClassMetadata(new LoadClassMetadataEventArgs($metadata, $em));

        return $metadata;
    }
}
