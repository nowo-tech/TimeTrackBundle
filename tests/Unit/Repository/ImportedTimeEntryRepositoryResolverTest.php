<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Tests\Unit\Repository;

use LogicException;
use Nowo\TimeTrackBundle\Repository\ImportedTimeEntryRepositoryInterface;
use Nowo\TimeTrackBundle\Repository\ImportedTimeEntryRepositoryResolver;
use Nowo\TimeTrackBundle\Repository\ImportedTimeEntryStoreInterface;
use Nowo\TimeTrackBundle\Repository\TimeEntryRepositoryInterface;
use PHPUnit\Framework\TestCase;

/**
 * Since 1.12 (FJ-L3): the imported-entry store is the configured TimeEntryRepositoryInterface service.
 */
final class ImportedTimeEntryRepositoryResolverTest extends TestCase
{
    public function testReturnsTheConfiguredStoreWhenItHoldsImportedEntries(): void
    {
        $store = $this->createStub(ImportedTimeEntryRepositoryInterface::class);

        self::assertSame($store, ImportedTimeEntryRepositoryResolver::resolve($store));
    }

    public function testFailsClosedForAStoreWithoutImportedEntries(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('does not implement ImportedTimeEntryRepositoryInterface');

        ImportedTimeEntryRepositoryResolver::resolve($this->createStub(TimeEntryRepositoryInterface::class));
    }

    public function testResolvesTheFrameworkFreeStore(): void
    {
        // 1.23.0: a store implementing only ImportedTimeEntryStoreInterface, and the deprecated interface (it extends it).
        $store = $this->createStub(ImportedTimeEntryStoreInterface::class);
        self::assertSame($store, ImportedTimeEntryRepositoryResolver::resolveStore($store));
        $legacy = $this->createStub(ImportedTimeEntryRepositoryInterface::class);
        self::assertSame($legacy, ImportedTimeEntryRepositoryResolver::resolveStore($legacy));

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('does not implement ImportedTimeEntryStoreInterface');
        ImportedTimeEntryRepositoryResolver::resolveStore($this->createStub(TimeEntryRepositoryInterface::class));
    }
}
