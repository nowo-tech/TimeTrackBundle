<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Tests\Functional;

use DateTimeImmutable;
use Nowo\TimeTrackBundle\Entity\Timesheet;
use Nowo\TimeTrackBundle\Enum\TimesheetPeriodKind;
use Nowo\TimeTrackBundle\Tests\Support\FunctionalKernel;
use Nowo\TimeTrackBundle\Timesheet\Period;
use Symfony\Component\HttpKernel\KernelInterface;

/**
 * Since 1.15 (HA-M3): a host's replacement entry store without the optional capability must not break `remove()`.
 */
final class FormerPeoplePlainStoreTest extends FunctionalTestCase
{
    protected static function createKernel(array $options = []): KernelInterface
    {
        return new FunctionalKernel(self::timeTrackConfig(), true);
    }

    public function testRemovingAnyEntityStillWorks(): void
    {
        $em        = $this->entityManager();
        $timesheet = new Timesheet($this->user, (string) $this->user->getId(), '', Period::fromKey(TimesheetPeriodKind::Week, '2026-W01'), 'UTC', new DateTimeImmutable('2026-01-10 08:00:00'));
        $em->persist($timesheet);
        $em->flush();

        $em->remove($timesheet);
        $em->flush();
        self::assertNull($em->find(Timesheet::class, $timesheet->getId()));

        $other = $this->createUser('other@example.com');
        $id    = $other->getId();
        $em->remove($other);
        $em->flush();
        self::assertNull($em->find($other::class, $id));
    }
}
