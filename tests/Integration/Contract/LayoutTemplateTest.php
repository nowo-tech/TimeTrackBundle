<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Tests\Integration\Contract;

use LogicException;
use Nowo\TimeTrackBundle\Tests\Support\PageFixtures;
use Nowo\TimeTrackBundle\Ui\TimeTrackPages;
use Nowo\UiKitBundle\Test\PageContractTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Twig\Environment;

use function substr_count;

/**
 * 2.0 (deprecated since 1.8): the 1.x layout bridge and the 1.x layout shim are removed. A layout with the canonical
 * blocks is used as is; a 1.x layout without them, and the removed 1.x default layout, fail the page with a
 * `LogicException` naming the fix (never a page rendered without its content).
 */
#[CoversClass(TimeTrackPages::class)]
final class LayoutTemplateTest extends TestCase
{
    public const LAYOUTS = [
        'legacy/plain.html.twig' => '<header data-host="plain"><title>{% block title %}{% endblock %}</title>{% block stylesheets %}<link data-host-css>{% endblock %}</header>'
            . '<main data-host-main>{% block body %}{% endblock %}</main>{% block javascripts %}<script data-host-js></script>{% endblock %}',
        'legacy/bridge.html.twig' => '{% extends "legacy/plain.html.twig" %}{% block body %}<div data-host-bridge>{% block nowo_ui_content %}{% endblock %}</div>{% endblock %}',
        'canonical.html.twig'     => '<div data-host-canonical><title>{% block nowo_ui_document_title %}{% endblock %}</title>{% block nowo_ui_page_header %}{% endblock %}'
            . '{% block nowo_ui_flashes %}{% endblock %}{% block nowo_ui_content %}{% endblock %}{% block nowo_ui_modals %}{% endblock %}</div>',
    ];

    /** @return iterable<string, array{string}> */
    public static function layoutsWithoutThePageHeader(): iterable
    {
        yield 'a plain 1.x layout' => ['legacy/plain.html.twig'];
        yield 'the documented 1.x bridge (nowo_ui_content only)' => ['legacy/bridge.html.twig'];
    }

    #[Test]
    #[DataProvider('layoutsWithoutThePageHeader')]
    public function aLayoutWithoutTheCanonicalBlocksFailsLoudly(string $layout): void
    {
        $pages = new TimeTrackPages($layout, $this->twig());

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('The TimeTrack layout "' . $layout . '" does not render the UiKit canonical blocks');
        $this->expectExceptionMessage('UPGRADING "To 2.0.0"');
        $pages->layout();
    }

    #[Test]
    public function theRemoved1xDefaultLayoutFailsLoudly(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('the 1.x layout that 2.0 removed');
        (new TimeTrackPages(TimeTrackPages::LEGACY_DEFAULT_LAYOUT, $this->twig()))->layout();
    }

    #[Test]
    public function aCanonicalLayoutIsUsedAsIsAndNoLayoutMeansUiKit(): void
    {
        $twig  = $this->twig();
        $pages = new TimeTrackPages('canonical.html.twig', $twig);

        self::assertSame('canonical.html.twig', $pages->layout());
        self::assertSame([], $pages->layoutContext());
        $html = $twig->render('@NowoTimeTrackBundle/time_track/index.html.twig', PageFixtures::timer(false, false, $pages));
        self::assertStringContainsString('<div data-host-canonical><title>Time tracker</title>', $html);
        self::assertSame(1, substr_count($html, 'data-testid="time-track-timer"'));

        self::assertNull((new TimeTrackPages('', $twig))->layout());
        self::assertNull((new TimeTrackPages())->layout());
    }

    #[Test]
    public function unknownLayoutsAndMissingTwigKeepTheConfiguredValue(): void
    {
        self::assertSame('missing/layout.html.twig', (new TimeTrackPages('missing/layout.html.twig', $this->twig()))->layout());
        self::assertSame('legacy/plain.html.twig', (new TimeTrackPages('legacy/plain.html.twig'))->layout());
    }

    #[Test]
    public function theRemovedTemplatesAreGone(): void
    {
        $loader = $this->twig()->getLoader();

        self::assertFalse($loader->exists('@NowoTimeTrackBundle/layout.html.twig'));
        self::assertFalse($loader->exists('@NowoTimeTrackBundle/_legacy_layout.html.twig'));
    }

    private function twig(): Environment
    {
        $case = new class('environment') extends PageContractTestCase {
            use TimeTrackContractEnvironment;

            public static function pageFixtures(): iterable
            {
                return [];
            }

            public function environment(): Environment
            {
                return $this->createTwig();
            }

            protected static function inlineTemplates(): array
            {
                return LayoutTemplateTest::LAYOUTS;
            }
        };

        return $case->environment();
    }
}
