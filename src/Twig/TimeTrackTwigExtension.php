<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Twig;

use Nowo\TimeTrackBundle\Client\ClientDistribution;
use Nowo\TimeTrackBundle\Client\ClientDistributionProviderInterface;
use Nowo\TimeTrackBundle\TimeTrackBundle;
use Nowo\UiKitBundle\View\DetailItem;
use Nowo\UiKitBundle\View\Label;
use Twig\Extension\AbstractExtension;
use Twig\Extension\GlobalsInterface;
use Twig\TwigFunction;

/**
 * Exposes TimeTrack Web UI globals to Twig templates (REQ-UI-001) and the client download helpers of
 * `@NowoTimeTrackBundle/client_distribution/_downloads.html.twig`.
 *
 * Global {@see self::GLOBAL_LAYOUT} mirrors {@code nowo_time_track.templates.layout}.
 * Global {@see self::GLOBAL_CSS_FRAMEWORK} mirrors {@code nowo_time_track.templates.css_framework}.
 * Functions: `nowo_time_track_client_distribution()`, `nowo_time_track_client_download_items(distribution)`,
 * `nowo_time_track_label(key)`.
 */
final class TimeTrackTwigExtension extends AbstractExtension implements GlobalsInterface
{
    public const GLOBAL_LAYOUT = 'nowo_time_track_layout';

    public const GLOBAL_CSS_FRAMEWORK = 'nowo_time_track_css_framework';

    /**
     * Receives the configured layout and CSS framework and the download link source.
     */
    public function __construct(
        private readonly string $layoutTemplate = '@NowoTimeTrackBundle/layout.html.twig',
        private readonly string $cssFramework = 'tabler',
        private readonly ?ClientDistributionProviderInterface $distributionProvider = null,
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

    /**
     * @return list<TwigFunction>
     */
    public function getFunctions(): array
    {
        return [
            new TwigFunction('nowo_time_track_client_distribution', $this->clientDistribution(...)),
            new TwigFunction('nowo_time_track_client_download_items', $this->downloadItems(...)),
            new TwigFunction('nowo_time_track_label', static fn (string $key): Label => new Label($key, TimeTrackBundle::TRANSLATION_DOMAIN)),
        ];
    }

    /**
     * Current download links (empty without a provider).
     */
    public function clientDistribution(): ClientDistribution
    {
        return $this->distributionProvider?->getDistribution() ?? new ClientDistribution();
    }

    /**
     * Detail items of the links (`downloads.<key>` → plain anchor) and the latest desktop version.
     *
     * @return list<DetailItem>
     */
    public function downloadItems(ClientDistribution $distribution): array
    {
        $items = [];
        foreach ($distribution->links() as $key => $url) {
            $items[] = new DetailItem(new Label('downloads.' . $key, TimeTrackBundle::TRANSLATION_DOMAIN), new Label('downloads.get', TimeTrackBundle::TRANSLATION_DOMAIN), $url);
        }
        if ($distribution->latestVersion !== null && $distribution->latestVersion !== '') {
            $items[] = new DetailItem(new Label('downloads.latest_version', TimeTrackBundle::TRANSLATION_DOMAIN), $distribution->latestVersion);
        }

        return $items;
    }
}
