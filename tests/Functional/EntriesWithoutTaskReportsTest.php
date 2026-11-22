<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Tests\Functional;

use DateTimeImmutable;
use Nowo\TimeTrackBundle\Entity\TimeEntry;
use Nowo\TimeTrackBundle\Enum\TimeEntrySource;

/**
 * Since 1.36.0 (Beacon spec 250): the reports page shows an entry without a task as "No task" with its free text.
 */
final class EntriesWithoutTaskReportsTest extends FunctionalTestCase
{
    protected static function timeTrackConfig(): array
    {
        return ['entries' => ['allow_without_task' => true]];
    }

    public function testTheReportsPageShowsNoTask(): void
    {
        $entry = (new TimeEntry($this->user, null, '', null, new DateTimeImmutable('-3 hours'), new DateTimeImmutable('-2 hours'), 3600, TimeEntrySource::Manual))->describe('Customer ACME');
        $this->entityManager()->persist($entry);
        $this->entityManager()->flush();
        $this->client->loginUser($this->user, 'main');

        $this->client->request('GET', '/tools/time-track/reports');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('[data-testid="time-track-no-task"]', 'No task');
        self::assertSelectorTextContains('[data-testid="time-track-reports"] table', 'Customer ACME');
    }
}
