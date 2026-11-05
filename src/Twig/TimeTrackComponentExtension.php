<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Twig;

use Override;
use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;
use Twig\TwigFunction;

/**
 * Since 1.13: `nowo_time_track_component(name, props)` (the components of contract §16a, rendered by
 * {@see TimeTrackComponentRuntime}) and the `nowo_time_track_duration` filter (`H:MM`, signed with `true`).
 */
final class TimeTrackComponentExtension extends AbstractExtension
{
    /**
     * The component function.
     */
    #[Override]
    public function getFunctions(): array
    {
        return [new TwigFunction('nowo_time_track_component', [TimeTrackComponentRuntime::class, 'render'], ['needs_environment' => true, 'is_safe' => ['html']])];
    }

    /**
     * The duration filter.
     */
    #[Override]
    public function getFilters(): array
    {
        return [new TwigFilter('nowo_time_track_duration', TimeTrackComponentRuntime::duration(...))];
    }
}
