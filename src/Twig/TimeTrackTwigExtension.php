<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Twig;

use Twig\Extension\AbstractExtension;
use Twig\Extension\GlobalsInterface;

/**
 * Exposes TimeTrack Web UI globals to Twig templates (REQ-UI-001).
 *
 * Global {@see self::GLOBAL_LAYOUT} mirrors {@code nowo_time_track.templates.layout}.
 * Global {@see self::GLOBAL_CSS_FRAMEWORK} mirrors {@code nowo_time_track.templates.css_framework}.
 */
final class TimeTrackTwigExtension extends AbstractExtension implements GlobalsInterface
{
    public const GLOBAL_LAYOUT = 'nowo_time_track_layout';

    public const GLOBAL_CSS_FRAMEWORK = 'nowo_time_track_css_framework';

    public function __construct(
        private readonly string $layoutTemplate = '@NowoTimeTrackBundle/layout.html.twig',
        private readonly string $cssFramework = 'tabler',
    ) {
    }

    /**
     * @return array<string, string>
     */
    public function getGlobals(): array
    {
        return [
            self::GLOBAL_LAYOUT        => $this->layoutTemplate,
            self::GLOBAL_CSS_FRAMEWORK => $this->cssFramework,
        ];
    }
}
