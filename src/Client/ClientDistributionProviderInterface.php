<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Client;

/**
 * Source of the client download links rendered by `@NowoTimeTrackBundle/client_distribution/_downloads.html.twig`
 * (Twig function `nowo_time_track_client_distribution()`). Default: {@see ConfigClientDistributionProvider}
 * (`client_distribution.*`); a host may back it with database settings.
 */
interface ClientDistributionProviderInterface
{
    /**
     * Current links.
     */
    public function getDistribution(): ClientDistribution;
}
