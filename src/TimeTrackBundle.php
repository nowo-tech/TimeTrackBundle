<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle;

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

    public function build(ContainerBuilder $container): void
    {
        $container->addCompilerPass(new TwigPathsPass());
    }

    public function getContainerExtension(): ExtensionInterface
    {
        if (!$this->extension instanceof ExtensionInterface) {
            $this->extension = new TimeTrackExtension();
        }

        return $this->extension;
    }
}
