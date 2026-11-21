<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Tests\Unit\PersonalData;

use Doctrine\ORM\EntityManagerInterface;
use LogicException;
use Nowo\TimeTrackBundle\Exception\PersonalDataErasureRefusedException;
use Nowo\TimeTrackBundle\PersonalData\DoctrineTimeTrackPersonalData;
use Nowo\TimeTrackBundle\Query\DoctrineTimeTrackQuery;
use Nowo\TimeTrackBundle\Retention\FormerPersonTimeEntries;
use Nowo\TimeTrackBundle\Tests\Support\InMemoryTimeEntryRepository;
use Nowo\TimeTrackBundle\Transaction\TimeTrackTransactionRunner;
use PHPUnit\Framework\TestCase;

/**
 * 1.35.0: the personal-data port refuses what it cannot do safely before anything is read or written.
 */
final class DoctrineTimeTrackPersonalDataTest extends TestCase
{
    public function testAnEntryStoreWithoutTheFormerPersonCapabilityCannotErase(): void
    {
        $this->expectException(LogicException::class);
        $this->service()->erase('7');
    }

    public function testALookAlikeIdIsRefused(): void
    {
        try {
            $this->service()->export("7\u{00A0}");
            self::fail('expected a refusal');
        } catch (PersonalDataErasureRefusedException $refused) {
            self::assertSame(PersonalDataErasureRefusedException::INVALID_USER_ID, $refused->errorCode);
        }
        $this->expectException(PersonalDataErasureRefusedException::class);
        $this->service()->erase('');
    }

    private function service(): DoctrineTimeTrackPersonalData
    {
        $manager = $this->createStub(EntityManagerInterface::class);
        $query   = new DoctrineTimeTrackQuery($manager);
        $store   = new InMemoryTimeEntryRepository();

        return new DoctrineTimeTrackPersonalData($manager, new TimeTrackTransactionRunner($manager), $query, $query, $query, $store, new FormerPersonTimeEntries($store));
    }
}
