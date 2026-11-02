<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Tests\Functional;

use function substr_count;

/**
 * BC of 1.x hosts (REQ-UI-003 migration): `templates.layout` (deprecated) pointing at a 1.x host layout without the
 * UiKit canonical blocks still renders the pages once inside that layout (through the deprecated bridge), with the
 * flashes and the CSRF start / stop forms working.
 */
final class LegacyLayoutManageUiTest extends FunctionalTestCase
{
    protected static function timeTrackConfig(): array
    {
        return ['templates' => ['layout' => '@TimeTrackTest/layout.html.twig']];
    }

    public function testThe1xHostLayoutStillWrapsTheTimerPage(): void
    {
        $this->client->loginUser($this->user, 'main');

        $crawler = $this->client->request('GET', '/tools/time-track');
        self::assertResponseIsSuccessful();
        $html = (string) $this->client->getResponse()->getContent();
        self::assertSame(1, substr_count($html, 'data-test-layout'));
        self::assertSame(1, substr_count($html, 'data-testid="time-track-timer"'));
        self::assertSelectorTextContains('[data-test-layout] [data-testid="time-track-timer"]', 'Implement login API');

        $this->client->submit($crawler->selectButton('Start')->eq(0)->form());
        self::assertResponseRedirects('/tools/time-track');
        $this->client->followRedirect();
        self::assertSelectorTextContains('[data-test-layout]', 'Timer started.');
        self::assertSelectorExists('[data-test-layout] [data-testid="time-track-stop"]');
    }
}
