<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Tests\Unit\Twig;

use Nowo\TimeTrackBundle\Twig\TimeTrackTwigExtension;
use PHPUnit\Framework\TestCase;
use Twig\Extension\GlobalsInterface;
use Twig\TwigFunction;

use function array_map;

final class TimeTrackTwigExtensionTest extends TestCase
{
    /**
     * 2.0: the 1.x globals `nowo_time_track_layout` / `nowo_time_track_css_framework` (fed by the removed `templates.*`
     * keys) are gone; the constructor still accepts (and ignores) their values.
     */
    public function testExposesNoGlobalsSince20(): void
    {
        $extension = new TimeTrackTwigExtension('base.html.twig', 'bootstrap5');

        self::assertNotInstanceOf(GlobalsInterface::class, $extension);
        self::assertSame(
            ['nowo_time_track_client_distribution', 'nowo_time_track_client_download_items', 'nowo_time_track_label'],
            array_map(static fn (TwigFunction $function): string => $function->getName(), $extension->getFunctions()),
        );
    }
}
