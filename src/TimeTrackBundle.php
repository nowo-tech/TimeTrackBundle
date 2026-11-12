<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle;

use Nowo\TimeTrackBundle\DependencyInjection\Compiler\TimeTrackPortsPass;
use Nowo\TimeTrackBundle\DependencyInjection\Compiler\TwigPathsPass;
use Nowo\TimeTrackBundle\DependencyInjection\TimeTrackExtension;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\ExtensionInterface;
use Symfony\Component\HttpKernel\Bundle\Bundle;

/**
 * Personal and team time tracking for Symfony applications.
 */
final class TimeTrackBundle extends Bundle
{
    public const TRANSLATION_DOMAIN = 'NowoTimeTrackBundle';

    /**
     * Registers the Twig paths and (since 1.23.0) the host ports compiler passes.
     */
    public function build(ContainerBuilder $container): void
    {
        $container->addCompilerPass(new TwigPathsPass());
        $container->addCompilerPass(new TimeTrackPortsPass());
    }

    /**
     * Bundle extension (`nowo_time_track`).
     */
    public function getContainerExtension(): ExtensionInterface
    {
        if (!$this->extension instanceof ExtensionInterface) {
            // @igor-ignore - Boot-time Symfony Bundle extension cache (not request state).
            $this->extension = new TimeTrackExtension();
        }

        return $this->extension;
    }
}
