<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Twig;

use Nowo\TimeTrackBundle\Client\ClientDistribution;
use Nowo\TimeTrackBundle\Client\ClientDistributionProviderInterface;
use Nowo\TimeTrackBundle\TimeTrackBundle;
use Nowo\UiKitBundle\View\DetailItem;
use Nowo\UiKitBundle\View\Label;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * The client download helpers of `@NowoTimeTrackBundle/client_distribution/_downloads.html.twig`. Since 2.0 the 1.x
 * Twig globals `nowo_time_track_layout` and `nowo_time_track_css_framework` (fed by the removed `templates.*` keys) are
 * gone. Functions: `nowo_time_track_client_distribution()`, `nowo_time_track_client_download_items(distribution)`,
 * `nowo_time_track_label(key)`.
 */
final class TimeTrackTwigExtension extends AbstractExtension
{
    /**
     * Receives the download link source.
     *
     * @param string $layoutTemplate @deprecated since 2.0, ignored (the `nowo_time_track_layout` global is removed); removed in 3.0
     * @param string $cssFramework @deprecated since 2.0, ignored (the `nowo_time_track_css_framework` global is removed); removed in 3.0
     */
    // @phpstan-ignore constructor.unusedParameter
    public function __construct(
        string $layoutTemplate = '',
        string $cssFramework = '',
        private readonly ?ClientDistributionProviderInterface $distributionProvider = null,
    ) {
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
