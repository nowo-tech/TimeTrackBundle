<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Tests\Integration\Contract;

use Nowo\TimeTrackBundle\Tests\Support\PageFixtures;
use Nowo\UiKitBundle\Test\PageContractTestCase;
use Nowo\UiKitBundle\Test\PageFixture;

/**
 * REQ-UI-003 page contract: the timer and reports pages extend the UiKit list scaffold; no <html>, CDN, inline
 * handler or confirm(); framework classes only through theme slots; the stub theme is reflected; no raw or missing
 * translation keys (strict rule); the CSRF forms of the Start / Stop actions keep their hidden fields.
 */
final class PageContractTest extends PageContractTestCase
{
    use TimeTrackContractEnvironment;

    public static function pageFixtures(): iterable
    {
        yield 'timer' => new PageFixture('@NowoTimeTrackBundle/time_track/index.html.twig', PageFixtures::timer());
        yield 'timer (running)' => new PageFixture('@NowoTimeTrackBundle/time_track/index.html.twig', PageFixtures::timer(true));
        yield 'timer (no tasks)' => new PageFixture('@NowoTimeTrackBundle/time_track/index.html.twig', PageFixtures::timer(false, true));
        yield 'reports' => new PageFixture('@NowoTimeTrackBundle/time_track/reports.html.twig', PageFixtures::reports());
        yield 'reports (empty)' => new PageFixture('@NowoTimeTrackBundle/time_track/reports.html.twig', PageFixtures::reports(true));
    }
}
