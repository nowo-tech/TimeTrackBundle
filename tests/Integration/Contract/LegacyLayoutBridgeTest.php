<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Tests\Integration\Contract;

use ErrorException;
use Nowo\TimeTrackBundle\Tests\Support\PageFixtures;
use Nowo\TimeTrackBundle\Ui\TimeTrackPages;
use Nowo\UiKitBundle\Test\PageContractTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\ErrorHandler\ErrorHandler;
use Twig\Environment;

use function substr_count;

/**
 * 1.x layouts keep working (BC of templates.layout / web_ui.layout_template): a plain 1.x layout (title, stylesheets,
 * body, javascripts), the documented 1.x bridge (nowo_ui_content inside body) and a copy of the 1.x bundle layout
 * (flashes outside body) render the page once through the deprecated bridge; a layout with the canonical blocks is
 * used as is; the 1.x default layout maps to the UiKit admin layout; 1.x full-file overrides that extend the
 * `nowo_time_track_layout` global still render inside the UiKit admin layout (layout.html.twig shim).
 */
#[CoversClass(TimeTrackPages::class)]
final class LegacyLayoutBridgeTest extends TestCase
{
    public const LAYOUTS = [
        'legacy/plain.html.twig' => '<header data-host="plain"><title>{% block title %}{% endblock %}</title>{% block stylesheets %}<link data-host-css>{% endblock %}</header>'
            . '<main data-host-main>{% block body %}{% endblock %}</main>{% block javascripts %}<script data-host-js></script>{% endblock %}',
        'legacy/bridge.html.twig'      => '{% extends "legacy/plain.html.twig" %}{% block body %}<div data-host-bridge>{% block nowo_ui_content %}{% endblock %}</div>{% endblock %}',
        'legacy/bundle-copy.html.twig' => '<header data-host="copy"><title>{% block title %}{% endblock %}</title>{% block stylesheets %}{% block nowo_ui_styles %}{% endblock %}{% endblock %}</header>'
            . '<div data-host-flashes>{% block nowo_ui_flashes %}{% endblock %}</div>'
            . '<div data-host-content>{% block body %}{% block nowo_ui_content %}{% endblock %}{% endblock %}</div>{% block javascripts %}{% block nowo_ui_scripts %}{% endblock %}{% endblock %}',
        'canonical.html.twig' => '<div data-host-canonical><title>{% block nowo_ui_document_title %}{% endblock %}</title>{% block nowo_ui_page_header %}{% endblock %}'
            . '{% block nowo_ui_flashes %}{% endblock %}{% block nowo_ui_content %}{% endblock %}{% block nowo_ui_modals %}{% endblock %}</div>',
        'host/legacy_override.html.twig' => '{% extends nowo_time_track_layout %}{% block title %}Old timer{% endblock %}'
            . '{% block stylesheets %}{{ parent() }}<link data-override-css>{% endblock %}{% block body %}<h1 data-override-body>{{ tasks|length }} tasks</h1>{% endblock %}',
    ];

    #[Test]
    public function aPlainLegacyLayoutRendersThePageInItsBodyBlock(): void
    {
        $twig  = $this->twig();
        $pages = new TimeTrackPages('legacy/plain.html.twig', $twig);

        self::assertMatchesRegularExpression('/legacy\/plain\.html\.twig.*removed in 2\.0/', $this->deprecationOf(static fn (): ?string => $pages->layout()));
        self::assertSame(TimeTrackPages::LEGACY_LAYOUT_BRIDGE, $pages->layout());
        self::assertSame(['nowo_time_track_legacy_layout' => 'legacy/plain.html.twig', 'nowo_time_track_legacy_blocks' => []], $pages->layoutContext());

        $html = $twig->render('@NowoTimeTrackBundle/time_track/index.html.twig', PageFixtures::timer(false, false, $pages));

        self::assertSame(1, substr_count($html, 'data-host="plain"'));
        self::assertMatchesRegularExpression('/<main data-host-main>.*data-testid="time-track-timer"/s', $html);
        self::assertSame(1, substr_count($html, 'data-testid="time-track-timer"'));
        self::assertStringContainsString('<title>Time tracker</title>', $html);
        self::assertStringContainsString('data-host-css', $html);
        self::assertStringContainsString('nowo-ui.css', $html);
        self::assertStringContainsString('data-host-js', $html);
        self::assertSame(1, substr_count($html, 'data-nowo-ui-contract-partial="page_header"'));
    }

