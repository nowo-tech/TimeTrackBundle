<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Tests\Functional;

/**
 * Demo-only `security.allow_unauthenticated: true`: the timer page renders without a user (no tasks, no timer, POSTs
 * are ignored) and the reports page sends anonymous visitors back to the timer page.
 */
final class UnauthenticatedManageUiTest extends FunctionalTestCase
{
    protected static function timeTrackConfig(): array
    {
        return ['security' => ['allow_unauthenticated' => true]];
    }

    public function testTimerPageRendersWithoutAUser(): void
    {
        $crawler = $this->client->request('GET', '/tools/time-track');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('[data-test-layout]', 'No active timer');
        self::assertCount(0, $crawler->selectButton('Start'));

        $this->client->request('POST', '/tools/time-track', ['action' => 'stop']);
        self::assertResponseIsSuccessful();
    }

    public function testReportsRedirectAnonymousVisitorsToTheTimerPage(): void
    {
        $this->client->request('GET', '/tools/time-track/reports');

        self::assertResponseRedirects('/tools/time-track');
    }
}
