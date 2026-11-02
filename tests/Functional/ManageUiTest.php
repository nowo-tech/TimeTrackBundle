<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Tests\Functional;

use DateTimeImmutable;
use Nowo\TimeTrackBundle\Entity\TimeEntry;
use Nowo\TimeTrackBundle\Enum\TimeEntrySource;

use function sprintf;

/**
 * Manage UI end to end (REQ-TEST-003): timer page with the CSRF-protected start / stop forms, flashes, the access
 * contract (REQ-UI-002) and the paginated reports list (REQ-PERF-001). Route names / paths are the 1.x contract.
 */
final class ManageUiTest extends FunctionalTestCase
{
    protected static function timeTrackConfig(): array
    {
        return ['reports' => ['page_size' => 2]];
    }

    public function testStartAndStopTheTimerFromTheTimerPage(): void
    {
        $this->client->loginUser($this->user, 'main');

        $crawler = $this->client->request('GET', '/tools/time-track');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('[data-testid="time-track-timer"]', 'Implement login API');
        $startForms = $crawler->selectButton('Start');
        self::assertCount(3, $startForms);
        $firstStart  = $startForms->eq(0)->form();
        $secondStart = $startForms->eq(1)->form();

        $this->client->submit($firstStart);
        self::assertResponseRedirects('/tools/time-track');
        $crawler = $this->client->followRedirect();
        self::assertSelectorTextContains('.nowo-ui-flash--success', 'Timer started.');
        self::assertSelectorTextContains('main', 'Running: Implement login API');
        self::assertSelectorExists('[data-testid="time-track-stop"]');
        self::assertCount(0, $crawler->selectButton('Start'));

        // A start form loaded before the timer started: one active timer per user.
        $this->client->submit($secondStart);
        $this->client->followRedirect();
        self::assertSelectorTextContains('.nowo-ui-flash--warning', 'You already have an active timer.');

        $stop = $this->client->getCrawler()->selectButton('Stop')->form();
        $this->client->submit($stop);
        $this->client->followRedirect();
        self::assertSelectorTextContains('.nowo-ui-flash--success', 'Timer stopped.');

        // The same stop form again: nothing is running any more.
        $this->client->submit($stop);
        $this->client->followRedirect();
        self::assertSelectorTextContains('.nowo-ui-flash--warning', 'No active timer.');

        $this->client->request('GET', '/tools/time-track/reports');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('[data-testid="time-track-reports"] table', 'Implement login API');
    }

    public function testStartingAnUnknownTaskShowsTheError(): void
    {
        $this->client->loginUser($this->user, 'main');
        $form = $this->client->request('GET', '/tools/time-track')->selectButton('Start')->first()->form();
        $form->setValues(['task_id' => 'missing-task']);

        $this->client->submit($form);
        $this->client->followRedirect();

        self::assertSelectorExists('.nowo-ui-flash--error');
    }

    public function testAPostWithoutAValidCsrfTokenIsDenied(): void
    {
        $this->client->loginUser($this->user, 'main');

        $this->client->request('POST', '/tools/time-track', ['action' => 'stop', '_token' => 'forged']);

        self::assertResponseStatusCodeSame(403);
    }

    public function testUsersWithoutTheAccessRoleAreDenied(): void
    {
        $this->client->loginUser($this->createUser('guest@example.com', []), 'main');

        $this->client->request('GET', '/tools/time-track/reports');

        self::assertResponseStatusCodeSame(403);
    }

    public function testAnonymousVisitorsAreDenied(): void
    {
        $this->client->request('GET', '/tools/time-track');

        self::assertContains($this->client->getResponse()->getStatusCode(), [401, 403]);
    }

    public function testReportsArePaginatedInTheDatabase(): void
    {
        $em = $this->entityManager();
        for ($i = 1; $i <= 3; ++$i) {
            $start = new DateTimeImmutable(sprintf('-%d hours', $i));
            $em->persist(new TimeEntry($this->user, 'seed-' . $i, 'Seed task ' . $i, null, $start, $start->modify('+30 minutes'), 1800, TimeEntrySource::Web));
        }
        $em->flush();
        $this->client->loginUser($this->user, 'main');

        $crawler = $this->client->request('GET', '/tools/time-track/reports');
        self::assertResponseIsSuccessful();
        self::assertCount(2, $crawler->filter('[data-testid="time-track-reports"] tbody tr'));
        self::assertSelectorExists('[data-nowo-ui-region="pagination"]');
        self::assertGreaterThan(0, $crawler->filter('[data-nowo-ui-region="pagination"] a[href*="page=2"]')->count());

        $crawler = $this->client->request('GET', '/tools/time-track/reports?page=9&from=' . (new DateTimeImmutable('-2 days'))->format('Y-m-d'));
        self::assertCount(1, $crawler->filter('[data-testid="time-track-reports"] tbody tr'));
        self::assertSelectorTextContains('[data-testid="time-track-reports"] tbody', 'Seed task 3');
        self::assertStringContainsString('from=', (string) $crawler->filter('[data-nowo-ui-region="pagination"] a[href*="page=1"]')->first()->attr('href'));
    }
}
