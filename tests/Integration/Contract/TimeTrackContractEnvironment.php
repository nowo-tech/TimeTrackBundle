<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Tests\Integration\Contract;

use function dirname;

/**
 * Twig environment of the UiKit page contract test for the bundle: bundle views and catalogues, strict
 * missing-translation rule, docs/TEMPLATES.md.
 *
 * @phpstan-require-extends \Nowo\UiKitBundle\Test\PageContractTestCase
 */
trait TimeTrackContractEnvironment
{
    protected static function templatePaths(): array
    {
        return ['NowoTimeTrackBundle' => dirname(__DIR__, 3) . '/src/Resources/views'];
    }

    protected static function translationDirs(): array
    {
        return [dirname(__DIR__, 3) . '/src/Resources/translations'];
    }

    protected static function templatesDocument(): string
    {
        return dirname(__DIR__, 3) . '/docs/TEMPLATES.md';
    }

    protected static function strictMissingTranslations(): bool
    {
        return true;
    }
}
