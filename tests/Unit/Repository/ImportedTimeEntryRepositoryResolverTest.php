<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Tests\Unit\Repository;

use LogicException;
use Nowo\TimeTrackBundle\Repository\ImportedTimeEntryRepositoryInterface;
use Nowo\TimeTrackBundle\Repository\ImportedTimeEntryRepositoryResolver;
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
}