    #[Test]
    public function theDocumented1xBridgeGetsThePageOnceInsideBody(): void
    {
        $twig  = $this->twig();
        $pages = new TimeTrackPages('legacy/bridge.html.twig', $twig);

        self::assertMatchesRegularExpression('/legacy\/bridge\.html\.twig/', $this->deprecationOf(static fn (): ?string => $pages->layout()));
        self::assertSame(['nowo_ui_content'], $pages->layoutContext()['nowo_time_track_legacy_blocks'] ?? null);

        $html = $twig->render('@NowoTimeTrackBundle/time_track/reports.html.twig', PageFixtures::reports(false, $pages));

        self::assertSame(1, substr_count($html, 'data-testid="time-track-reports"'));
        self::assertMatchesRegularExpression('/<main data-host-main>.*data-nowo-ui-contract-partial="page_header".*data-testid="time-track-reports"/s', $html);
    }

    #[Test]
    public function aCopyOfThe1xBundleLayoutDoesNotPrintBlocksTwice(): void
    {
        $twig  = $this->twig();
        $pages = new TimeTrackPages('legacy/bundle-copy.html.twig', $twig);

        self::assertMatchesRegularExpression('/bundle-copy/', $this->deprecationOf(static fn (): ?string => $pages->layout()));
        self::assertSame(['nowo_ui_styles', 'nowo_ui_flashes', 'nowo_ui_content', 'nowo_ui_scripts'], $pages->layoutContext()['nowo_time_track_legacy_blocks'] ?? null);

        $html = $twig->render('@NowoTimeTrackBundle/time_track/index.html.twig', PageFixtures::timer(true, false, $pages));

        self::assertSame(1, substr_count($html, 'data-testid="time-track-timer"'));
        self::assertSame(1, substr_count($html, 'data-nowo-ui-contract-partial="page_header"'));
        self::assertMatchesRegularExpression('/<div data-host-flashes>.*data-nowo-ui-contract-partial="page_header"/s', $html, 'page header before the flashes');
        self::assertSame(1, substr_count($html, 'nowo-ui.css'));
    }

    #[Test]
    public function aCanonicalLayoutIsUsedAsIsAndThe1xDefaultMapsToUiKit(): void
    {
        $twig  = $this->twig();
        $pages = new TimeTrackPages('canonical.html.twig', $twig);

        self::assertSame('canonical.html.twig', $pages->layout());
        self::assertSame([], $pages->layoutContext());
        $html = $twig->render('@NowoTimeTrackBundle/time_track/index.html.twig', PageFixtures::timer(false, false, $pages));
        self::assertStringContainsString('<div data-host-canonical><title>Time tracker</title>', $html);

        self::assertNull((new TimeTrackPages(TimeTrackPages::LEGACY_DEFAULT_LAYOUT, $twig))->layout());
        self::assertNull((new TimeTrackPages('', $twig))->layout());
        self::assertNull((new TimeTrackPages())->layout());
    }

    #[Test]
    public function unknownLayoutsAndMissingTwigFallBackToTheConfiguredValue(): void
    {
        self::assertSame('missing/layout.html.twig', (new TimeTrackPages('missing/layout.html.twig', $this->twig()))->layout());
        self::assertSame('legacy/plain.html.twig', (new TimeTrackPages('legacy/plain.html.twig'))->layout());
    }

    #[Test]
    public function a1xFullFileOverrideStillRendersThroughTheLayoutShim(): void
    {
        $twig = $this->twig();
        $twig->addGlobal('nowo_time_track_layout', TimeTrackPages::LEGACY_DEFAULT_LAYOUT);

        $html = $twig->render('host/legacy_override.html.twig', PageFixtures::timer());

        self::assertStringContainsString('data-nowo-ui-contract-layout="admin"', $html);
        self::assertStringContainsString('<div data-nowo-ui-contract-block="nowo_ui_document_title">Old timer</div>', $html);
        self::assertMatchesRegularExpression('/data-nowo-ui-contract-block="nowo_ui_styles">.*data-nowo-ui-theme-tokens.*<link data-override-css>/s', $html, 'override CSS stacks after the UiKit styles');
        self::assertMatchesRegularExpression('/data-nowo-ui-contract-block="nowo_ui_content">\s*<h1 data-override-body>3 tasks<\/h1>/', $html);
    }

    /**
     * Runs the callable and returns the message of the first PHP error / deprecation it triggered ('' when none).
     */
    private function deprecationOf(callable $callable): string
    {
        try {
            ErrorHandler::call($callable);
        } catch (ErrorException $e) {
            return $e->getMessage();
        }

        return '';
    }

    private function twig(): Environment
    {
        $case = new class('legacy') extends PageContractTestCase {
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
                return LegacyLayoutBridgeTest::LAYOUTS;
            }
        };

        return $case->environment();
    }
}
