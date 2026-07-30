<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Tests\Unit\Twig;

use Nowo\TimeTrackBundle\Twig\TimeTrackTwigExtension;
use PHPUnit\Framework\TestCase;

final class TimeTrackTwigExtensionTest extends TestCase
{
    public function testExposesLayoutAndCssFrameworkGlobals(): void
    {
        $extension = new TimeTrackTwigExtension('base.html.twig', 'bootstrap5');

        self::assertSame(
            [
                'nowo_time_track_layout'        => 'base.html.twig',
                'nowo_time_track_css_framework' => 'bootstrap5',
            ],
            $extension->getGlobals(),
        );
        self::assertSame('nowo_time_track_layout', TimeTrackTwigExtension::GLOBAL_LAYOUT);
        self::assertSame('nowo_time_track_css_framework', TimeTrackTwigExtension::GLOBAL_CSS_FRAMEWORK);
    }

    public function testDefaultGlobalsMatchBundleDemo(): void
    {
        $globals = (new TimeTrackTwigExtension())->getGlobals();

        self::assertSame(
            '@NowoTimeTrackBundle/layout.html.twig',
            $globals[TimeTrackTwigExtension::GLOBAL_LAYOUT],
        );
        self::assertSame('tabler', $globals[TimeTrackTwigExtension::GLOBAL_CSS_FRAMEWORK]);
    }
}
